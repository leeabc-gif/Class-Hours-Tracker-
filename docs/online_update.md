# 在线更新运维手册

> 适用版本：**v1.0.2+**（v1.0.1 兼容 — 旧 CNB 通道仍可使用）
> 适用对象：管理员（使用在线更新界面）+ 发布者（自建更新源 + 签名）

---

## 0. v1.0.2 重要变化

- **默认更新源切到 GitHub Releases**：安装/升级后，「基础配置 → 在线更新源」默认是 `github`，清单 URL 默认指向
  `https://github.com/leeabc-gif/Class-Hours-Tracker-/releases/latest/download/manifest.json`
- **GitHub Token 字段**：`update_github_token`，公开仓库留空，私有仓库或需提升限流时填 PAT
- **CI 自动发布**：推送 `v*` tag 到 GitHub，`.github/workflows/release.yml` 自动构建 `release/keshi-<ver>.zip` + 已签名 `release/manifest.json`，并上传到 GitHub Release
- **一键重置示例数据**（新增）：见第 6 节

## 1. 管理员侧：配置更新源（v1.0.2 推荐 GitHub）

1. 登录后台 → 「基础配置」→ 找到「**在线更新源**」下拉（默认 github）
   - `github`（推荐）：默认拉 GitHub Releases 公开仓库 `leeabc-gif/Class-Hours-Tracker-` 的 `manifest.json`
   - `cnb`（v1.0.1 兼容）：拉 CNB 官方 release `bmayan/class-hours-tracker`
   - `custom`（自托管）：自填下方「更新清单地址」
2. 「更新清单地址 (manifest.json)」可被 `custom` 模式直接使用；`github / cnb` 模式会优先使用本字段（兼容老用户），未填则用默认 URL
3. 「GitHub Personal Access Token」（可选）：私有仓库或需提升限流时填公开 read PAT；**不回显明文**
4. 点「保存系统参数」—— 系统会先做 https / 长度 / SSRF 预校验，不通过会直接拒绝
5. 进入「**系统更新**」页
   - 页面顶部多了一行「更新源：GitHub Releases（默认）·清单：xxx」可一眼确认当前生效的源
   - **当前版本** / **出厂基线** / **PHP 版本** 一次性显示
   - 「上次检查时间」与「最新版本」即使不点「检查更新」也能看到（来自缓存）
6. 点「**检查更新**」→ 系统调用 `UpdateService::check`：
   - 解析 URL → DNS 锁 IP → 协议白名单 → 拉清单（限大小 1MB）→ 验 RSA-SHA256 签名
   - 自动注入管理员配置的 GitHub Token（`Authorization: Bearer ...`）
   - 401/403/404/429 会分别提示「未授权 / 限流 / 资源不存在」并附排查建议
   - 仅 `files.length ≤ 1` 通过
7. 点「**一键升级**」：
   - 进入维护模式（`runtime/maintenance.flag` + `Retry-After` 头 + 管理员豁免）
   - 备份文件到 `runtime/update_backups/files_<时间戳>/`，写 `_meta.json` 记录旧版本号、文件列表、新增文件列表、当前包 sha256
   - 下载 `manifest.files[0]` 到 `runtime/updates/`，校验 sha256
   - 解压到白名单路径（`application/ public/ config/ route/ thinkphp/`，跳过隐藏文件 + 任何含 `runtime/` `config/installed.php` `public/install/` 的文件）
   - 事务执行 `release/upgrade.sql`（仅在 GitHub Actions 中打包时存在），失败 → 自动 rollback
   - 一切顺利 → 写新版本号到 `ks_setting.app_version`、退出维护模式、记录 `ks_operation_log`
   - 任何 fatal / OOM → `register_shutdown_function` 兜底清维护 flag

## 2. 管理员侧：手动回滚

1. 「系统更新」页 → 点「**回滚**」
2. 行为：
   - 拿最近一份 `runtime/update_backups/files_*/` 还原（白名单 + 路径穿越校验）
   - 删除本次升级新增的文件（来自 `_meta.json.new_files`）
   - 若 `package_sha256` 一致，执行回滚包里的 `downgrade.sql`
   - 把 `ks_setting.app_version` 回退到 `_meta.json.version`
