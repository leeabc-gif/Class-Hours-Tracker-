## v1.0.6 · 2026-09-09 · 节次与班级显示修复

### 修复
- 调整节次选项为 `1-4节`、`5-8节`、`1-2节`、`2-4节`、`5-6节`、`5-8节`。
- 课时列表将班级 ID 映射为班级名称显示，不再只显示数字。
- 统一版本号为 `1.0.6`，兼容已部署的 `1.0.5` 系统在线升级。

> 这是 **本项目自身** 的版本变更记录，区别于框架自身的 `CHANGELOG.md`（后者是 ThinkPHP 5.1 LTS 框架变更）。
> 版本号采用 `1.0.x`（次版本 = 安全/兼容性升级；补丁号 = 修复/小优化）。

## v1.0.5 · 2026-09-09 · 重置预览与在线更新兼容修复

### 修复
- 修复「一键重置示例数据」预览访问未加前缀的 `lesson`、`class`、`course` 等逻辑表名，导致数据库实际表为 `ks_lesson` 时出现 `Table '...lesson' doesn't exist` 的问题。
- 预览、清理和重建示范课程统一访问实际的 `ks_` 数据表。
- 修复在线更新请求遇到合法 HTTP 301/302/303/307/308 跳转时误报失败的问题；每次跳转都重新执行 URL、DNS 和 SSRF 校验，最多允许 3 次。
- 统一当前已安装版本、代码基线、种子数据和示例重置默认版本为 `1.0.5`，新增版本同步迁移。

### 修复
- 修复「我的课时」表头全选和批量删除读取范围不一致的问题，统一限定在当前课时表格。
- 更新前端静态资源版本参数，避免浏览器继续使用旧版 `app.js` 缓存。
- 统一当前已安装版本、代码基线、种子数据和示例重置默认版本为 `1.0.4`。
- 新增版本同步数据库迁移，历史 `1.0.0`～`1.0.3` 安装记录升级后同步为 `1.0.4`，更高版本不被覆盖。
- 修复发布流水线中 `upgrade.sql` 在压缩包生成后才复制的问题，确保发布包、SHA256 和数据库迁移内容一致。

### 兼容性
- 兼容现有 `v1.0.3` 数据库和更新流程。
- 不覆盖高于 `1.0.4` 的数据库版本记录。


### 修复（patch）
- **CNB `/-/releases/latest` 解析**：v1.0.2 只识别 `api.github.com/repos/.../releases/latest` 一种入口，CNB 的 `cnb.cool/<owner>/<repo>/-/releases/latest` 拿不到真 manifest（404）。本次新增 `UpdateService::resolveCnbLatest()`：抓 `cnb.cool/<owner>/<repo>/-/releases` 列表页，从 HTML 抓 `vX.Y.Z` 形式的最新 tag，再拼回 `/-/releases/download/<tag>/manifest.json`。GitHub 与 CNB 共用 `resolveManifestEntry()` 链式入口
- **检查更新失败原因可视化**：之前 `latest_version` 一栏永远显示 `—`，连失败原因都看不到。本次 `Admin::updateCheck` 失败时也写 `update_last_check_at` + 新增 `update_last_check_error` 缓存；前端「最新版本」卡片下方新增红字提示区，直接展示错误摘要（如「CNB Release 资源未找到：仓库可能还没在 Releases 页发布带 manifest.json 资源的新版本 tag」）
- **「更新源」提示文案修正**：之前同时显示「更新源：GitHub Releases」+「清单：cnb.cool/...」会让人误解；现在根据 manifest_url 与默认 URL 的实际匹配情况显示「GitHub Releases（默认） / CNB 官方 Release（默认） / 自定义（manifest_url 覆写 / 直填）」
- **「我的课时记录」批量选择/删除**：补全 `App.updateCheckState / toggleCheckAll / lessonBatchDelete / lessonBatchByQuery` 4 个之前只声明但未实现的回调，新增显眼的「全选当前页 / 反选」按钮（紧贴"删除选中"），避免用户找不到全选入口

### 兼容性
- 100% 兼容 v1.0.2，管理员无感知升级
- `update_manifest_url` 字段不变，老配置直接生效
- 新增的 `update_last_check_error` setting 字段是可选的，老数据没这个 key 不影响逻辑
- `app_version` 字段没动，旧版本号会被 update 流程正确覆盖


## v1.0.2 · 2026-09-09 · GitHub Releases 更新源 + 一键重置示例数据

