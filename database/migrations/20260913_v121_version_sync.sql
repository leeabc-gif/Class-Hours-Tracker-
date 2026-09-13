-- v1.2.1 维护版版本同步
-- 学生模块表结构已由 v1.2.0 完成，本版本不重复执行 DDL。
UPDATE `ks_setting`
   SET `cfg_value` = '1.2.1'
 WHERE `cfg_key` = 'app_version'
   AND (
        `cfg_value` IN ('', '0')
     OR CONCAT(
          LPAD(SUBSTRING_INDEX(CONCAT(`cfg_value`, '.0.0'), '.', 1), 4, '0'), '.',
          LPAD(SUBSTRING_INDEX(SUBSTRING_INDEX(CONCAT(`cfg_value`, '.0.0'), '.', 2), '.', -1), 4, '0'), '.',
          LPAD(SUBSTRING_INDEX(SUBSTRING_INDEX(CONCAT(`cfg_value`, '.0.0'), '.', 3), '.', -1), 4, '0')
        ) < '0001.0002.0001'
   );

INSERT IGNORE INTO `ks_setting` (`cfg_key`, `cfg_value`, `remark`)
VALUES ('app_version', '1.2.1', '系统当前版本（在线更新维护）');