3. 响应拆 `restored_files` 与 `removed_new_files` 两项，前端分别展示

## 3. 管理员侧：维护模式（紧急停服）

- 「系统更新」页有「**进入维护模式 / 退出维护**」开关
- 维护期间：
  - 管理员仍可访问
  - 登录/注册/Cookie 探活/在线更新自身放行
  - 其他用户 → 503 + `Retry-After: <n>` 头
- 安全网：
  - 写入用 `LOCK_EX` 写 flag
  - `register_shutdown_function` 在 PHP fatal 时自动清 flag

## 4. 发布者侧：构造 manifest.json

参考 `docs/manifest.example.json`：

```json
{
  "latest_version": "1.0.2",
  "min_php": "7.4.0",
  "published_at": "2026-09-08T10:00:00+08:00",
  "changelog": "修复 A；增强 B",
  "requires": { "from_version": "1.0.1" },
  "files": [
    {
      "name": "keshi-1.0.2.zip",
      "url": "https://update.example.com/keshi/keshi-1.0.2.zip",
      "sha256": "<64 位 hex>",
      "version": "1.0.2",
      "size": 5242880
    }
  ],
  "signature": "<base64 RSA-SHA256(canonical json 去掉 signature 字段)>"
}
```

约束：
- `files` 只能放 1 个，多包会被拒
- `url` 必须 https，且 DNS 不能解析到内网/保留段
- `sha256` 必须用客户端 64 位小写 hex
- `signature` 用 `config/update_trust.php` 里登记的公钥对应的私钥签

## 5. 发布源选型（HTTPS raw 直链）

更新源需要支持 **公开 HTTPS 单文件直链**（即 `https://x/manifest.json` 直接拿到 `application/json`）。下面是常见选项：

| 平台 | raw URL 形式 | 公开访问 | 备注 |
|---|---|---|---|
| **Gitee** | `https://gitee.com/<org>/<repo>/raw/<branch>/<path>` | 是 | 国内速度好，但偶尔有频率限制 |
| **GitHub** | `https://raw.githubusercontent.com/<org>/<repo>/<branch>/<path>` | 是 | 国际常用，但国内慢 |
| **自建对象存储** | 自定义 | 视配置 | 推荐：阿里云 OSS / 腾讯云 COS / 七牛 / 华为云 OBS，绑定自定义域名即可 |
| **自建静态站** | 自定义 | 视配置 | Nginx + Let's Encrypt，零成本 |
| ✅ **CNB `cnb.cool`** | `https://cnb.cool/<org>/<repo>/-/releases/latest/download/<asset>` | 是 | 仓库 `.cnb.yml` 在 push `v*` tag 时自动调 `POST /releases/.../asset-upload-url` 上传 `manifest.json` 和 `keshi-<ver>.zip` 到 CNB Release；公开仓库匿名可访问 |

> 如果你的源码托管在 CNB（推荐），把构建产物 `manifest.json` + `keshi-x.y.z.zip` **同步推一份到 Gitee 或 OSS**，再在「基础配置」填那个 Gitee / OSS 的 raw URL。

## 6. 签名流程（`tools/sign_manifest.php`）

```bash
php tools/sign_manifest.php \
  --manifest=manifest.json \
  --private-key=/path/to/release_private.pem
```

工具会：
1. 读 manifest，去掉 `signature` 字段
2. `json_encode` 排序后用私钥做 `openssl_sign`（SHA256，RSA）
3. base64 后回写 `signature`

## 7. 升级包目录约定

`keshi-1.0.2.zip` 解压后白名单（只能出现下列前缀）：

| 路径 | 说明 |
|---|---|
| `application/**` | 应用代码 |
| `public/**` | 静态资源 / 入口 |
| `config/**` | 配置文件（不含 `config/installed.php` / `config/update_trust.php`）|
| `runtime/**` | 运行时（不含 `runtime/install.*`）|
| `database/**` | 迁移 SQL |
| `docs/**` | 文档 |
| `tools/**` | 工具脚本 |
| `deploy/**` | 部署配置 |
| `extend/**` | 扩展 |
| `*.md` | 顶层说明 |
| `composer.json` | 依赖清单（小心改） |

