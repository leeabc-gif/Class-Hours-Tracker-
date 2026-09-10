# 升级失败修复报告：There is no active transaction

**日期**：2026-09-10
**提交**：`46eca71`
**影响文件**：`application/common/service/UpdateService.php`
**严重级别**：致命（升级功能完全不可用 + 数据库可能进入半升级状态）

---

## 一、现象

后台「系统更新」点击升级后报错：

```
更新失败：There is no active transaction（已自动回滚到升级前文件）
```

更新源：自定义（manifest_url 覆写）
清单：`https://cnb.cool/bmayan/class-hours-tracker/-/releases/download/v1.0.8/manifest.json`

---

## 二、根因

### 2.1 机制

MySQL 的 **DDL 语句会触发隐式提交（implicit commit）**。一旦执行
`CREATE TABLE` / `ALTER TABLE` / `DROP` / `TRUNCATE` 等，当前事务**立即结束**，
`PDO::inTransaction()` 由 `true` 变为 `false`。

而原 `runSqlFromZip()` 的写法是把整份 `upgrade.sql` 用事务包起来：

```php
$pdo->beginTransaction();
try {
    foreach ($statements as $stmt) { $pdo->exec($stmt); }
    $pdo->commit();              // ← 事务早没了，这里抛异常
} catch (\Throwable $e) {
    $pdo->rollBack();            // ← 再抛同一个异常，把真错彻底盖掉
    throw new \RuntimeException('执行 upgrade.sql 失败，已回滚数据库：' . $e->getMessage());
}
```

### 2.2 触发点

**v1.1.0 的 `upgrade.sql` 含 7 个 `CREATE TABLE IF NOT EXISTS`**：

`ks_ai_channel`、`ks_ai_model`、`ks_ai_token`、`ks_ai_quota`、
`ks_ai_usage_log`、`ks_notice`、`ks_notice_read`

**第 1 条 CREATE 执行完事务就消失了**。实测证据：

```
begin: inTransaction=1
stmt #1 OK; inTx=0   ← CREATE TABLE 执行后事务当场消失
stmt #2 OK; inTx=0
...
```

随后 `commit()` 抛 `There is no active transaction`，进入 catch；
catch 里的 `rollBack()` **再次抛出同一句**，于是这个次要异常替换了真正的失败原因，
最终前端只看到那句 PDO 裸文案。

### 2.3 更危险的后果

用旧逻辑完整复现（真实 MySQL 8.0.36）：

| 项目 | 升级前 | 升级后 |
|---|---|---|
| 表数量 | 12 | **18** |
| app_version | 1.0.0 | **1.0.0（未变）** |

也就是说这是个 **"假失败"**：
- 报错说失败并回滚了文件
- 但 **DDL 已经真实生效**，新表已建
- 版本号却没写进去

结果是**数据库结构已升级、代码被回滚、版本号停留在旧值** —— 三者互相错配，
属于不一致的半升级状态。

---

## 三、修复方案

### 3.1 新增 DDL 识别器

```php
protected static function isImplicitCommitStatement($stmt)
{
    $s = ltrim((string) $stmt);
    if ($s === '') return false;
    // 剥离开头的行注释/块注释，避免注释或字符串里的关键字误判
    $s = preg_replace('#^(?:\s*(?:--[^\n]*\n|/\*.*?\*/|\#[^\n]*\n))+#s', '', $s);
    $s = ltrim((string) $s);
    return (bool) preg_match(
        '/^(CREATE|ALTER|DROP|TRUNCATE|RENAME|GRANT|REVOKE|FLUSH|LOCK|UNLOCK|ANALYZE|OPTIMIZE|REPAIR)\b/i',
        $s
    );
}
```

### 3.2 含 DDL 就不开事务

包裹 DDL 的事务是**虚假的保护**（DDL 本身无法回滚），不如不开：

```php
$hasDdl = false;
foreach ($statements as $s) {
    if (self::isImplicitCommitStatement($s)) { $hasDdl = true; break; }
}
$useTx = !$hasDdl;
if ($useTx) { $pdo->beginTransaction(); }
```

### 3.3 提交/回滚前复查事务状态

```php
if ($pdo->inTransaction()) { $pdo->commit(); }     // 提交前复查
// ...
try {
    if ($pdo->inTransaction()) { $pdo->rollBack(); $rolled = true; }
} catch (\Throwable $re) { /* 记日志，不再盖掉原始异常 */ }
```

### 3.4 报错文案区分场景 + 保留真实原因

- 纯 DML 失败 → `执行 upgrade.sql 失败，已回滚数据库：第 N 条语句（SQLSTATE...）`
- 含 DDL 失败 → `执行 upgrade.sql 失败：第 N 条语句（SQLSTATE...）（该 SQL 含建表/改表语句，MySQL 已隐式提交，数据库无法自动回滚；如需还原请用升级前的数据库备份）`

### 3.5 回滚路径同步受益

`rollback()` 执行 `downgrade.sql` 时复用同一方法，
因此回滚时不会再二次爆同样的错。

---

## 四、验证（真实 MySQL 8.0.36 隔离库）

| 场景 | 结果 |
|---|---|
| v1.1.0 升级 1.0.0 → 1.1.0 | **成功**，7 张表全建，表数 12 → 19 |
| 幂等重跑 v1.1.0 | **通过**，不重复建表、版本不回退 |
| 纯 DML 包（v1.0.8）走事务路径 | **通过**，事务保护仍生效 |
| DDL 检测器单元用例 | **10/10 通过**（含注释剥离、字符串误判防护） |
| 失败注入：含 DDL | 正确提示需用备份恢复，真错完整暴露 |
| 失败注入：纯 DML | 正确提示已回滚数据库 |

测试后已将隔离库还原至 12 表 / `app_version=1.0.0` 基线，
并删除临时文件（`_env/t_runsql.php`、临时 `config/installed.php`）。

---

## 五、重要遗留：在线更新的"鸡生蛋"问题

已核对：**`keshi-1.0.8.zip` 和 `keshi-1.1.0.zip` 包内的 UpdateService 都是旧逻辑**
（不含 `isImplicitCommitStatement`）。

因为**执行更新动作的是服务器上的旧代码**，所以即使 v1.1.0 包已发布，
点"在线更新"依然会走进老 bug → 形成死锁。

### 破解方式（二选一）

**方案 A：先手工替换单文件（推荐，改动最小）**
1. 把修复后的 `application/common/service/UpdateService.php` 上传覆盖到服务器
2. 再走后台在线更新升到 v1.1.0，即可正常完成

**方案 B：全量覆盖部署**
1. 用 v1.1.0 全量包覆盖代码
2. 手工执行包内 `upgrade.sql`（建 7 张新表 + 写版本号）

### 后续建议
发一个 **v1.1.1**，把本次修复带进发布包，
这样以后所有安装都不会再遇到此问题。

---

## 六、经验固化

已写入项目长期记忆（`.workbuddy/memory/MEMORY.md`）：

> **含 DDL 就别开事务**；`commit()`/`rollBack()` 前必须复查 `inTransaction()`；
> **catch 块里的清理代码绝不能自己抛异常盖掉原始异常** —— 这是本次 bug 难以定位的直接原因。
