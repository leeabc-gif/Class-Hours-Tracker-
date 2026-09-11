-- ============================================================
-- v1.1.3 升级迁移（垫脚石版本，无 DDL）
--
-- 为什么本文件没有 CREATE TABLE：
--   v1.1.0~v1.1.2 的 upgrade.sql 含 7 个 CREATE TABLE（AI 中转 5 张表 +
--   通知公告 2 张表）。而 1.0.x 站点上运行的旧版 UpdateService
--   会把整份 SQL 包进一个事务执行，MySQL 的 DDL 会触发隐式提交，
--   第一条 CREATE 之后事务就消失，后续 commit() 会抛
--   "There is no active transaction"，整个升级失败并回滚文件。
--   这是一个鸡生蛋问题：只有新 UpdateService 才能正确执行含 DDL 的升级，
--   但新 UpdateService 必须先通过升级写进服务器。
--
--   v1.1.3 的使命就是"打破死锁"：
--   1) 本 SQL **完全不含 DDL**，旧 UpdateService 能正常在事务中跑完，
--      把所有文件（含修复后的 UpdateService 与新的前端/后端代码）
--      部署到位。
--   2) 文件部署完成后，新版 UpdateService::ensureSchemaIfNeeded()
--      会在 updateStatus 接口被首次调用时，幂等补齐缺失的 7 张表
--      （此时已经是新代码在运行，DDL 隐式提交不再是问题）。
--
-- 从 1.0.x 直升时升级路径：
--   旧代码下载 v1.1.3 包 → 备份文件 → 覆盖文件 → 跑本 SQL（纯 DML，成功）
--   → commit 成功 → 版本号写到 1.1.3 → 下次管理员进后台，新代码自动补建表
--
-- 作用：
--   1) 把默认 CNB 更新源从写死的 v1.0.8 清回空串，回落到默认 latest 入口
--      （仅清官方写死值，管理员自定义的第三方地址不动）
--   2) 同步 app_version → 1.1.3
-- ============================================================

-- 清理历史写死到具体 tag 的 CNB 默认源
UPDATE `ks_setting`
   SET `cfg_value` = ''
 WHERE `cfg_key` = 'update_manifest_url'
   AND `cfg_value` LIKE 'https://cnb.cool/bmayan/class-hours-tracker/-/releases/download/v1.%/manifest.json';

-- 同步版本号到 1.1.3（三段各补零到 4 位后字符串比较，防止 1.10.0 被降级）
UPDATE `ks_setting`
   SET `cfg_value` = '1.1.3'
 WHERE `cfg_key` = 'app_version'
   AND (
        `cfg_value` IN ('', '0')
     OR CONCAT(
          LPAD(SUBSTRING_INDEX(CONCAT(`cfg_value`, '.0.0'), '.', 1), 4, '0'), '.',
          LPAD(SUBSTRING_INDEX(SUBSTRING_INDEX(CONCAT(`cfg_value`, '.0.0'), '.', 2), '.', -1), 4, '0'), '.',
          LPAD(SUBSTRING_INDEX(SUBSTRING_INDEX(CONCAT(`cfg_value`, '.0.0'), '.', 3), '.', -1), 4, '0')
        ) < '0001.0001.0003'
   );

INSERT IGNORE INTO `ks_setting` (`cfg_key`, `cfg_value`, `remark`)
VALUES ('app_version', '1.1.3', '系统当前版本（在线更新维护）');