### 新功能（minor）
- **GitHub Releases 作为默认更新源**：
  - 「基础配置 → 在线更新源」下拉：github（默认）/ cnb（v1.0.1 兼容）/ custom
  - 默认清单 URL：https://api.github.com/repos/leeabc-gif/Class-Hours-Tracker-/releases/latest（UpdateService 自动解析 tag_name，再拉 `github.com/.../releases/download/<tag>/manifest.json`）
  - 新配置 `update_github_token`（不回显明文），私有仓库或限流时填写
  - `UpdateService::httpGet` 自动注入 `Authorization: Bearer ...`
  - 401/403/404/429 返回更具体的错误提示
- **一键重置示例数据**（`Admin::resetDemoData` + `ResetDemo` 服务）：
  - 入口：「基础配置 → 危险操作」+「系统更新」页底部
  - 清空 8 张业务表（lessons / classes / 课程非示范 / AI / 操作日志 / 收藏等）
  - 保留：admin 账号（≥1，密码回 admin123）+ ks_setting + 院系 + 学期 + 非 admin 教师 + 1 门示范课程
  - 三重保险：admin 角色 + 输入 `RESET` + 输入当前管理员密码
  - 全程事务 + 进入维护模式
- **GitHub Actions CI**：`.github/workflows/release.yml` 监听 `v*` tag，自动构建 `release/keshi-<ver>.zip` + 签名 `release/manifest.json`，用 `softprops/action-gh-release@v2` 发布
- **签名工具升级**：`tools/sign_manifest.php` 支持 CI 模式（`--version --package --private-key --changelog-file --out`），单行命令直接出已签名 manifest
- **数据库迁移**：`database/migrations/20260909_v102_github_default_and_reset.sql`
  - ks_course 新增 `is_demo TINYINT(1)` 字段（幂等）
  - id=1 的「工业机器人导论」标记为 is_demo=1
  - 默认 update_source=github、update_manifest_url 切到 GitHub Releases
  - 兜底出厂 ks_setting（仅补空键，不覆盖 update_manifest_url）
- **UI 增强**：
  - 基础配置页增加「在线更新源」下拉 + GitHub Token 字段 + 危险操作区
  - 系统更新页增加更新源提示 + 危险操作区
- **文档**：`README.md` 中英文双版同步补 v1.0.2 介绍

### 兼容性
- 100% 兼容 v1.0.1，管理员无感知升级
- 现有 CNB 通道仍可在「基础配置」切换回
- 旧版 `sign_manifest.php` 调用方式 100% 兼容
- `manifest.example.json` 字段未变
## v1.0.1 · 2026-09 · 在线更新安全加固
### 更新点（patch）
- **默认更新源切换到 CNB 官方 Release 公开下载**
  - 仓库根 `.cnb.yml`：push `v*` tag 时自动调 CNB Release API 把 `release/manifest.json` + `release/keshi-<ver>.zip` 发布到仓库 Release
  - 公开仓库匿名可访问：`https://cnb.cool/bmayan/class-hours-tracker/-/releases/latest/download/manifest.json`
  - 同步 `database/seed.sql` / `20260908_default_manifest_url.sql` / `config/update_trust.php` / `README.md` / `docs/online_update.md`
  - 升级 release 时同步用新私钥重签 manifest，公钥同步换到 `config/update_trust.php`

### 核心目标
对在线更新与回滚链路做全面代码审查后，按「必须-1/2/3/4/5 → 严重 S-1/S-2 → 中 M-1~M-10 → 轻 L-1~L-8」四档顺序一次性修复。

### 必修
1. **settings 持久化 `update_manifest_url`**
   - `App.saveSettings` 增 `update_manifest_url` 字段
   - `Admin::settingsSave` 强校验：`https` 开头 + 长度 ≤ 1000 + `UpdateService::validateManifestUrl` SSRF 预校验
   - 「基础配置」页新增输入框 + 状态显示
2. **CSRF 防护真正落地**
   - 新增 `app/http/middleware/CsrfVerify.php`，注册到 `config/middleware.php`
   - 新增 `/api/auth/csrfToken` 接口（白名单，登录前可拉）
   - 登录后由 `AuthService::attempt` 派发 token
   - 前端 `api()` 对 `POST/PUT/DELETE/PATCH` 自动注入 `X-CSRF-Token`；遇 419 自动重新拉取
   - `Cookie` 增加 `httponly=true` + `samesite=Lax`
