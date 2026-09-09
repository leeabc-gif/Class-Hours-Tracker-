-- ============================================================
-- v1.0.8 升级迁移：同步版本号
-- 兼容旧版 ks_setting 表（该表没有 updated_at 字段）。
-- 本次升级无 schema 变化，仅扩展前端的节次枚举（section 1..10）
-- 并新增跨节组合 7=1-4节 / 8=2-4节 / 9=5-8节 / 10=1-8节。
-- ============================================================

UPDATE `ks_setting`
   SET `cfg_value` = '1.0.8'
 WHERE `cfg_key` = 'app_version'
   AND (CAST(`cfg_value` AS DECIMAL(10,3)) < 1.008 OR `cfg_value` IN ('', '0'));

INSERT IGNORE INTO `ks_setting` (`cfg_key`, `cfg_value`, `remark`)
VALUES ('app_version', '1.0.8', '系统当前版本（在线更新维护）');
