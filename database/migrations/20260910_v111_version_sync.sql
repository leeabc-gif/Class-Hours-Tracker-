-- ============================================================
-- v1.1.1 升级迁移
-- 作用：
--   1) 修复在线更新报 "There is no active transaction" 的致命 bug
--      （代码修复在 application/common/service/UpdateService.php，
--        本 SQL 无需为此做任何变更）
--   2) 兼容「从 1.0.x 老站直升 1.1.1」：幂等补齐 AI 中转平台（5 张表）
--      与通知公告（2 张表）；已存在则不重建。
--   3) 同步 app_version → 1.1.1
--
-- 注意（重要）：下面的 CREATE TABLE 属 DDL，MySQL 执行 DDL 会隐式提交事务。
--   v1.1.1 起 UpdateService 已改为「含 DDL 时不再开事务」并在
--   commit/rollBack 前复查 inTransaction()，故不会再抛
--   "There is no active transaction"。请勿把本文件的 DDL 手工包进事务。
-- 课表导入（CSV/Excel）复用已有 ks_lesson，无需新表。
-- ============================================================

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
  `balance`          decimal(14,2) NOT NULL DEFAULT '0.00' COMMENT '总余额点数（管理员分配）',
  `total_used`       decimal(14,2) NOT NULL DEFAULT '0.00' COMMENT '累计消耗',
  `daily_limit`      decimal(12,2) NOT NULL DEFAULT '0.00' COMMENT '日限额，0=不限',
  `weekly_limit`     decimal(12,2) NOT NULL DEFAULT '0.00' COMMENT '周限额，0=不限',
  `monthly_limit`    decimal(12,2) NOT NULL DEFAULT '0.00' COMMENT '月限额，0=不限',
  `daily_used`       decimal(12,2) NOT NULL DEFAULT '0.00',
  `weekly_used`      decimal(12,2) NOT NULL DEFAULT '0.00',
  `monthly_used`     decimal(12,2) NOT NULL DEFAULT '0.00',
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
  `points`            decimal(12,4) NOT NULL DEFAULT '0.0000' COMMENT '本次消耗点数',
  `latency_ms`        int(11) NOT NULL DEFAULT 0,
  `status`            tinyint(1) NOT NULL DEFAULT 1 COMMENT '1成功 0失败',
  `error_msg`         varchar(500) NOT NULL DEFAULT '',
  `source`            enum('chat','playground','api') NOT NULL DEFAULT 'chat',
  `ip`                varchar(45) NOT NULL DEFAULT '',
  `created_at`        int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_teacher` (`teacher_id`,`created_at`),
  KEY `idx_created` (`created_at`)
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

-- 同步版本号
-- 注意：历史版本用 CAST(cfg_value AS DECIMAL) 比较是错的——MySQL 只取到第一个
--   小数点，'1.0.8'→1.000、'1.1.1'/'1.1.2'/'1.10.0' 全部→1.100，
--   会导致「已是更高版本的站被降级改写」。这里改用三段各补零到 4 位后
--   做字符串比较，保证只在「当前版本确实低于 1.1.1」时才写。
UPDATE `ks_setting`
   SET `cfg_value` = '1.1.1'
 WHERE `cfg_key` = 'app_version'
   AND (
        `cfg_value` IN ('', '0')
     OR CONCAT(
          LPAD(SUBSTRING_INDEX(CONCAT(`cfg_value`, '.0.0'), '.', 1), 4, '0'), '.',
          LPAD(SUBSTRING_INDEX(SUBSTRING_INDEX(CONCAT(`cfg_value`, '.0.0'), '.', 2), '.', -1), 4, '0'), '.',
          LPAD(SUBSTRING_INDEX(SUBSTRING_INDEX(CONCAT(`cfg_value`, '.0.0'), '.', 3), '.', -1), 4, '0')
        ) < '0001.0001.0001'
   );

INSERT IGNORE INTO `ks_setting` (`cfg_key`, `cfg_value`, `remark`)
VALUES ('app_version', '1.1.1', '系统当前版本（在线更新维护）');
