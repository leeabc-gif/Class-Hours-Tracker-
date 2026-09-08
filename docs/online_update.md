# 在线更新运维手册

> 适用版本：v1.0.1+
> 适用对象：管理员（使用在线更新界面）+ 发布者（自建更新源 + 签名）

---

## 1. 管理员侧：配置更新源

1. 登录后台 → 「基础配置」→ 找到「**在线更新清单地址 (manifest.json)**」
2. 填写形如 `https://update.example.com/keshi/manifest.json` 的 https URL
3. 点「保存系统参数」—— 系统会先做 https / 长度 / SSRF 预校验，不通过会直接拒绝
4. 进入「**系统更新**」页
   - **当前版本** / **出厂基线** / **PHP 版本** 一次性显示
   - 「上次检查时间」与「最新版本」即使不点「检查更新」也能看到（来自缓存）
5. 点「**检查更新**」→ 系统调用 `UpdateService::check`：
   - 解析 URL → DNS 锁 IP → 协议白名单 → 拉清单（限大小 256KB）→ 验 RSA-SHA256 签名
   - 仅 `files.length ≤ 1` 通过
6. 点「**一键升级**」：
   - 进入维护模式（`runtime/maintenance.flag` + `Retry-After` 头 + 管理员豁免）
   - 备份文件到 `runtime/update_backups/files_<时间戳>/`，写 `_meta.json` 记录旧版本号、文件列表、新增文件列表、当前包 sha256
   - 下载 `manifest.files[0]` 到 `runtime/update_packages/`，校验 sha256
   - 解压到白名单路径（`application/ public/ config/ runtime/ ...`），跳过隐藏文件
   - 事务执行 `upgrade.sql`，失败 → 自动 rollback
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
