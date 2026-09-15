-- v1.2.2 学生端移动布局与学情回归修复版本同步
-- 本版本不含 DDL，仅同步应用版本号，兼容已完成学生模块建表的站点。
UPDATE `ks_setting`
   SET `cfg_value` = '1.2.2'
 WHERE `cfg_key` = 'app_version'
   AND (
        `cfg_value` IN ('', '0')
     OR CONCAT(
          LPAD(SUBSTRING_INDEX(CONCAT(`cfg_value`, '.0.0'), '.', 1), 4, '0'), '.',
          LPAD(SUBSTRING_INDEX(SUBSTRING_INDEX(CONCAT(`cfg_value`, '.0.0'), '.', 2), '.', -1), 4, '0'), '.',
          LPAD(SUBSTRING_INDEX(SUBSTRING_INDEX(CONCAT(`cfg_value`, '.0.0'), '.', 3), '.', -1), 4, '0')
        ) < '0001.0002.0002'
   );

INSERT IGNORE INTO `ks_setting` (`cfg_key`, `cfg_value`, `remark`)
VALUES ('app_version', '1.2.2', '系统当前版本（在线更新维护）');
