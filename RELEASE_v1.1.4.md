# keshi 课时统计系统 · v1.1.4 发布报告

> 发布日期：2026-09-11
> 版本类型：功能版（含数据库 DDL，首个「安全含 DDL」的正式版本）

## 一句话结论

v1.1.4 是 **首个携带建表 DDL 的正式版**：补齐 AI 中转平台 5 张表 + 通知公告 2 张表，
同时落地 AI 中转网关「真流式（SSE）+ 多厂商通道 + 自动故障转移」。
之所以现在才敢含 DDL，是因为 **v1.1.3 垫脚石版本已把修复后的 `UpdateService` 部署到所有站点**，
它能正确处理「含 DDL 的 upgrade.sql」（DDL 隐式提交不再报错）。

## 为什么现在才含 DDL（重要背景）

- v1.0.x / v1.1.0~v1.1.2 的 `upgrade.sql` 含 7 个 `CREATE TABLE`，而执行升级的是站点上的
  **旧版** `UpdateService`（无条件 `beginTransaction` 包整份 SQL）。MySQL 的 DDL 会隐式提交，
  第 1 条 CREATE 之后事务消失，`commit()` 抛 `There is no active transaction` → 升级死锁。
- v1.1.3 是「垫脚石」：upgrade.sql 纯 DML、不含 DDL，旧 UpdateService 也能安全跑完，
  把修复后的 UpdateService 部署到位。装完 v1.1.3 的站点跑的就是修复版更新器。
- 因此 v1.1.4 的 upgrade.sql 正式携带建表语句，**且全程不依赖事务**（修复版检测到 DDL 即跳过事务）。

## 变更清单

### 功能
- **AI 中转网关真流式（SSE）**：`/v1/chat/completions` 与 `/playground/stream` 逐 chunk 流式返回，
  首包先发 `role`、末包带 `usage{points,channel_id}` 与 `[DONE]`，前端 Playground 实时累加渲染。
- **多厂商通道 + 自动故障转移**：按 `priority` 权重选启用渠道，单次请求最多尝试 `MAX_TRY_CHANNELS=3`
  个渠道，坏渠道（超时/5xx）经 `__SWITCH__<id>|<name>` 透传信号自动切下一渠道，全程只记 1 条用量日志。
- **按厂商拉取模型列表**：`AiConfig::fetchModels($url,$key,$type)` 按 type 分发 ——
  Gemini 走 `/v1beta/models?key=`；OpenAI / Claude / 通义 / DeepSeek / 自定义 走 OpenAI 兼容 `GET /models`。
- **额度精度提升至 `DECIMAL(16,6)`**：修复「扣了钱没扣额度」——旧 `DECIMAL(14,2)` 把 sub-cent 点数
  （如 0.0036）四舍五入吞掉（`1000 - 0.0036 → 1000.00`），提升到 6 位小数后扣减精确。

### 数据库（在线升级自动执行，幂等）
- 补齐 7 张表：AI 中转 5 张（`ks_ai_channel` / `ks_ai_model` / `ks_ai_token` / `ks_ai_quota` /
  `ks_ai_usage_log`）+ 通知公告 2 张（`ks_notice` / `ks_notice_read`），全部 `CREATE TABLE IF NOT EXISTS`。
- 升级包同时携带 `ks_ai_quota` / `ks_ai_usage_log` 金额列精度提升 ALTER（幂等 `MODIFY`），
  已装站点升级后自动生效，无需手工执行迁移。

## 升级须知（务必转告用户）

- **从 1.0.x / 1.1.0~1.1.2 升级：必须先升 v1.1.3 垫脚石版**，再升 v1.1.4。
  否则旧 UpdateService 遇到本版 DDL 仍会触发死锁。
- **从 ≥ 1.1.3 升级：直接点「在线更新」即可**，本版 upgrade.sql 由修复版更新器执行，建表自动完成。
- 在线更新会自动执行 `upgrade.sql`；无需手工导 SQL。

## 验证证据

| 项目 | 结果 |
| ---- | ---- |
| 修复版 `UpdateService::runSqlFromZip` 实测 v1.1.4 含 DDL 升级 | 12→**19 表**，`app_version=1.1.4`，无 `There is no active transaction` ✓ |
| 幂等重跑（模拟重复点击） | 再次成功，表数 19、`app_version=1.1.4` ✓ |
| 精度校验 | `ks_ai_quota.balance` = `decimal(16,6)` ✓ |
| PHP 语法检查（10 个改动文件 `php -l`） | 全部无语法错误 ✓ |
| 构建 zip | `keshi-1.1.4.zip` 793575 字节，sha256 `353115463004c4ff1bf2ea976b84d43bc8af705ea27b0e4e1afcb18da77650de` |
| 清单验签（CNB / GitHub 两份，RSA-SHA256） | 均有效 ✓（与生产 `config/update_trust.php` 公钥一致） |
| zip sha256 与清单 `files[].sha256` 一致 | 一致 ✓ |
| 漏出检查 | zip **不含** `config/installed.php` / `_env/` / `.workbuddy/` ✓ |

> 测试在隔离库 `ks_test_install@127.0.0.1:3399` 进行，测完已还原 12 表 / `app_version=1.1.3` 基线。

## 发布资产

| 平台 | 清单 | 下载源 | 说明 |
| ---- | ---- | ------ | ---- |
| CNB | `release/manifest.json` | `https://cnb.cool/bmayan/class-hours-tracker/-/releases/download/v1.1.4/keshi-1.1.4.zip` | 已签名 |
| GitHub | `release/manifest-github.json` | `https://github.com/leeabc-gif/Class-Hours-Tracker-/releases/download/v1.1.4/keshi-1.1.4.zip` | 已签名 |

- git tag：`v1.1.4`
- 更新包：`release/keshi-1.1.4.zip`（仅作本地/平台上传用，不入库）

## 已知限制 / 待办

- **实际 Release + 资产上传需 GitHub PAT / CNB Token**：本会话运行环境未提供 token，
  故代码与 tag 已推送，但两个平台的 Release 与资产（zip + manifest）**仍需创建/上传**——
  可由用户手动在平台界面操作，或提供 token 后由工具完成。
- `release/` 目录被 `.gitignore` 忽略，zip 不入库；`manifest.json` / `manifest-github.json` 已 `git add -f` 入库。
