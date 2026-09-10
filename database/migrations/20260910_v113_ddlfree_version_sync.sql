-- ============================================================
-- v1.1.3 升级迁移（垫脚石版本：纯 DML，不含 DDL）
--
-- 作用：
--   1) 同步 app_version → 1.1.3
--   2) 清理历史写死的 CNB 默认更新源（让代码回落到最新的 latest 入口）
--
-- 为什么是"纯 DML"？（重要）
--   从 v1.0.8 及更低版本升级时，执行升级动作的是站点上**旧版**的
--   UpdateService —— 它用 beginTransaction() 包住整份 upgrade.sql，
--   只要 SQL 里有 DDL（CREATE/ALTER/DROP/...），MySQL 就会触发隐式
--   提交，事务当场消失，随后 commit() 抛 "There is no active transaction"，
--   导致升级失败。
--
--   v1.1.3 的代码里已经包含了修复后的 UpdateService（含 DDL 不开事务、
--   commit/rollBack 前复查 inTransaction() 等），但 SQL 本身必须是纯 DML
--   才能让旧代码顺利跑完。
--
--   升到 v1.1.3 之后，再升以后的版本时就会用新的 UpdateService 执行，
--   那时升级包里含 DDL 也没问题了。
--
--   AI 中转平台 5 张表、通知公告 2 张表等结构变更，会在后续版本（或由
--   应用层惰性补齐）完成，不在本迁移里做。
-- ============================================================

-- ------------------------------------------------------------
-- v1.1.3：把「写死到具体 tag」的 CNB 默认更新源升级为 latest 入口
--
-- 仅清理"官方写死值"（形如 .../releases/download/v1.X/manifest.json），
-- 管理员自定义的第三方地址一律不动。
-- ------------------------------------------------------------
UPDATE `ks_setting`
   SET `cfg_value` = ''
 WHERE `cfg_key` = 'update_manifest_url'
   AND `cfg_value` LIKE 'https://cnb.cool/bmayan/class-hours-tracker/-/releases/download/v1.%/manifest.json';

-- 同步版本号（语义化字符串比较，避免 1.10.0 被降级改写）
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
