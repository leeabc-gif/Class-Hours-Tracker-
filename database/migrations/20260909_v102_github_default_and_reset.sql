-- ============================================================
-- 2026-09-09 · v1.0.2 升级：
--   1) ks_course 新增 is_demo 字段，标识"重置后保留的示范课程"
--   2) 默认示范课程（id=1，工业机器人导论）打上 is_demo=1
--   3) ks_setting 默认 update_source=github，update_github_token 留空
--   4) 默认 update_manifest_url 改为 GitHub Releases
--   5) 仅在 cfg_value 为空(管理员从未设置过)时才覆盖，避免冲掉用户自定义
-- ============================================================

-- 1) 加字段（幂等）
SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME   = 'ks_course'
    AND COLUMN_NAME  = 'is_demo'
);
SET @sql := IF(@col_exists = 0,
  'ALTER TABLE `ks_course` ADD COLUMN `is_demo` TINYINT(1) NOT NULL DEFAULT 0 COMMENT ''是否系统示范课程（重置示例数据时保留）'' AFTER `status`',
  'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- 2) 把 id=1 的工业机器人导论标记为示范课程
UPDATE `ks_course` SET `is_demo` = 1 WHERE `id` = 1 AND `name` = '工业机器人导论';

-- 3) 默认切到 GitHub
INSERT IGNORE INTO `ks_setting` (`cfg_key`, `cfg_value`, `remark`) VALUES
  ('update_source',       'github',  '在线更新源：github（GitHub Releases）/ cnb（CNB 官方）/ custom（自填）'),
  ('update_github_token', '',        'GitHub Personal Access Token（仅私有仓库或提升限流时填写，留空可访问公开仓库）');

-- 老用户兼容：之前是空值或没切过源的，把 update_source 设为 github
UPDATE `ks_setting` SET `cfg_value`='github' WHERE `cfg_key`='update_source' AND (`cfg_value`='' OR `cfg_value` IS NULL);

-- 4) 默认 manifest URL 改到 GitHub Releases 公开 REST API（仅当未自定义过）
--    UpdateService::check 会先 GET 该 API 拿 tag_name，再去
--    https://github.com/.../releases/download/<tag>/manifest.json 拉真正的 manifest
UPDATE `ks_setting`
   SET `cfg_value` = 'https://api.github.com/repos/leeabc-gif/Class-Hours-Tracker-/releases/latest',
       `remark`    = '在线更新清单地址，默认指向 GitHub latest release API；管理员可在「基础配置」覆盖'
 WHERE `cfg_key` = 'update_manifest_url'
   AND (`cfg_value` = '' OR `cfg_value` IS NULL);
