# v1.1.3 升级操作指引

> 这一版专治你截图里那个 **`升级失败：更新失败：There is no active transaction`**。

---

## 一、你现在遇到的是什么问题

你的站点是 **v1.0.8**，更新源是 **GitHub Releases（默认）**。

两件事同时出了问题：

| # | 问题 | 后果 |
|---|------|------|
| 1 | GitHub 上最新只发到 **v1.0.6** | 你 1.0.8 的站点检查更新，拿到的是**比自己还旧**的版本 |
| 2 | v1.1.0 / v1.1.2 的升级 SQL 含 7 个 `CREATE TABLE` | 用旧代码执行必然报 `There is no active transaction` |

### 为什么第 2 条是个「死锁」

执行升级动作的，是**你服务器上那份旧的 `UpdateService.php`**，不是更新包里的新版。

旧代码的做法是：无条件用 `beginTransaction()` 把整份 `upgrade.sql` 包起来。
但 MySQL 有个特性 —— **执行 DDL（CREATE/ALTER/DROP）会隐式提交事务**。

所以第 1 条 `CREATE TABLE` 一执行，事务当场就没了：

```
#1 [CREATE] 执行后 inTransaction = NO   ← 事务在这里消失
#2 [CREATE] 执行后 inTransaction = NO
...
commit() → There is no active transaction     ← 你看到的报错
rollBack() → There is no active transaction   ← 真正的错误被这句掩盖了
```

**v1.1.1 其实已经修好了这个 bug，但修复代码在更新包"里面"，而执行升级的是包"外面"的旧代码。**
不升级就拿不到修复，没有修复就升不了级 —— 死锁。

---

## 二、v1.1.3 是怎么破局的

v1.1.3 是一块**垫脚石**：

- **代码内容和 v1.1.2 完全一样**（含修复后的 UpdateService、自动检查、一键更新）
- **但升级 SQL 只保留纯 DML**（同步版本号 + 清理写死的更新源），**一条 DDL 都没有**

这样旧代码也能顺利跑完。装完 v1.1.3，你站点上的更新器就是修复版了，
以后再升含建表操作的版本就不会再出问题。

### 实测对照（真实 MySQL）

| 版本 | 用旧代码执行的结果 |
|---|---|
| v1.1.2 | 第 1 条 CREATE 后事务丢失 → **复现报错**，表数 12→19 但版本没升上去（半升级状态） |
| v1.1.3 | 3 条 DML 全程 `inTransaction=YES` → **commit 成功**，版本正确升到 1.1.3 |

---

## 三、你要怎么操作（二选一）

### 方案 A：改用 CNB 源（推荐，已发布好，改一次就行）

1. 进后台 **「系统设置 → 在线更新」**
2. **更新源** 选 `CNB`（如果有下拉框），或在 **更新清单地址** 填：

```
https://cnb.cool/bmayan/class-hours-tracker/-/releases/latest/download/manifest.json
```

3. 保存 → 回「系统更新」页 → 点 **检查更新** → 会看到 **v1.1.3**
4. 点 **下载并安装**

装完之后：
- 更新器已是修复版，以后升级不会再报事务错
- 写死的旧地址会被自动清空，进更新页就**自动检查新版本**，有新版直接点「立即更新」

### 方案 B：继续用 GitHub 源（需要你手工传两个文件）

代码和 tag 我已经用 SSH 推上 GitHub 了，但**创建 Release 和上传附件必须用 API Token**，我这边没有。

你自己传的话：

1. 打开 https://github.com/leeabc-gif/Class-Hours-Tracker-/releases/new
2. **Choose a tag** 选择已存在的 `v1.1.3`
3. 标题填 `v1.1.3`
4. 把下面两个文件拖进 **Attach binaries** 区域：

```
release/github-v1.1.3/keshi-1.1.3.zip
release/github-v1.1.3/manifest.json
```

> ⚠️ 这两个文件在 `release/github-v1.1.3/` 目录里，是**专门为 GitHub 签好名的版本**
> （清单里的下载地址指向 github.com）。
> 不要用 `release/manifest.json`，那个是 CNB 版，地址不对。

5. 点 **Publish release**
6. 回你的后台点「检查更新」，就能看到 v1.1.3 了（GitHub 源走的是 API 自动取 latest，不用改地址）

---

## 四、发布信息

| 项 | 值 |
|---|---|
| 版本 | v1.1.3 |
| 更新包 | `keshi-1.1.3.zip`，769,807 字节 |
| SHA-256 | `a46e4d6ce201d4e5a77c133624d1aae11bd9b31d7b62896387837e492ddde119` |
| 最低可升级版本 | 1.0.0（1.0.x 老站也能直升） |
| CNB Release | `2097957589685698560`（已发布，可用） |
| GitHub | 代码与 tag 已推送，Release 待手工创建 |
| 提交 | `2e71a54` |

### 线上校验结果（CNB）

- `manifest.json` HTTP 200 / `keshi-1.1.3.zip` HTTP 200
- 下载后实测 sha256 与清单**一致**
- RSA-SHA256 验签**通过**
- 线上包内 `upgrade.sql` DDL 数 = **0**
- 线上包内 UpdateService 含修复标记
- Release 列表解析 latest → **v1.1.3**

---

## 五、升完之后会怎样

- ✅ 报错消失，升级正常完成
- ✅ 进「系统更新」页**自动检查**新版本，不用手动点
- ✅ 有新版直接显示 **「有新版本可用！」横幅 + 大号「立即更新」按钮**，点一下就升
- ✅ 已是最新则显示绿色「已是最新版本」

也就是你截图里想要的那种效果。

## 六、遗留事项

AI 中转平台（5 张表）和通知公告（2 张表）的**建表操作没有包含在 v1.1.3 里**
（因为它们是 DDL，会触发本次这个问题）。

这些表会在**下一个版本**补齐 —— 那时你站点上跑的已经是修复后的更新器，
含 DDL 的升级包不会再出问题。如果你现在就要用这两个功能，告诉我，我可以给你一份
单独执行的 SQL。
