-- 教师个人 AI 配置迁移
-- 已存在目标表时跳过，适用于 MySQL 5.7+ / MariaDB 10.2+
CREATE TABLE IF NOT EXISTS `ks_teacher_ai_config` (
  `id`         int(10) unsigned NOT NULL AUTO_INCREMENT,
  `teacher_id` int(10) unsigned NOT NULL DEFAULT 0 COMMENT '教师ID，按教师隔离',
  `enabled`    tinyint(1) NOT NULL DEFAULT 1 COMMENT '教师个人 AI 开关',
  `source`     enum('system','custom') NOT NULL DEFAULT 'system' COMMENT 'system=系统模型，custom=自定义模型',
  `api_url`    varchar(500) NOT NULL DEFAULT '' COMMENT '自定义模型接口地址',
  `api_key`    text COMMENT '加密保存的自定义模型 API Key',
  `model`      varchar(100) NOT NULL DEFAULT '' COMMENT '自定义模型名称',
  `created_at` int(11) NOT NULL DEFAULT 0,
  `updated_at` int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_teacher` (`teacher_id`),
  KEY `idx_enabled` (`enabled`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='教师个人 AI 配置';
