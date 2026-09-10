# keshi 课时统计系统 v1.1.1 发布报告

> 发布时间：2026-09-10　|　仓库：`bmayan/class-hours-tracker`（CNB）
> 定位：**重要修复版**，解决在线更新彻底不可用的致命问题

---

## 一、本次解决的问题

### 1. 致命：在线更新报 `There is no active transaction`

用户在后台点击"在线更新"时报错：

```
更新失败：There is no active transaction（已自动回滚到升级前文件）
```

**根因**是 MySQL 的 DDL 隐式提交机制：

`CREATE / ALTER / DROP / TRUNCATE / RENAME / GRANT / REVOKE / FLUSH / LOCK` 这类语句
会让当前事务**立即结束**，`PDO::inTransaction()` 随即由 `true` 变成 `false`。

v1.1.0 的 `upgrade.sql` 含 7 个 `CREATE TABLE`，于是：

| 步骤 | 实际发生 |
|---|---|
| `beginTransaction()` | `inTx = 1` |
| 执行第 1 条 `CREATE TABLE` | 语句成功，但 **`inTx` 变成 0**（隐式提交） |
| 后续语句 | 全部在自动提交模式下执行并**真实生效** |
| `commit()` | 抛 `There is no active transaction` |
| `catch` 里 `rollBack()` | **再抛同一句**，把真正的错误原因彻底掩盖 |

后果比报错本身更危险 —— 实测旧逻辑下形成**三方不一致**：

- 数据库：DDL 已生效（表数 12 → 18），处于**半升级**状态
- 代码：被自动回滚到升级前
- 版本号：`app_version` 仍停留在 `1.0.0`

### 2. 版本号比较的语义化缺陷（主动发现并修复）

历史迁移脚本用 `CAST(cfg_value AS DECIMAL(10,3))` 比较版本，而 MySQL 的 CAST **只取到第一个小数点**：

| 版本字符串 | CAST 结果 | 问题 |
|---|---|---|
| `1.0.8` | `1.000` | 丢失第三段 |
| `1.1.1` | `1.100` | — |
| `1.1.2` | `1.100` | 与 1.1.1 **无法区分** |
| `1.10.0` | `1.100` | 与 1.1.1 **无法区分** |

导致 `1.10.0 < 1.101` 成立 → **高版本站点会被降级改写**。

---

## 二、修复方案

### `application/common/service/UpdateService.php`

1. 新增 `isImplicitCommitStatement()`：剥离前置行注释/块注释后，正则识别隐式提交语句
2. **含 DDL 就不开事务**（开了也只是假保护），纯 DML 才用事务保证原子性
3. `commit()` / `rollBack()` 前**一律复查 `inTransaction()`**，杜绝二次异常掩盖真错
4. 报错文案区分「已回滚数据库」与「DDL 已隐式提交、需用备份恢复」，并保留第 N 条语句 + SQLSTATE

`rollback()` 中执行 `downgrade.sql` 复用同一方法，同步获得修复。

### `database/migrations/20260910_v111_version_sync.sql`

版本号同步改为**三段各补零到 4 位后字符串比较**：

```sql
CONCAT(
  LPAD(SUBSTRING_INDEX(CONCAT(`cfg_value`, '.0.0'), '.', 1), 4, '0'), '.',
  LPAD(SUBSTRING_INDEX(SUBSTRING_INDEX(CONCAT(`cfg_value`, '.0.0'), '.', 2), '.', -1), 4, '0'), '.',
  LPAD(SUBSTRING_INDEX(SUBSTRING_INDEX(CONCAT(`cfg_value`, '.0.0'), '.', 3), '.', -1), 4, '0')
) < '0001.0001.0001'
```

保留 7 个 `CREATE TABLE IF NOT EXISTS` 以兼容 **1.0.x 老站直升 1.1.1**（那时这些表还不存在）；
这些 DDL 恰好由本次修复正确处理。从 1.1.0 升级时它们不做任何事，仅写版本号。

### `tools/build_release.php`

首版打包误把 `_hotfix_upload/` 打进发布包（固定排除清单漏网）。
新增通用兜底规则：**顶层任何以 `_` 开头的目录/文件一律不进包**。

---

