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
- 🔐 **安全**：口令哈希、Session 会话、操作日志全留痕、CSRF 防护、班级/课程输入白名单校验
- 🗂️ **在线更新**：后台内置更新管理器（manifest 验签 + 增量覆盖 + 自动备份 + 可回滚 + 维护模式）
- 📱 **响应式**：Bootstrap 5 + Chart.js，PC / 手机都能用

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
- 🔐 **Security**: password hashing, server-side sessions, full operation logging, CSRF protection
- 🗂️ **Online updater**: admin-managed update center (manifest signature verify + incremental patch + auto-backup + rollback + maintenance mode)
- 📱 **Responsive UI**: Bootstrap 5 + Chart.js — works on desktop and mobile

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