不在白名单的目录**会被静默丢弃**（保护 `runtime/maintenance.flag`、`config/installed.php`、`.env` 等不被覆盖）。

`upgrade.sql` 放在升级包根目录或 `code/` 目录下均会被识别。

## 8. 故障排查

| 现象 | 排查 |
|---|---|
| 写入 `update_manifest_url` 报"必须以 https:// 开头" | URL 是 http / 缺协议 / 含空格；改成纯 https |
| 「检查更新」报"更新清单地址不安全" | 解析到内网 / 保留段；换公网 https 域名 |
| 报"更新清单签名无效或缺失" | 私钥没签 / 公钥未在 `config/update_trust.php` 登记 / 重新发布 |
| 报"当前版本仅支持单包更新" | manifest.files 长度 > 1，合并 |
| 升级中途白屏 | 维护模式生效；管理员登录后点回滚 |
| 升级一直卡在维护模式 | 检查 `runtime/maintenance.flag` 是否残留，可手动删除（不会影响数据）|
| 回滚后版本号没回退 | `runtime/update_backups/files_*/_meta.json` 被人工改过；用更早的备份目录 |

## 9. 安全承诺

- 升级链路全程 https、DNS 锁、协议白名单、签名校验、路径白名单
- 所有失败都进 `runtime/log/`，**不**在 runtime 留裸堆栈文件
- SQL 失败回显只显示行号 + 错误码，**不**回显 DDL
- 维护模式兜底关闭，**不**会因 fatal 永久锁死
- CSRF 防护默认开启，跨站请求需带 `X-CSRF-Token`（取自 `/api/auth/csrfToken`）

---

## 6. 一键重置示例数据（v1.0.2+）

适用于：
- 上线前演示 / 教学展示 / 拍产品截图
- 装了测试数据想回到出厂状态
- 误操作清空了一些表，想回到「能演示」状态

### 操作入口
- 「基础配置 → 危险操作」区
- 「系统更新」页底部危险操作区

### 行为
- **清空** 8 张业务表：`ks_lesson / ks_class / ks_course`（非示范）/ `ks_course_favorite / ks_ai_conversation / ks_ai_message / ks_teacher_ai_config / ks_operation_log`
- **保留**：
  - 所有 `admin` 角色的教师账号（保证至少 1 个 admin 存在，密码回 `admin123`）
  - `ks_setting` 系统配置（学校名/单价/AI 等基础项），缺失键自动按出厂基线补齐
  - `ks_department` 院系
  - `ks_term` 学期
  - `ks_teacher` 中非 admin 的教师（普通教师全部保留）
  - 1 门示范课程 `工业机器人导论`（通过 `is_demo=1` 标识，重置后作为唯一业务数据展示）

### 三重保险
1. 必须 `admin` 角色（已被 `Admin::initialize` 拦）
2. 前端弹窗输入 `RESET` 二次确认
3. 再输一次当前管理员密码（`Teacher::checkPassword` 校验）

### 实现位置
- 控制器：`application/api/controller/Admin.php → resetDemoData / resetDemoDataPreview`
- 服务：`application/common/service/ResetDemo.php`
- 数据库：`database/migrations/20260909_v102_github_default_and_reset.sql`（`ks_course.is_demo` 字段）
- 操作日志：清空前先写一条 `reset_demo` 日志；清空 `ks_operation_log` 后再写一条「清理 N 行」总结

### 适用边界
- **不可恢复**：清空后无任何"回收站"；如需保留旧数据请先用 `mysqldump` 备份
- **重置后需重新登录**：会话内 `password` hash 已变（旧 admin 不一定存在），需用默认 `admin / admin123` 或已存在的 admin 密码登录
- **不影响**：图片上传目录、课时附件、安装锁 `config/installed.php`
- **服务器侧建议**：重置前先 `mysqldump` 整库；生产环境可临时把 `Admin::resetDemoData` 路由注掉（注释 router 或在控制器首行加 `return $this->fail('演示服务器已禁用重置');`）

