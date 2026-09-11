-- ============================================================
-- v1.1.4 升级迁移（首个「含 DDL」的正式版本）
--
-- 为什么本文件可以安全含 DDL：
--   v1.1.0~v1.1.2 的 upgrade.sql 含 7 个 CREATE TABLE，但执行升级的是站点上的
--   旧版 UpdateService，它用 beginTransaction() 包住整份 SQL，MySQL 的 DDL 会隐式提交，
--   第一条 CREATE 之后事务消失，commit() 抛 "There is no active transaction" → 升级失败（死锁）。
--   v1.1.3 是「垫脚石版本」：upgrade.sql 纯 DML、不含 DDL，旧 UpdateService 也能安全跑完，
--   把修复后的 UpdateService 部署到位。装完 v1.1.3 的站点跑的就是修复版 UpdateService，
--   已能正确处理「含 DDL 的 upgrade.sql」（DDL 隐式提交不再报错）。
--
-- 本文件承担被 v1.1.3 推迟的两件事：
--   1) 补齐 AI 中转平台 5 张表 + 通知公告 2 张表（CREATE TABLE IF NOT EXISTS，幂等）
--   2) 把 AI 额度/用量金额列精度统一提升到 DECIMAL(16,6)
--      （New API 风格按实际 token 倍率计费会产生 sub-cent 点数，2 位小数会被四舍五入吞掉）
--
-- 安全性要点（防止半升级）：
--   * 先 CREATE TABLE IF NOT EXISTS（16,6 精度），再 ALTER 提升精度（幂等 MODIFY）。
--     若表尚不存在（v1.1.3 站点尚未触发 ensureSchemaIfNeeded 的情况）→ CREATE 建出 16,6 表，
--     ALTER 变 no-op；若表已存在且为 14,2 → CREATE 跳过，ALTER 提升到 16,6。两种顺序均成功。
--   * 全程不依赖事务（修复版 UpdateService 检测到 DDL 会跳过事务，纯 DML 才开事务）。
-- ============================================================

-- ① 渠道：多厂商上游（OpenAI / Claude / 通义 / DeepSeek / Gemini / 自定义 …）
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

-- ② 模型与倍率：计费单价（点数 = prompt*prompt_ratio + completion*completion_ratio）
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

-- ③ API 令牌：教师拿去外部调用的 sk- 密钥（明文仅创建时展示一次）
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

-- ④ 教师额度：总余额 + 日/周/月周期限额（周期到点自动清零 used）
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

-- ⑤ 用量日志：计费、审计、统计的唯一依据
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
  KEY `idx_teacher` (`teacher_id`,`created_at`),
  KEY `idx_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='AI用量日志';

-- ⑥ 通知公告
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

-- ⑦ 公告已读回执
CREATE TABLE IF NOT EXISTS `ks_notice_read` (
  `id`        int(10) unsigned NOT NULL AUTO_INCREMENT,
  `notice_id` int(10) unsigned NOT NULL DEFAULT 0,
  `user_id`   int(10) unsigned NOT NULL DEFAULT 0,
  `read_at`   int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_nr` (`notice_id`,`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='公告已读回执';

-- ⑧ 精度提升（幂等 MODIFY）：表已存在且为旧精度时提升到 16,6；
--    上面 CREATE 已是 16,6 则此 ALTER 为 no-op，两种顺序均安全。
ALTER TABLE `ks_ai_quota`
  MODIFY COLUMN `balance`       DECIMAL(16,6) NOT NULL DEFAULT '0.000000' COMMENT '总余额点数（管理员分配）',
  MODIFY COLUMN `total_used`    DECIMAL(16,6) NOT NULL DEFAULT '0.000000' COMMENT '累计消耗',
  MODIFY COLUMN `daily_limit`   DECIMAL(16,6) NOT NULL DEFAULT '0.000000' COMMENT '日限额，0=不限',
  MODIFY COLUMN `weekly_limit`  DECIMAL(16,6) NOT NULL DEFAULT '0.000000' COMMENT '周限额，0=不限',
  MODIFY COLUMN `monthly_limit` DECIMAL(16,6) NOT NULL DEFAULT '0.000000' COMMENT '月限额，0=不限',
  MODIFY COLUMN `daily_used`    DECIMAL(16,6) NOT NULL DEFAULT '0.000000',
  MODIFY COLUMN `weekly_used`   DECIMAL(16,6) NOT NULL DEFAULT '0.000000',
  MODIFY COLUMN `monthly_used`  DECIMAL(16,6) NOT NULL DEFAULT '0.000000';

ALTER TABLE `ks_ai_usage_log`
  MODIFY COLUMN `points` DECIMAL(16,6) NOT NULL DEFAULT '0.000000' COMMENT '本次消耗点数';

-- ⑨ 同步版本号到 1.1.4（三段各补零到 4 位后字符串比较，防止 1.10.0 被降级）
UPDATE `ks_setting`
   SET `cfg_value` = '1.1.4'
 WHERE `cfg_key` = 'app_version'
   AND (
        `cfg_value` IN ('', '0')
     OR CONCAT(
          LPAD(SUBSTRING_INDEX(CONCAT(`cfg_value`, '.0.0'), '.', 1), 4, '0'), '.',
          LPAD(SUBSTRING_INDEX(SUBSTRING_INDEX(CONCAT(`cfg_value`, '.0.0'), '.', 2), '.', -1), 4, '0'), '.',
          LPAD(SUBSTRING_INDEX(SUBSTRING_INDEX(CONCAT(`cfg_value`, '.0.0'), '.', 3), '.', -1), 4, '0')
        ) < '0001.0001.0004'
   );

INSERT IGNORE INTO `ks_setting` (`cfg_key`, `cfg_value`, `remark`)
VALUES ('app_version', '1.1.4', '系统当前版本（在线更新维护）');
