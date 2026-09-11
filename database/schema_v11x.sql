-- v1.1.x 所需新增表（AI 中转平台 5 张 + 通知公告 2 张）
-- 幂等：全部用 CREATE TABLE IF NOT EXISTS，已存在则跳过
-- 此文件由 UpdateService::ensureSchemaIfNeeded() 在新代码就位后读取执行，
-- 不会出现在 upgrade.sql 里，避免旧代码在事务中执行 DDL 导致
-- "There is no active transaction"。

CREATE TABLE IF NOT EXISTS `ks_ai_channel` (
  `id`         int(10) unsigned NOT NULL AUTO_INCREMENT,
  `name`       varchar(64)  NOT NULL DEFAULT '' COMMENT '渠道名，如 OpenAI-主用',
  `type`       enum('openai','claude','qwen','deepseek','gemini','custom') NOT NULL DEFAULT 'openai',
  `base_url`   varchar(500) NOT NULL DEFAULT '' COMMENT '如 https://api.openai.com/v1',
  `api_key`    text COMMENT 'AES-256-CBC 加密存储（v1: 前缀）',
  `models`     text COMMENT '该渠道可用模型 JSON 数组，空=不限制',
  `priority`   int(11) NOT NULL DEFAULT 10 COMMENT '权重，数值越大越优先',
  `status`     tinyint(1) NOT NULL DEFAULT 1 COMMENT '1启用 0禁用',
  `last_ok_at` int(11) NOT NULL DEFAULT 0 COMMENT '最近一次成功时间',
  `last_err`   varchar(500) NOT NULL DEFAULT '' COMMENT '最近一次失败原因',
  `created_at` int(11) NOT NULL DEFAULT 0,
  `updated_at` int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_status` (`status`,`priority`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='AI渠道(多厂商)';

CREATE TABLE IF NOT EXISTS `ks_ai_model` (
  `id`               int(10) unsigned NOT NULL AUTO_INCREMENT,
  `model_key`        varchar(100) NOT NULL DEFAULT '' COMMENT '模型标识，如 gpt-4o-mini',
  `display_name`     varchar(100) NOT NULL DEFAULT '' COMMENT '显示名',
  `prompt_ratio`     decimal(10,4) NOT NULL DEFAULT '1.0000' COMMENT '输入 token 倍率',
  `completion_ratio` decimal(10,4) NOT NULL DEFAULT '1.0000' COMMENT '输出 token 倍率',
  `enabled`          tinyint(1) NOT NULL DEFAULT 1,
  `created_at`       int(11) NOT NULL DEFAULT 0,
  `updated_at`       int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_model` (`model_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='AI模型与计费倍率';

CREATE TABLE IF NOT EXISTS `ks_ai_token` (
  `id`           int(10) unsigned NOT NULL AUTO_INCREMENT,
  `teacher_id`   int(10) unsigned NOT NULL DEFAULT 0 COMMENT '所属教师',
  `name`         varchar(64)  NOT NULL DEFAULT '' COMMENT '令牌备注名',
  `token_prefix` varchar(16)  NOT NULL DEFAULT '' COMMENT '展示用前缀，如 sk-a1b2',
  `token_hash`   varchar(64)  NOT NULL DEFAULT '' COMMENT 'sha256(明文)，不可逆',
  `model_limit`  text COMMENT '允许的模型 JSON 数组，空=不限',
  `status`       tinyint(1) NOT NULL DEFAULT 1 COMMENT '1启用 0吊销',
  `expires_at`   int(11) NOT NULL DEFAULT 0 COMMENT '过期时间戳，0=永不过期',
  `last_used_at` int(11) NOT NULL DEFAULT 0,
  `created_at`   int(11) NOT NULL DEFAULT 0,
  `updated_at`   int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_hash` (`token_hash`),
  KEY `idx_teacher` (`teacher_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='教师API令牌(sk-)';

CREATE TABLE IF NOT EXISTS `ks_ai_quota` (
  `id`               int(10) unsigned NOT NULL AUTO_INCREMENT,
  `teacher_id`       int(10) unsigned NOT NULL DEFAULT 0,
  `balance`          decimal(16,6) NOT NULL DEFAULT '0.000000' COMMENT '总余额点数（管理员分配）',
  `total_used`       decimal(16,6) NOT NULL DEFAULT '0.000000' COMMENT '累计消耗',
  `daily_limit`      decimal(16,6) NOT NULL DEFAULT '0.000000' COMMENT '日限额，0=不限',
  `weekly_limit`     decimal(16,6) NOT NULL DEFAULT '0.000000' COMMENT '周限额，0=不限',
  `monthly_limit`    decimal(16,6) NOT NULL DEFAULT '0.000000' COMMENT '月限额，0=不限',
  `daily_used`       decimal(16,6) NOT NULL DEFAULT '0.000000',
  `weekly_used`      decimal(16,6) NOT NULL DEFAULT '0.000000',
  `monthly_used`     decimal(16,6) NOT NULL DEFAULT '0.000000',
  `daily_reset_at`   int(11) NOT NULL DEFAULT 0 COMMENT '下次日重置时间',
  `weekly_reset_at`  int(11) NOT NULL DEFAULT 0 COMMENT '下次周重置时间',
  `monthly_reset_at` int(11) NOT NULL DEFAULT 0 COMMENT '下次月重置时间',
  `updated_at`       int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_teacher` (`teacher_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='教师AI额度(含日周月周期)';

CREATE TABLE IF NOT EXISTS `ks_ai_usage_log` (
  `id`                int(10) unsigned NOT NULL AUTO_INCREMENT,
  `teacher_id`        int(10) unsigned NOT NULL DEFAULT 0,
  `token_id`          int(10) unsigned NOT NULL DEFAULT 0 COMMENT '0=站内调用',
  `channel_id`        int(10) unsigned NOT NULL DEFAULT 0,
  `model`             varchar(100) NOT NULL DEFAULT '',
  `prompt_tokens`     int(11) NOT NULL DEFAULT 0,
  `completion_tokens` int(11) NOT NULL DEFAULT 0,
  `points`            decimal(16,6) NOT NULL DEFAULT '0.000000' COMMENT '本次消耗点数',
  `latency_ms`        int(11) NOT NULL DEFAULT 0,
  `status`            tinyint(1) NOT NULL DEFAULT 1 COMMENT '1成功 0失败',
  `error_msg`         varchar(500) NOT NULL DEFAULT '',
  `source`            enum('chat','playground','api') NOT NULL DEFAULT 'chat',
  `ip`                varchar(45) NOT NULL DEFAULT '',
  `created_at`        int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_teacher_time` (`teacher_id`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='AI用量日志';

CREATE TABLE IF NOT EXISTS `ks_notice` (
  `id`           int(10) unsigned NOT NULL AUTO_INCREMENT,
  `title`        varchar(200) NOT NULL DEFAULT '',
  `content`      text COMMENT '正文（纯文本或简单 HTML）',
  `type`         enum('notice','announce') NOT NULL DEFAULT 'notice' COMMENT 'notice=通知 announce=公告',
  `scope`        enum('all','teacher','admin') NOT NULL DEFAULT 'all' COMMENT '可见范围',
  `pinned`       tinyint(1) NOT NULL DEFAULT 0 COMMENT '1置顶',
  `publisher_id` int(10) unsigned NOT NULL DEFAULT 0,
  `status`       tinyint(1) NOT NULL DEFAULT 1 COMMENT '1发布 0草稿/下架',
  `created_at`   int(11) NOT NULL DEFAULT 0,
  `updated_at`   int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='通知公告';

CREATE TABLE IF NOT EXISTS `ks_notice_read` (
  `id`        int(10) unsigned NOT NULL AUTO_INCREMENT,
  `notice_id` int(10) unsigned NOT NULL DEFAULT 0,
  `user_id`   int(10) unsigned NOT NULL DEFAULT 0,
  `read_at`   int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_nr` (`notice_id`,`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='公告已读回执';
