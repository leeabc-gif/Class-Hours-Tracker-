-- ============================================================
-- v1.2.0 升级迁移 —— 学生端 / 学生档案 / 学生 AI
--
-- 【自包含】站点可能从 v1.1.3 直接跳到 v1.2.0（在线更新只执行目标版本这一个
--           upgrade.sql），因此本文件先幂等补齐 v1.1.4 引入的 7 张 AI/公告表，
--           再做学生模块。已在 v1.1.4 的站点这部分 CREATE 全部 no-op。
--
-- 本版本新增（全部 CREATE TABLE IF NOT EXISTS，幂等）：
--   A. v1.1.4 的 7 表（渠道/模型/令牌/额度/用量/公告/回执）—— 保底补齐
--   B. ks_student               学生档案 + 登录账号（学号登录）
--   C. ks_student_attendance    学生出勤（按 课时×学生 唯一）
--   D. ks_student_score         学生成绩与评价
--   E. ks_student_ai_quota      学生 AI 额度（独立于教师额度）
--   F. ks_student_ai_conversation 学生 AI 答疑会话
--   G. ks_student_ai_message    学生 AI 答疑消息（内容审计）
--
-- 对既有表的幂等改造：
--   * ks_class        加 join_code（班级自助注册口令）
--   * ks_ai_usage_log 加 owner_type / student_id，source 枚举增 'student'，
--                     让学生调用与教师调用进同一张审计/计费表
--   * ks_notice.scope 枚举增 'student'，公告可对学生发布
--
-- 安全性：站点已升级到 v1.1.3+（修复版 UpdateService），本文件含 DDL
--         会被逐条执行、不再包事务，避免 MySQL DDL 隐式提交导致的死锁。
-- ============================================================

-- ========== A. 保底补齐 v1.1.4 的 7 张表（已存在则跳过） ==========

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
  `source`            enum('chat','playground','api','student') NOT NULL DEFAULT 'chat',
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
  `scope`        enum('all','teacher','admin','student') NOT NULL DEFAULT 'all' COMMENT '可见范围',
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

-- A 收尾：旧精度站点把额度/用量金额列提到 16,6（幂等 MODIFY）
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

-- ========== B~G. 学生模块 6 张新表 ==========

