-- ============================================================
-- v1.0.7 升级迁移：同步版本号
-- 兼容旧版 ks_setting 表（该表没有 updated_at 字段）。
-- ============================================================

UPDATE `ks_setting`
   SET `cfg_value` = '1.0.7'
 WHERE `cfg_key` = 'app_version'
   AND (CAST(`cfg_value` AS DECIMAL(10,3)) < 1.007 OR `cfg_value` IN ('', '0'));

INSERT IGNORE INTO `ks_setting` (`cfg_key`, `cfg_value`, `remark`)
VALUES ('app_version', '1.0.7', '系统当前版本（在线更新维护）');