3. **Manifest 多包拒绝**
   - `UpdateService::check` 强制 `files.length ≤ 1`，避免任意第二个文件绕过 sha256 策略
4. **Backup::dump 完整性**
   - `SHOW CREATE TABLE` 失败 / 视图 / 权限不足 → 直接抛错，不再拼半成品 SQL
   - 列名加反引号 + ` 转移
5. **维护模式兜底关闭**
   - `Base::setMaintenance(true)` 用 `LOCK_EX` 写 flag
   - 注册 `register_shutdown_function` 在 PHP fatal / exit / OOM 时兜底清除，避免永久锁死

### 严重
- **S-1 SSRF TOCTOU 防护**
  - `CURLOPT_RESOLVE` 锁解析结果（防 DNS rebinding）
  - `CURLOPT_FOLLOWLOCATION` 关闭，跟随 302 一律拒绝
  - `CURLOPT_PROTOCOLS = HTTP|HTTPS` 显式协议白名单
  - 解析时新增 `resolveSafeIps()` 公共方法（带 A/AAAA DNS 解析 + 私网/保留段过滤）
- **S-2 维护拦截不再泄漏状态**
  - 从 `Base::initialize` 移入 `requireLogin` / `requireAdmin` 之后
  - 仅对已登录用户返 503，附带 `Retry-After` 响应头

### 中等
- **M-1** 文件覆盖保留原 `fileperms`（0755 脚本不再被强制 0644）
- **M-2** settingsSave 强校验（合并到 必-1）
- **M-3** `runtime/debug_upd.txt` 移除，统一走 `Log::error`（含 traceAsString）
- **M-4** `runSqlFromZip` 错误回显改为 `index #N + 错误码`，SQL 内容走 Log（不再外泄 DDL 片段）
- **M-5** `Backup` 检测 BLOB 资源流并抛错
- **M-6** 单次数据库备份 > 256MB 触发 `Log::warning` + 写 `BACKUP_LARGE.txt` 提示
- **M-7** `Backup::rawPdo` 把 PDO 异常映射为「数据库连接失败，请检查 installed.php 配置」，详细错误走 Log
- **M-8** `runtime/.htaccess` 增加 Apache 兜底 `Require all denied`（与 Nginx deny 双重防护）
- **M-9** 列名加反引号
- **M-10** `rollback` 拆 `restored_files` 与 `removed_new_files` 两项返回

### 轻量
- **L-1** 修正 `maintenanceExempt` 白名单（替换「死代码」`update` 控制器白名单，改为 5 个真实方法）
- **L-2** updateStatus 补 `latest_version` / `php_version` / `app_version_baseline` / `last_check_at`
- **L-3** 所有 `mb_substr` 显式 `UTF-8` 编码
- **L-4** updateStatus 读 `update_last_check_latest` 缓存；`updateCheck` 写入
- **L-5** `apply($zipPath)` 移除误导形参 `$lockHandle`
- **L-6** `ks_setting.cfg_key` 长度暂未升级（保持原 schema，避免迁移）
- **L-7** 新增 `docs/manifest.example.json` 样例 + `docs/online_update.md` 使用文档
- **L-8** 维护响应 `Retry-After` 头

### 文档 / 配置
- README 中英文「最近更新」「安全说明」同步刷新
- 新增 `docs/online_update.md`（发布者指南 + 管理员运维手册）
- 新增 `docs/manifest.example.json`

### 验证
- 全部 9 个修改过的 PHP 文件 `php -l` 语法通过
- 12 个文件改动，提交：`be1984e`
- 已推送 CNB：`https://cnb.cool/bmayan/class-hours-tracker` 主分支 `be1984e8...`

---

## v1.0.0 · 2026-09 · 首发

- 双角色权限（管理员 / 教师）
- 课时全生命周期（CRUD / 软删除 / 恢复 / 防重复）
- 高效录入（弹窗 / 模板复用 / 周次批量 / AI 解析）
- 班级池 + 课程库 + 三级下拉
- 课酬自动核算 + 价格快照
- 可视化统计（个人 / 班级 / 院系 / 月度对账）
- CSV 导出 + SQL 备份
- AI 助手（OpenAI 兼容 + 本地规则引擎兜底）
- 在线更新（manifest 验签 + 增量 + 备份 + 回滚 + 维护模式）
- CSRF token + 严格 SSRF 防护
- 响应式 Bootstrap 5 UI
