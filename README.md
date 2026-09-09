<!-- 语言切换徽章：中文 / English 同一文档锚点跳转 -->
<div align="center">

# 课时通 · 多教师课时统计与课酬核算系统
**Class Hours Tracker · Multi-Teacher Period-Tracking & Payroll System for Vocational Schools**

[中文](#中文) ｜ [English](#english)

![PHP](https://img.shields.io/badge/PHP-%3E%3D7.4-8892BF)
![ThinkPHP](https://img.shields.io/badge/ThinkPHP-5.1-blue)
![MySQL](https://img.shields.io/badge/MySQL-5.7%2F8.0-4479A1)
![Frontend](https://img.shields.io/badge/Frontend-Bootstrap5%20%2B%20Chart.js-7952B3)
![License](https://img.shields.io/badge/License-Apache--2.0-green)

一个为**中等职业学校**量身打造的开源课时课酬管理系统 —— 专治"老师上了一学期课，期末算课时、算课酬全靠 Excel 和人工"的痛点。

</div>

---

<a name="中文"></a>

# 🇨🇳 中文介绍

## 为什么做这个系统？

中职学校的教师课时核算，长期依赖纸质课表与 Excel 手工汇总：

- 排课靠脑记，课时记录零散在个人表格里，**期末核对费时费力**；
- 课酬单价、代课/补课/调课规则复杂，**人工易算错**；
- 教务与教师之间**数据不透明**，缺乏可追溯的操作记录；
- 公办校多用商用系统，**小班额、多教师、少管理员的场景往往"杀鸡用牛刀"**。

**课时通**聚焦上述痛点，用一套轻量的 Web 系统把"排课 → 记录 → 统计 → 核算 → 导出"全流程闭环。

## ✨ 核心特性

- 👥 **双角色权限**：管理员 / 教师，后端强制数据隔离，教师只能看到自己的课时
- 📝 **课时全生命周期**：新增、编辑、软删除、恢复、防重复；支持常规课/补课/调课/实训四类
- 🚀 **高效录入**：快速录入弹窗、历史课程模板一键复用、周次批量生成、粘贴课表 AI 解析导入
- 🏫 **班级池与多选**：管理员维护班级池，录入时班级多选（可临时自定义），课时内多班用逗号分隔快照
- 📚 **课程库 + 三级下拉**：常用★ / 全部课程 / 自定义 三组课程选择，自定义课程不入库
- 🧮 **课酬自动核算**：课时 × 单价自动计算金额；支持全局/个人单价与历史价格快照（防改价串账）
- 📊 **可视化统计**：教师个人仪表盘（本月/累计课时·课酬）、按周/按月统计、全校看板、院系汇总、**管理员月度课酬对账**（教师 × 月份交叉表）
- 📤 **导出与备份**：个人 / 全校 / 院系课时 CSV 导出、月度对账导出、**一键 SQL 数据库备份**
- 🤖 **AI 助手**：会话式问答与统计、粘贴课表解析；预留大模型接口（OpenAI 兼容），未配置时自动回落本地规则引擎
- 📅 **课表日历**：周视图课表，直观查看排课
- 🔐 **安全**：口令哈希、Session 会话、操作日志全留痕、CSRF token（X-CSRF-Token）+ 严格 SSRF 防护、班级/课程输入白名单校验
- 🗂️ **在线更新**：后台内置更新管理器（manifest 验签 + 增量覆盖 + 自动备份 + 可回滚 + 维护模式）
- 📱 **响应式**：Bootstrap 5 + Chart.js，PC / 手机都能用

## 📦 最近更新

**即将发布（v1.0.5）· 重置示例数据预览兼容修复**

- 🛠️ **修复重置预览报错**：统一使用实际的 `ks_` 数据表名称，解决数据库表存在但预览仍访问 `lesson` 等未加前缀表名的问题。

**2026-09（v1.0.4）· 全选修复与系统版本同步**

- ✅ **课时列表全选修复**：表头全选、反选和批量删除统一限定在当前课时表格，修复点击全选后选择状态不完整或范围错误的问题
- 🧹 **前端缓存刷新**：更新静态资源版本参数，避免浏览器继续加载旧版 `app.js`
- 🔢 **系统版本统一**：代码基线、数据库种子数据、示例数据重置和已安装版本统一使用 `1.0.4`
- 🗄️ **在线升级兼容**：新增版本同步迁移，兼容 `1.0.0`～`1.0.3` 的历史安装记录；发布包包含根目录 `upgrade.sql`

**2026-09（v1.0.3）· CNB / GitHub latest URL 解析 + 检查失败可读提示 + 课时记录批量操作**

- 🔧 **CNB `/-/releases/latest` 解析**：v1.0.2 只识别 GitHub 的 `api.github.com/repos/.../releases/latest` 一种入口，CNB 的 `cnb.cool/<owner>/<repo>/-/releases/latest` 拿不到真 manifest（404）。新增 `UpdateService::resolveCnbLatest()`：抓 CNB releases 列表页，从 HTML 抓 `vX.Y.Z` 形式的最新 tag，再拼回 `/-/releases/download/<tag>/manifest.json`；GitHub 与 CNB 共用 `resolveManifestEntry()` 链式入口
- 🩺 **检查更新失败可读**：之前 `latest_version` 一栏永远显示 `—`，连失败原因都看不到。`Admin::updateCheck` 失败时也写 `update_last_check_at` + 新增 `update_last_check_error` 缓存；前端「最新版本」卡片下方新增红字提示区，直接展示错误摘要（如「CNB Release 资源未找到：仓库可能还没在 Releases 页发布带 manifest.json 资源的新版本 tag」）
- 📝 **「更新源」提示文案修正**：之前同时显示「更新源：GitHub Releases」+「清单：cnb.cool/...」会让人误解；现在根据 manifest_url 与默认 URL 的实际匹配情况显示「GitHub Releases（默认） / CNB 官方 Release（默认） / 自定义（manifest_url 覆写 / 直填）」
- ✅ **「我的课时记录」批量选择/删除**：补全 4 个之前只声明但未实现的回调（`updateCheckState / toggleCheckAll / lessonBatchDelete / lessonBatchByQuery`），新增显眼的「全选当前页 / 反选」按钮（紧贴"删除选中"），避免用户找不到全选入口

**2026-09（v1.0.2）· GitHub Releases 更新源 + 一键重置示例数据**

- 🐙 **默认走 GitHub Releases**：「基础配置 → 在线更新源」新增 `github / cnb / custom` 三选一下拉，**默认**指向 `api.github.com/repos/leeabc-gif/Class-Hours-Tracker-/releases/latest`（UpdateService 自动拉 API 拿 `tag_name`，再拼 `github.com/.../releases/download/<tag>/manifest.json` 拉真正的 manifest）；配套 `.github/workflows/release.yml`，**推送 `v*` tag 时自动构建** `release/keshi-<ver>.zip` + `release/manifest.json`（用 `tools/sign_manifest.php` 走 RSA-SHA256 签名），并以 GitHub Actions 的 `softprops/action-gh-release@v2` 发布为 Release 资产
- 🔑 **GitHub Token 字段**：`update_github_token` 配置（不回显明文，公开仓库可空），`UpdateService::httpGet` 自动注入 `Authorization: Bearer ...` 头；针对 401/403/404/429 给出更清晰的错误提示（私有仓库 / 限流 / 资产名错配）
- 🛡️ **降级兼容**：`cnb` 源（v1.0.1 的 CNB 通道）作为兜底保留，管理员随时可在「基础配置」切换；`custom` 选项允许指向自建对象存储或 Gitee raw
- 🧹 **签名工具升级**：`tools/sign_manifest.php` 新增 CI 模式（`--version --package --private-key --changelog-file --out`），GitHub Actions 一行命令直接产出已签名 manifest；旧用法（手动 JSON + 私钥）100% 兼容
- ♻️ **一键重置示例数据**：新增「基础配置 → 危险操作」与「系统更新」页的 **一键重置示例数据** 按钮，**清空全部业务表**（课时/班级/AI 会话/操作日志等 8 张表），**仅保留** 管理员账号（admin 至少留 1 个，密码回 `admin123`）、系统配置、院系、学期、教师中的非 admin、**1 门示范课程**（`工业机器人导论`，由 `ks_course.is_demo=1` 标识）；三重保险（admin 角色 + 输 `RESET` + 输当前管理员密码），全程事务 + 维护模式
- 🗄️ **数据库迁移**：`database/migrations/20260909_v102_github_default_and_reset.sql` 幂等新增 `ks_course.is_demo` 字段（并把 id=1 的 `工业机器人导论` 标记为示范），默认值切换为 GitHub
- 🔄 **同步发布**：v1.0.2 tag 同步推送到 CNB（`origin`）和 GitHub（`github`）双仓库，CHANGELOG 与 PROJECT_CHANGELOG 同步更新

**2026-09（v1.0.1）· 在线更新安全加固 + 系统参数持久化**

- 🛡️ **CSRF 防护真上线**：新增全局 `CsrfVerify` 中间件，所有 `POST/PUT/DELETE/PATCH` 必须携带 `X-CSRF-Token`；前端 `api()` 自动注入；登录页白名单；`Cookie` 增加 `HttpOnly` + `SameSite=Lax`；遇 419 自动重新拉取 token
- 🛡️ **SSRF 防护升级**：`UpdateService::httpGet` 用 `CURLOPT_RESOLVE` 锁解析结果（防 DNS rebinding / TOCTOU），`CURLOPT_PROTOCOLS` 仅允许 HTTP/HTTPS，关闭 302 跟随或对跳转目标重跑 SSRF
- 🛡️ **维护模式不卡死**：写入 `runtime/maintenance.flag` 用 `LOCK_EX`；注册 `register_shutdown_function` 兜底关闭，避免 PHP fatal 跳过 `finally` 导致永久锁死
- 🛡️ **维护拦截不再泄露状态**：从 `Base::initialize` 移入 `requireLogin/requireAdmin` 之后，仅对已登录用户返 503，附带 `Retry-After` 头
- 🛡️ **Manifest 多包拒绝**：`UpdateService::check` 强制 `files.length ≤ 1`，杜绝任意第二个文件绕过 sha256 白名单
- 💾 **系统参数持久化**：「基础配置」页正式提供 **更新清单地址 `update_manifest_url`** 字段；`settingsSave` 加严格 https + 长度 + SSRF 预校验（默认指向 CNB 官方 Release 公开下载通道 `cnb.cool/.../releases/latest/download/manifest.json`，推送 `v*` tag 时由仓库根 `.cnb.yml` 自动发布）
- 💾 **备份可靠性提升**：`Backup::dump` 在 `SHOW CREATE TABLE` 失败 / 视图 / 权限不足时**直接抛错**，不再静默生成无 DDL 的半成品 SQL；列名加反引号 + ` 转移
- 💾 **回滚可读性**：拆分 `restored_files` 与 `removed_new_files` 两项返回，前端分别展示「已还原文件」「已删除新文件」
- 📋 **更新状态可观察**：`updateStatus` 携带上次检查缓存的 `latest_version` / `last_check_at` / `php_version` / `app_version_baseline`；前端在「系统更新」页直接显示，无需每次点「检查更新」
- 📂 **新增 manifest.json 样例** `docs/manifest.example.json`，发布方可照着签名
- 🧹 **日志统一**：原 `runtime/debug_upd.txt` 裸堆栈全部改走 `Log::error`；SQL 错误回显改为行号 + 错误码，不再外泄 DDL 片段
- 🛠️ **补丁模式细节**：文件覆盖保留原 `fileperms`（`0755` 脚本不再被强制 `0644`）；备份 > 256MB 触发告警；`runtime/.htaccess` 增加 Apache 兜底防护

## 🖥️ 界面一览

> 截图占位：发布前请补充 `docs/screenshots/` 下的登录页、教师工作台、全校看板、月度对账截图。

## 🧱 技术栈

| 层 | 选型 |
|---|---|
| 后端 | PHP 7.4+ / ThinkPHP 5.1（LTS）|
| 数据库 | MySQL 5.7 / 8.0 |
| 前端 | 原生 SPA + Bootstrap 5 + Chart.js（无重型框架依赖）|
| 部署 | 支持宝塔面板一键部署、Nginx；也支持 PHP 内置服务器本地开发 |

## 📁 目录结构

```
keshi/
├─ public/              网站根目录（入口）
│  ├─ index.php         ThinkPHP 入口 + 未安装自动引导到 /install/
│  ├─ index.html        前端 SPA 外壳（登录/注册/主应用）
│  ├─ install/          Web 安装向导
│  └─ static/           前端静态资源（app.js 等）
├─ application/         应用代码
│  ├─ api/              API 控制器（Auth/Lesson/Course/Admin/Ai/Export/Stat/Profile）
│  ├─ common/           公共服务与模型（含 UpdateService 在线更新、AiEngine）
│  └─ index/            站点入口控制器
├─ config/              配置（含 update_trust.php 更新验签公钥）
├─ route/               路由
├─ database/            建表脚本、种子数据、迁移 SQL
├─ deploy/              宝塔 Nginx 部署参考
├─ tools/               发布工具（如 sign_manifest.php 更新清单签名）
├─ thinkphp/            ThinkPHP 5.1 框架
└─ vendor/              Composer 依赖
```

## 🚀 快速开始

### 环境要求
- PHP ≥ 7.4（需 pdo_mysql / mbstring / openssl / json；fileinfo 建议）
- MySQL 5.7 或 8.0
- Web 服务器（Nginx / Apache / PHP 内置服务器均可）

### 方式一：宝塔面板（推荐）
1. 新建站点，站点根目录指向 `public/`
2. 上传源码（或从 GitHub 拉取），站点根指到 `keshi/public`
3. 在宝塔创建空数据库
4. 浏览器访问站点域名 → **自动进入安装向导**，填数据库信息 + 设置管理员账号
5. 安装完成后自动锁定，进入登录页

### 方式二：本地开发（PHP 内置服务器）
```bash
cd keshi
# 修改 database/install.sql 前先创建数据库，或直接走 Web 安装向导
php -S 127.0.0.1:8000 -t public public/router.php
# 访问 http://127.0.0.1:8000/install/ 完成安装
```

### 首次登录
- 安装时自行设置的管理员账号
- 教师可通过登录页「立即注册」自助注册，由管理员在后台启用后即可登录

## 🗄️ 数据表（12 张，前缀 `ks_`）

`ks_teacher`（教师/管理员）、`ks_department`（院系）、`ks_course`（课程库）、`ks_course_favorite`（常用课程）、`ks_term`（学期）、`ks_class`（班级池）、`ks_lesson`（课时记录，核心表）、`ks_operation_log`（操作日志）、`ks_setting`（系统参数，含版本号）、`ks_ai_conversation` / `ks_ai_message` / `ks_teacher_ai_config`（AI 会话与配置）

## 📚 文档

- Web 安装向导内置（访问站点自动引导）
- [宝塔 Nginx 部署参考](deploy/baota-nginx.conf)

## 🛠️ 常见问题

- **教师注册后无法登录？** 注册默认是"待启用"状态，需管理员在「用户管理」启用。
- **录入班级只能单选？** 班级字段支持多选（从班级池搜索多选，也可临时自定义），课时内以逗号分隔存储。
- **课程下拉没有想要的？** 支持自定义课程，录完后不入课程库。
- **改密码会串课酬？** 每笔课时保存了价格快照，改单价不影响历史记录。

## 🔐 安全说明（v1.0.1 起）

- **CSRF**：所有写接口强制校验 `X-CSRF-Token`，登录/注册/install/取 token 本身白名单；遇 419 自动重取
- **SSRF**：在线更新仅允许 https，DNS 解析结果用 `CURLOPT_RESOLVE` 锁死；302 跟随被禁用；`CURLOPT_PROTOCOLS` 仅允许 HTTP/HTTPS
- **维护模式**：进入/退出维护都走 `runtime/maintenance.flag`，PHP fatal 时由 `register_shutdown_function` 兜底关闭
- **回滚**：最近一份文件备份 + 备份元信息 `_meta.json` 中的版本号，回滚后 `app_version` 一起回退
- **回滚日志**：所有回滚写 `ks_operation_log`，含 from/to 版本、还原文件数、删除新文件数
- **依赖升级建议**：`composer update` 后务必重新跑 PHP 语法检查 + 在测试环境跑一次在线更新 dry-run

## 🤝 贡献 / 反馈

欢迎提交 Issue 与 Pull Request。贡献前请先阅读下方「数据模型与设计约定」，保持与现有命名（`ks_` 前缀、`created_at`/`updated_at` 时间戳等）一致。

## 📄 License

本项目基于 **Apache-2.0** 开源协议发布，可自由使用与二次开发。

---

<a name="english"></a>

# 🇬🇧 English

## What Problem Does It Solve?

In Chinese vocational (中职) schools, teacher class-period (课时) and payroll records are often kept in scattered spreadsheets:

- Scheduling and lesson logs live in personal files — **semester-end reconciliation is painful**;
- Complex rate rules (make-up / swapped / training classes) make **manual calculation error-prone**;
- **No transparency or audit trail** between teachers and administration;
- Commercial systems are often **overkill for small-class, multi-teacher, few-admin teams**.

**Keshi** closes the loop from *scheduling → logging → statistics → payroll → export* with a lightweight web app built for exactly this scenario.

## ✨ Features

- 👥 **Dual-role access** (Admin / Teacher) with backend-enforced data isolation
- 📝 **Full lesson lifecycle**: create / edit / soft-delete / restore / duplicate-guard; supports regular, make-up, swapped & training class types
- 🚀 **Fast data entry**: quick-add modal, one-click reuse of historical templates, batch week generation, paste-a-schedule AI import
- 🏫 **Class pool + multi-select**: admins maintain a class pool; multi-select (or ad-hoc custom) classes per lesson, stored as a comma-separated snapshot
- 📚 **Course library + 3-tier dropdown**: ★Favorites / All / Custom — custom courses are not written back to the library
- 🧮 **Auto payroll**: amount = periods × rate; supports global/personal rates and per-record price snapshots (no retroactive tampering)
- 📊 **Visual analytics**: teacher dashboard, weekly/monthly stats, school-wide board, department rollups, and **admin monthly reconciliation** (teacher × month cross-tab)
- 📤 **Export & backup**: personal / school / department CSV, reconciliation CSV, and **one-click SQL database dump**
- 🤖 **AI assistant**: conversational Q&A & stats, paste-to-parse schedules; OpenAI-compatible API reserved — falls back to a local rule engine when unconfigured
- 📅 **Timetable calendar**: week-view schedule
- 🔐 **Security**: password hashing, server-side sessions, full operation logging, **CSRF token (X-CSRF-Token)**, **strict SSRF protection** (DNS pin + protocol allowlist), `HttpOnly` + `SameSite=Lax` cookies, **maintenance-mode auto-recovery** via `register_shutdown_function`
- 🗂️ **Online updater**: admin-managed update center with **manifest RSA-SHA256 signature verify**, **single-package enforcement**, SHA256 + path allowlist, **auto backup before apply**, **explicit rollback** (separates restored/removed-new files), maintenance mode with `Retry-After`
- 📱 **Responsive UI**: Bootstrap 5 + Chart.js — works on desktop and mobile

## 🆕 Recent Updates

**Coming soon (v1.0.5) — Reset-preview table-name compatibility fix**

- 🛠️ **Fixed reset preview errors**: consistently use the actual `ks_` table names, resolving failures where the database table exists but the preview queried unprefixed names such as `lesson`.

**2026-09 (v1.0.4) — Select-all fix and system-version synchronization**

- ✅ **Fixed lesson-list select all**: header select-all, invert selection, and bulk deletion are now scoped to the current lesson table, fixing incomplete or incorrect selection ranges
- 🧹 **Frontend cache refresh**: bumped the static asset version so browsers do not keep loading an outdated `app.js`
- 🔢 **Unified system version**: code baseline, database seed, demo reset, and installed-version fallback now consistently use `1.0.4`
- 🗄️ **Online-update compatibility**: added a version-sync migration for existing `1.0.0`–`1.0.3` installations; release packages include the root-level `upgrade.sql`

**2026-09 (v1.0.3) — CNB / GitHub latest URL resolver + readable check-failure + batch lesson ops**

- 🔧 **CNB `/-/releases/latest` resolver**: v1.0.2 only recognised GitHub's `api.github.com/repos/.../releases/latest` entry. The CNB `cnb.cool/<owner>/<repo>/-/releases/latest` URL would 404 because there's no JSON API. Added `UpdateService::resolveCnbLatest()` — it fetches the CNB releases page, scrapes the latest `vX.Y.Z` tag from the HTML, and rewrites the URL to `/-/releases/download/<tag>/manifest.json`. Both providers go through a single `resolveManifestEntry()` chain
- 🩺 **Readable check-failure reason**: the `latest_version` cell used to silently show `—` and gave no hint why. `Admin::updateCheck` now persists `update_last_check_at` and a new `update_last_check_error` cache on failure; a red-text error block sits under the version card on the system-update page
- 📝 **Update-source hint fixed**: the previous "GitHub Releases" + "manifest: cnb.cool/..." combo was confusing. The hint now reflects the actually-resolved URL — `GitHub Releases (default)` / `CNB 官方 Release (default)` / `Custom (manifest_url override / direct)`
- ✅ **Batch lesson selection / deletion**: the four callbacks declared in the page template (`updateCheckState / toggleCheckAll / lessonBatchDelete / lessonBatchByQuery`) were unimplemented and threw `ReferenceError`. They are now wired up, and two prominent buttons — **Select current page** and **Invert** — sit right next to **Delete selected** so users can find the bulk-select entry point

**2026-09 (v1.0.2) — GitHub Releases channel + one-click demo-data reset**

- 🐙 **Default update source switched to GitHub Releases**: a new `github / cnb / custom` selector in 「基础配置 → 在线更新源」, defaulting to `api.github.com/repos/leeabc-gif/Class-Hours-Tracker-/releases/latest` (UpdateService auto-fetches `tag_name` via the API, then resolves `github.com/.../releases/download/<tag>/manifest.json` for the real manifest). Ships with `.github/workflows/release.yml` — every `v*` tag push triggers an automated build of `release/keshi-<ver>.zip` + a signed `release/manifest.json` (RSA-SHA256 via `tools/sign_manifest.php`), then publishes them as Release assets via `softprops/action-gh-release@v2`
- 🔑 **GitHub Token field**: `update_github_token` config slot (masked in API responses, optional for public repos). `UpdateService::httpGet` now injects `Authorization: Bearer ...` and produces clearer 401/403/404/429 error messages (private repo / rate-limit / asset-name mismatch)
- 🛡️ **Backwards compatible**: the v1.0.1 **CNB** channel is retained as a fallback — admins can switch in 「基础配置」 at any time. The `custom` option allows pointing to your own object store (or Gitee raw)
- 🧹 **Signer upgrade**: `tools/sign_manifest.php` gains a CI mode (`--version --package --private-key --changelog-file --out`) so GitHub Actions can mint a signed manifest in one line. The legacy "hand-edit JSON + private key" flow still works 100%
- ♻️ **One-click demo-data reset**: new button in 「基础配置 → 危险操作」 and the 「系统更新」 page. **Wipes 8 business tables** (lessons / classes / AI conversations / operation logs …) and **keeps** the admin accounts (≥ 1 admin preserved, password reset to `admin123`), system settings, departments, terms, non-admin teachers, and **1 demo course** (`工业机器人导论`, flagged by `ks_course.is_demo=1`). Triple guard (admin role + type `RESET` + current admin password), all under a transaction inside maintenance mode
- 🗄️ **DB migration**: `database/migrations/20260909_v102_github_default_and_reset.sql` idempotently adds `ks_course.is_demo`, marks course id=1 as the demo, and switches the default `update_source` / `update_manifest_url` to GitHub
- 🔄 **Dual-remote publish**: the `v1.0.2` tag is pushed to both `origin` (CNB) and `github` remotes; `CHANGELOG.md` and `PROJECT_CHANGELOG.md` are kept in sync

**2026-09 (v1.0.1) — Online updater security hardening**

- **CSRF protection is now real**: global `CsrfVerify` middleware on every `POST/PUT/DELETE/PATCH`; frontend `api()` auto-injects the token; login/register/install/`csrfToken` are whitelisted; `Cookie` gained `HttpOnly` + `SameSite=Lax`; 419 auto-refetches
- **SSRF hardened**: `CURLOPT_RESOLVE` pins the resolved IP (no DNS rebinding / TOCTOU); `CURLOPT_PROTOCOLS` only allows HTTP/HTTPS; 302 follows are disabled
- **Maintenance mode cannot get stuck**: flag is written with `LOCK_EX`; a `register_shutdown_function` clears it on PHP fatal
- **Maintenance no longer leaks state**: moved from `Base::initialize` to `requireLogin/requireAdmin`, only authenticated users get a 503
- **Manifest multi-package rejected**: `UpdateService::check` enforces `files.length ≤ 1`
- **Settings persistence**: 「基础配置」 now exposes **`update_manifest_url`**, validated by `settingsSave` (https-only + length + SSRF pre-check); defaults to the **CNB official release channel** (`cnb.cool/<repo>/-/releases/latest/download/manifest.json`), published automatically by `.cnb.yml` on every `v*` tag push
- **Backup reliability**: `Backup::dump` **throws** when `SHOW CREATE TABLE` fails (views / missing privilege) — no silent half-baked dumps
- **Rollback observability**: splits `restored_files` vs `removed_new_files` in the response
- **Updatable status is cached**: `updateStatus` carries `latest_version` / `last_check_at` / `php_version` / `app_version_baseline` so the UI doesn't need a manual `check` first
- **New sample manifest**: `docs/manifest.example.json` for publishers
- **Logs unified**: `runtime/debug_upd.txt` removed; everything goes to `Log::error`; SQL errors are surfaced as `index #N` + code only — DDL is **not** echoed back
- **Patch correctness**: file overwrites keep the original `fileperms` (0755 scripts are no longer downgraded to 0644); backups > 256MB warn; `runtime/.htaccess` provides Apache-side fallback protection

## 🧱 Tech Stack

| Layer | Choice |
|---|---|
| Backend | PHP 7.4+ / ThinkPHP 5.1 (LTS) |
| Database | MySQL 5.7 / 8.0 |
| Frontend | Vanilla SPA + Bootstrap 5 + Chart.js |
| Deploy | BT-Panel (宝塔) one-click, Nginx; or local PHP built-in server |

## 🚀 Getting Started

### Requirements
- PHP ≥ 7.4 (ext: pdo_mysql / mbstring / openssl / json; fileinfo recommended)
- MySQL 5.7 or 8.0
- Nginx / Apache / PHP built-in server

### Install
1. Point your web root to `keshi/public`.
2. Create an empty MySQL database.
3. Open the site in a browser — the **install wizard runs automatically**, fill in DB credentials and set the admin account.
4. After install the wizard locks itself; log in with the admin account you just set.

### Local dev
```bash
cd keshi
php -S 127.0.0.1:8000 -t public public/router.php
# open http://127.0.0.1:8000/install/
```

### First login
- Use the **admin account you created during install**.
- Teachers can **self-register** from the login page; an admin enables them before they can sign in.

## 🗄️ Database (12 tables, prefix `ks_`)

`ks_teacher`, `ks_department`, `ks_course`, `ks_course_favorite`, `ks_term`, `ks_class`, `ks_lesson` (core), `ks_operation_log`, `ks_setting` (incl. version), `ks_ai_conversation`, `ks_ai_message`, `ks_teacher_ai_config`

## 📄 License

Released under the **Apache-2.0** license. Free to use and modify.
