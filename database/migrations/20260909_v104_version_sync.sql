-- ============================================================
-- 2026-09-09 · v1.0.4 升级
--   1) 将历史安装记录中的旧版本同步到当前发布版本
--   2) 不覆盖高于 1.0.4 的版本记录，避免误降级
-- ============================================================

UPDATE `ks_setting`
   SET `cfg_value` = '1.0.4',
       `remark` = '系统当前版本（在线更新维护）'
 WHERE `cfg_key` = 'app_version'
   AND (`cfg_value` IS NULL OR `cfg_value` = ''
        OR `cfg_value` IN ('1.0.0', '1.0.1', '1.0.2', '1.0.3'));

INSERT IGNORE INTO `ks_setting` (`cfg_key`, `cfg_value`, `remark`)
VALUES ('app_version', '1.0.4', '系统当前版本（在线更新维护）');
