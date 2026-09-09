-- ============================================================
-- v1.0.6 升级迁移：同步版本号
-- 仅将历史版本同步到 1.0.6，不覆盖更高版本。
-- ============================================================

-- 注意：ks_setting 表没有 updated_at 字段，不要写该列（否则升级时报 Unknown column）

UPDATE `ks_setting`
   SET `cfg_value` = '1.0.6'
 WHERE `cfg_key` = 'app_version'
   AND (CAST(`cfg_value` AS DECIMAL(10,3)) < 1.006 OR `cfg_value` IN ('', '0'));

INSERT INTO `ks_setting` (`cfg_key`, `cfg_value`, `remark`)
SELECT 'app_version', '1.0.6', '系统当前版本（在线更新维护）'
 WHERE NOT EXISTS (
     SELECT 1 FROM `ks_setting` WHERE `cfg_key` = 'app_version'
 );
