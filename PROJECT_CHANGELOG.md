# 课时通 · 变更记录

> 这是 **本项目自身** 的版本变更记录，区别于框架自身的 `CHANGELOG.md`（后者是 ThinkPHP 5.1 LTS 框架变更）。
> 版本号采用 `1.0.x`（次版本 = 安全/兼容性升级；补丁号 = 修复/小优化）。

---

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
