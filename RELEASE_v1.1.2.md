# v1.1.2 发布报告 — 后台更新页改造为「自动检查 + 一键更新」

- **版本**：v1.1.2
- **发布时间**：2026-09-10
- **CNB Release**：`2097939384560844800`（tag `v1.1.2`，指向 commit `9fce6e8`）
- **更新包**：`keshi-1.1.2.zip`，766,645 字节
- **SHA-256**：`81a2b3e7d2ddf9225bdf14c47151f14e50a55b9a53980bb7e5c63796d45e1ae6`

---

## 一、需求来源

用户提供了另一套系统后台的更新界面截图，形态是：

> 顶部一条「有新版本可用！v0.2.4」横幅，下方一个大号「立即更新」按钮，点一下就升级。

用户的问题是："网站能不能这样直接点击更新"。

本版即为此改造。

---

## 二、改造前的问题

| # | 问题 | 具体表现 |
|---|------|----------|
| 1 | 必须手动点「检查更新」 | 进入「系统更新」页只显示当前版本，不主动查有没有新版 |
| 2 | 三个按钮平铺、无主次 | 「检查更新 / 下载并安装 / 回滚」并列，用户不知道该点哪个 |
| 3 | 强制填写清单地址 | 输入框为空时前端直接拦截，提示"请先填写更新清单地址" |
| 4 | **默认更新源写死在 v1.0.8** | 这是最致命的一条 —— 老站点无论怎么点检查更新，永远只能看到 v1.0.8，后续版本一个都发现不了 |

---

## 三、修复方案

### 3.1 后端（`application/api/controller/Admin.php`）

**默认源改回 latest 入口**

```php
public static function defaultCnbManifestUrl()
{
    return 'https://cnb.cool/bmayan/class-hours-tracker/-/releases/latest/download/manifest.json';
}
```

`UpdateService::resolveCnbLatest()` 早已具备解析能力（拉 Release 列表页 HTML，正则提取所有
`-/releases/(tag|download)/vX.Y.Z`，按 `version_compare` 取最大），只是默认源没用上它。

**新增 `?auto=1` 静默自动检查**

```php
if ((string) $this->input('auto', '') === '1') {
    $this->autoCheckIfStale();
}
```

`autoCheckIfStale($ttlSeconds = 21600)`：距上次检查超过 6 小时（或从未检查）才请求远端，
避免每次进页面都打外网；异常全部吞掉、只写 `last_check_error`，绝不影响状态接口本身返回。

**新增返回 `update_available`**

```php
$updateAvailable = false;
if ($cachedLatest !== '' && $current !== '') {
    $updateAvailable = version_compare($cachedLatest, $current, '>');
}
```

版本比较统一由后端 `version_compare` 完成，前端不再自己比字符串。

### 3.2 前端（`public/static/app/app.js`）

- 版本号做成居中主卡片
- 新增 `udNewBox`：橙色「有新版本可用！vX.Y.Z」横幅 + 大号「立即更新」按钮
- 新增 `udOkBox`：绿色「已是最新版本」
- 原三个按钮收进 `udAdv` 折叠区（`App.updateToggleAdv()` 控制展开）
- 输入框改为只回填 `manifest_url_custom`，留空即用系统默认源
- `updateCheck` / `updateInstall` 去掉"请先填写更新清单地址"的前端拦截

顺带修一个隐蔽 bug：`setUdBusy` 原用 `querySelector`，同名动作有多个按钮时只有第一个进入加载态，
改为 `querySelectorAll` 全量处理。

### 3.3 数据库（`database/migrations/20260910_v112_version_sync.sql`）

清理历史写死的 CNB 源，只清官方值、不误伤管理员自定义地址：

```sql
UPDATE `ks_setting`
   SET `cfg_value` = ''
 WHERE `cfg_key` = 'update_manifest_url'
   AND `cfg_value` LIKE 'https://cnb.cool/bmayan/class-hours-tracker/-/releases/download/v1.%/manifest.json';
```

同步 `app_version` → `1.1.2`（阈值 `< '0001.0001.0002'`）；
幂等补齐 AI 中转平台 5 张表与通知公告 2 张表，兼容 1.0.x 老站直升。

---

## 四、验证矩阵

### 4.1 发布前

| 项 | 结果 |
|----|------|
| jsdom 前端渲染（5 场景） | 16/16 断言通过 |
| 清理 SQL 用例 | 7/7 通过（官方写死值清空、自定义/他仓库地址保留） |
| 版本同步用例 | 11/11 通过（`1.10.0`/`2.0.0` 不被降级改写） |
| PHP 语法检查 | `Admin.php` 无错误 |
| 发布包条目数 | 404 项 |
| 泄漏检查 | 无 `_` 前缀项、无 `installed.php`、无 `.pem`、无 hotfix 残留 |
| 包内 `config/app.php` | `version = 1.1.2` |
| 包内改造标记 | `autoCheckIfStale`×2、`update_available`×2、`releases/latest/download`×1、`udNewBox`×2、`udOkBox`×2、`updateToggleAdv`×2 |
| 本地清单验签 | RSA-SHA256 通过 |

### 4.2 线上校验（发布后实测）

| 项 | 结果 |
|----|------|
| `manifest.json` | HTTP 200，3,179 字节 |
| `keshi-1.1.2.zip` | HTTP 200，766,645 字节 |
| 下载后实测 sha256 | `81a2b3e7…` — **与清单一致** |
| 线上清单验签 | RSA-SHA256 通过 |
| Release 列表候选 tag | `v1.0.7 / v1.0.8 / v1.1.0 / v1.1.1 / v1.1.2` |
| `resolveCnbLatest()` 解析结果 | `v1.1.2`（用真实生产代码跑通） |
| 解析出的清单地址 | `.../releases/download/v1.1.2/manifest.json`（即上面 HTTP 200 的地址） |

### 4.3 `update_available` 逐版本核对

| 站点当前版本 | update_available | 界面表现 |
|---|---|---|
| 1.0.0 | true | 显示「立即更新」 |
| 1.0.8 | true | 显示「立即更新」 |
| 1.1.0 | true | 显示「立即更新」 |
| 1.1.1 | true | 显示「立即更新」 |
| 1.1.2 | false | 显示「已是最新」 |
| 1.10.0 | false | 显示「已是最新」（未被误判） |
| 2.0.0 | false | 显示「已是最新」 |

---

## 五、升级须知（重要）

**执行升级动作的是站点上的旧代码**，而旧代码的默认更新源仍然写死在 v1.0.8。
因此 v1.1.1 及更早版本的站点，无法自动发现 v1.1.2。

需要在后台手工操作一次：

> 「系统设置 → 在线更新源」填入：
> `https://cnb.cool/bmayan/class-hours-tracker/-/releases/latest/download/manifest.json`

保存后点「检查更新」即可看到 v1.1.2，正常安装。

**升到 v1.1.2 之后**，迁移脚本会把这个写死的旧源清空，默认源改为 latest 入口 ——
从此以后进「系统更新」页就会自动检查、有新版直接点「立即更新」，不需要再碰这个地址。

---

## 六、提交记录

| Commit | 说明 |
|--------|------|
| `293b9da` | feat(update): 后台更新页改造为自动检查+一键更新；默认CNB源改回latest入口 |
| `9fce6e8` | chore(release): v1.1.2 签名清单 |

main 与 tag `v1.1.2` 均已推送至 CNB。

> 推送注意：本机 `credential.helper = helper-selector` 在无 TTY 环境拿不到凭据，
> `git push` 会零输出假死。必须用
> `git -c credential.helper= push "https://cnb:<TOKEN>@cnb.cool/..." main`。