-- ① 学生档案 + 登录账号
CREATE TABLE IF NOT EXISTS `ks_student` (
  `id`            int(10) unsigned NOT NULL AUTO_INCREMENT,
  `sno`           varchar(32)  NOT NULL DEFAULT '' COMMENT '学号（登录账号）',
  `password`      varchar(255) NOT NULL DEFAULT '' COMMENT '密码哈希 bcrypt',
  `name`          varchar(32)  NOT NULL DEFAULT '' COMMENT '姓名',
  `gender`        tinyint(1)   NOT NULL DEFAULT 0 COMMENT '0未知 1男 2女',
  `class_id`      int(10) unsigned NOT NULL DEFAULT 0 COMMENT '所属班级 ks_class.id',
  `year`          smallint(5)  NOT NULL DEFAULT 0 COMMENT '入学年份，如 2025',
  `phone`         varchar(20)  NOT NULL DEFAULT '' COMMENT '联系电话',
  `avatar`        varchar(255) NOT NULL DEFAULT '' COMMENT '头像地址',
  `status`        tinyint(1)   NOT NULL DEFAULT 2 COMMENT '2待审核 1正常 0禁用',
  `source`        enum('import','register') NOT NULL DEFAULT 'import' COMMENT 'import批量导入 register自助注册',
  `last_login_at` int(11)      NOT NULL DEFAULT 0,
  `last_login_ip` varchar(45)  NOT NULL DEFAULT '',
  `created_at`    int(11)      NOT NULL DEFAULT 0,
  `updated_at`    int(11)      NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_sno` (`sno`),
  KEY `idx_class` (`class_id`),
  KEY `idx_status` (`status`),
  KEY `idx_year` (`year`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='学生档案与登录账号';

-- ② 学生出勤（一节 ks_lesson 对一个学生一条）
CREATE TABLE IF NOT EXISTS `ks_student_attendance` (
  `id`          int(10) unsigned NOT NULL AUTO_INCREMENT,
  `lesson_id`   int(10) unsigned NOT NULL DEFAULT 0 COMMENT '课时 ks_lesson.id',
  `student_id`  int(10) unsigned NOT NULL DEFAULT 0,
  `class_id`    int(10) unsigned NOT NULL DEFAULT 0 COMMENT '冗余班级，便于按班录入/统计',
  `status`      enum('present','absent','leave','late','early') NOT NULL DEFAULT 'present' COMMENT 'present出勤 absent缺勤 leave请假 late迟到 early早退',
  `note`        varchar(255) NOT NULL DEFAULT '' COMMENT '备注',
  `operator_id` int(10) unsigned NOT NULL DEFAULT 0 COMMENT '记录教师 ks_teacher.id',
  `created_at`  int(11)      NOT NULL DEFAULT 0,
  `updated_at`  int(11)      NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_lesson_student` (`lesson_id`,`student_id`),
  KEY `idx_student` (`student_id`),
  KEY `idx_class` (`class_id`),
  KEY `idx_lesson` (`lesson_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='学生出勤记录';

-- ③ 学生成绩与评价（score 可空：纯评语场景）
CREATE TABLE IF NOT EXISTS `ks_student_score` (
  `id`          int(10) unsigned NOT NULL AUTO_INCREMENT,
  `student_id`  int(10) unsigned NOT NULL DEFAULT 0,
  `class_id`    int(10) unsigned NOT NULL DEFAULT 0 COMMENT '冗余班级',
  `course_id`   int(10) unsigned NOT NULL DEFAULT 0,
  `course_name` varchar(64)  NOT NULL DEFAULT '' COMMENT '课程名快照',
  `term_id`     int(10) unsigned NOT NULL DEFAULT 0,
  `title`       varchar(100) NOT NULL DEFAULT '' COMMENT '考核项，如 期中实操/平时表现',
  `score`       decimal(6,2) DEFAULT NULL COMMENT '分数，NULL=仅评语',
  `grade`       varchar(16)  NOT NULL DEFAULT '' COMMENT '等级，如 优/良/中/及格',
  `comment`     varchar(1000) NOT NULL DEFAULT '' COMMENT '评语',
  `recorded_by` int(10) unsigned NOT NULL DEFAULT 0 COMMENT '录入教师',
  `created_at`  int(11)      NOT NULL DEFAULT 0,
  `updated_at`  int(11)      NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_student` (`student_id`),
  KEY `idx_class` (`class_id`),
  KEY `idx_course` (`course_id`),
  KEY `idx_term` (`term_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='学生成绩与评价';

-- ④ 学生 AI 额度（结构对齐教师 ks_ai_quota，独立余额/周期限额）
CREATE TABLE IF NOT EXISTS `ks_student_ai_quota` (
  `id`               int(10) unsigned NOT NULL AUTO_INCREMENT,
  `student_id`       int(10) unsigned NOT NULL DEFAULT 0,
  `balance`          decimal(16,6) NOT NULL DEFAULT '0.000000' COMMENT '总余额点数（管理员/教师分配）',
  `total_used`       decimal(16,6) NOT NULL DEFAULT '0.000000' COMMENT '累计消耗',
  `daily_limit`      decimal(16,6) NOT NULL DEFAULT '0.000000' COMMENT '日限额，0=不限',
  `weekly_limit`     decimal(16,6) NOT NULL DEFAULT '0.000000' COMMENT '周限额，0=不限',
  `monthly_limit`    decimal(16,6) NOT NULL DEFAULT '0.000000' COMMENT '月限额，0=不限',
  `daily_used`       decimal(16,6) NOT NULL DEFAULT '0.000000',
  `weekly_used`      decimal(16,6) NOT NULL DEFAULT '0.000000',
  `monthly_used`     decimal(16,6) NOT NULL DEFAULT '0.000000',
  `daily_reset_at`   int(11) NOT NULL DEFAULT 0,
  `weekly_reset_at`  int(11) NOT NULL DEFAULT 0,
  `monthly_reset_at` int(11) NOT NULL DEFAULT 0,
  `updated_at`       int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_student` (`student_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='学生AI额度(含日周月周期)';

-- ⑤ 学生 AI 答疑会话
CREATE TABLE IF NOT EXISTS `ks_student_ai_conversation` (
  `id`         int(10) unsigned NOT NULL AUTO_INCREMENT,
  `student_id` int(10) unsigned NOT NULL DEFAULT 0,
  `title`      varchar(100) NOT NULL DEFAULT '',
  `created_at` int(11) NOT NULL DEFAULT 0,
  `updated_at` int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_student` (`student_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='学生AI答疑会话';

-- ⑥ 学生 AI 答疑消息（留存供内容审计 / 学情画像）
CREATE TABLE IF NOT EXISTS `ks_student_ai_message` (
  `id`              int(10) unsigned NOT NULL AUTO_INCREMENT,
  `conversation_id` int(10) unsigned NOT NULL DEFAULT 0,
  `student_id`      int(10) unsigned NOT NULL DEFAULT 0 COMMENT '冗余，便于按学生隔离与审计',
  `role`            enum('user','assistant') NOT NULL DEFAULT 'user',
  `content`         text COMMENT '消息正文',
  `model`           varchar(100) NOT NULL DEFAULT '' COMMENT '本次应答使用的模型',
  `points`          decimal(16,6) NOT NULL DEFAULT '0.000000' COMMENT '本次消耗点数',
  `flagged`         tinyint(1) NOT NULL DEFAULT 0 COMMENT '审计标记 0正常 1可疑',
  `created_at`      int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_conv` (`conversation_id`),
  KEY `idx_student` (`student_id`,`created_at`),
  KEY `idx_flagged` (`flagged`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='学生AI答疑消息';

-- ⑦ ks_class 增加班级自助注册口令（幂等加列）
SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME   = 'ks_class'
    AND COLUMN_NAME  = 'join_code'
);
SET @sql := IF(@col_exists = 0,
  'ALTER TABLE `ks_class` ADD COLUMN `join_code` varchar(16) NOT NULL DEFAULT '''' COMMENT ''自助注册口令，空=不开放'' AFTER `year`',
  'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- ⑧ ks_ai_usage_log 支持学生主体（幂等加列 + 扩展枚举）
SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME   = 'ks_ai_usage_log'
    AND COLUMN_NAME  = 'owner_type'
);
SET @sql := IF(@col_exists = 0,
  'ALTER TABLE `ks_ai_usage_log` ADD COLUMN `owner_type` enum(''teacher'',''student'') NOT NULL DEFAULT ''teacher'' COMMENT ''调用主体类型'' AFTER `teacher_id`',
  'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME   = 'ks_ai_usage_log'
    AND COLUMN_NAME  = 'student_id'
);
SET @sql := IF(@col_exists = 0,
  'ALTER TABLE `ks_ai_usage_log` ADD COLUMN `student_id` int(10) unsigned NOT NULL DEFAULT 0 COMMENT ''owner_type=student 时的学生ID'' AFTER `owner_type`',
  'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- 学生调用来源并入审计枚举（MODIFY 幂等）
ALTER TABLE `ks_ai_usage_log`
  MODIFY COLUMN `source` enum('chat','playground','api','student') NOT NULL DEFAULT 'chat';

-- ⑨ 公告可对学生发布（MODIFY 幂等）
ALTER TABLE `ks_notice`
  MODIFY COLUMN `scope` enum('all','teacher','admin','student') NOT NULL DEFAULT 'all' COMMENT '可见范围';

-- ⑩ 同步版本号到 1.2.0
UPDATE `ks_setting`
   SET `cfg_value` = '1.2.0'
 WHERE `cfg_key` = 'app_version'
   AND (
        `cfg_value` IN ('', '0')
     OR CONCAT(
          LPAD(SUBSTRING_INDEX(CONCAT(`cfg_value`, '.0.0'), '.', 1), 4, '0'), '.',
          LPAD(SUBSTRING_INDEX(SUBSTRING_INDEX(CONCAT(`cfg_value`, '.0.0'), '.', 2), '.', -1), 4, '0'), '.',
          LPAD(SUBSTRING_INDEX(SUBSTRING_INDEX(CONCAT(`cfg_value`, '.0.0'), '.', 3), '.', -1), 4, '0')
        ) < '0001.0002.0000'
   );

INSERT IGNORE INTO `ks_setting` (`cfg_key`, `cfg_value`, `remark`)
VALUES ('app_version', '1.2.0', '系统当前版本（在线更新维护）');