## 三、验证结果

| 验证项 | 结果 |
|---|---|
| DDL 检测器单元用例 | 10/10 通过 |
| 版本比较用例（含 `1.10.0`/`1.2.0`/空串/`0`） | 11/11 通过 |
| 升级路径：1.0.0 直升 1.1.1 | 通过（12 → 19 表） |
| 升级路径：幂等重跑 | 通过 |
| 升级路径：1.1.0 → 1.1.1 | 通过（仅写版本号） |
| 升级路径：1.2.0 降级保护 | 通过（不被改写） |
| 升级路径：1.10.0 语义化 | 通过（不被降级） |
| 失败注入（2 场景） | 真实错误原因不再被掩盖 |
| 端到端演练（验签 + sha256 + SQL + 版本 + 建表） | 全绿 |

所有验证均基于**真实 MySQL 8.0.36** 复现，而非静态推断。每轮测试后测试库还原至 12 表 / `app_version=1.0.0` 基线。

---

## 四、发布产物与线上校验

### 产物

| 项 | 值 |
|---|---|
| 发布包 | `keshi-1.1.1.zip` |
| 文件数 | 402 |
| 大小 | 755,855 字节 |
| sha256 | `abe38ba595ee2d26d39bd4979c1799cf28eb7a5ef806681b3765974814cefbc6` |
| Release ID | `2097928606155862016` |
| target commit | `792a9b3` |
| 下载地址 | `https://cnb.cool/bmayan/class-hours-tracker/-/releases/download/v1.1.1/keshi-1.1.1.zip` |
| 清单地址 | `https://cnb.cool/bmayan/class-hours-tracker/-/releases/download/v1.1.1/manifest.json` |

### 线上校验（下载后实测）

- `keshi-1.1.1.zip`：HTTP **200**，755,855 字节，实测 sha256 与清单声明**完全一致**
- `manifest.json`：HTTP **200**，`latest_version = 1.1.1`，RSA-SHA256 **验签通过**
- 包内抽检：修复标记齐全（`isImplicitCommitStatement` ×2、`inTransaction()` ×4、DDL 提示文案存在）
- `config/app.php` 版本号 = `1.1.1`；`upgrade.sql` 含 3 处 LPAD、目标版本 `1.1.1`
- 无 `_` 前缀临时项，无 `installed.php` / `release/` / `.git/` / `runtime/` 泄漏

### 提交链

```
46eca71  fix(update): 修复升级报 'There is no active transaction' 致命 bug
a596702  release: v1.1.1 - 修复在线更新 + 版本号比较语义化
1efd75f  release: v1.1.1 manifest (CNB 下载源 + RSA 签名)
792a9b3  fix(build): 发布包排除顶层 _ 前缀目录 + 重签清单
```

CNB 远端 `main` 与 tag `v1.1.1` 均已指向 `792a9b3`。

---

## 五、升级须知（重要）

### "鸡生蛋"问题

**执行在线更新的是旧代码**，所以 UpdateService 自身的 bug 无法靠在线更新修复。
从**低于 v1.1.1** 的版本升级，必须先手工替换单个文件：

1. 上传 `application/common/service/UpdateService.php`（v1.1.1 版本）覆盖服务器同名文件
2. 再进后台点"在线更新"

单文件热修已备在 `_hotfix_upload/application/common/service/UpdateService.php`（本地目录，已 gitignore）。

从 v1.1.1 起，后续版本升级无需此步骤。

### 如果此前已升级失败过

若你的站点已经出现过 `There is no active transaction`，数据库可能处于半升级状态
（表已建好但 `app_version` 仍是旧值）。v1.1.1 的 `upgrade.sql` 是**幂等**的，
`CREATE TABLE IF NOT EXISTS` 不会重复建表，版本号会被正确同步到 `1.1.1`，直接升级即可。

---

## 六、遗留事项

- GitHub 副远端（`leeabc-gif/Class-Hours-Tracker-`）因本地缺凭据未推送，不影响 CNB 更新链路
- 旧脚本 `_env/publish_cnb.py` 第 91 行仍有 verify-404 隐患，本次改用 `_env/publish_cnb_111.py`；
  后续可把修正合并回旧脚本
