-- ============================================================
-- 多教师课时统计与课酬核算系统 —— 数据库结构
-- 适用：MySQL 5.7+ / MariaDB 10.2+
-- 引擎：InnoDB，字符集：utf8mb4
-- ============================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ----------------------------
-- 1. 院系
-- ----------------------------
DROP TABLE IF EXISTS `ks_department`;
CREATE TABLE `ks_department` (
  `id`          int(10) unsigned NOT NULL AUTO_INCREMENT,
  `name`        varchar(64)  NOT NULL DEFAULT '' COMMENT '院系名称',
  `sort`        int(11)      NOT NULL DEFAULT 0 COMMENT '排序',
  `status`      tinyint(1)   NOT NULL DEFAULT 1 COMMENT '1启用 0停用',
  `created_at`  int(11)      NOT NULL DEFAULT 0,
  `updated_at`  int(11)      NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='院系';

-- ----------------------------
-- 2. 教师档案 / 登录账号（管理员与教师共用）
-- ----------------------------
DROP TABLE IF EXISTS `ks_teacher`;
CREATE TABLE `ks_teacher` (
  `id`            int(10) unsigned NOT NULL AUTO_INCREMENT,
  `username`      varchar(32)  NOT NULL DEFAULT '' COMMENT '登录账号',
  `password`      varchar(255) NOT NULL DEFAULT '' COMMENT '密码哈希 bcrypt',
  `name`          varchar(32)  NOT NULL DEFAULT '' COMMENT '姓名',
  `department_id` int(10) unsigned NOT NULL DEFAULT 0 COMMENT '所属院系',
  `position`      varchar(32)  NOT NULL DEFAULT '' COMMENT '岗位',
  `role`          enum('admin','teacher') NOT NULL DEFAULT 'teacher' COMMENT '角色',
  `status`        tinyint(1)   NOT NULL DEFAULT 1 COMMENT '1启用 0禁用',
  `last_login_at` int(11)      NOT NULL DEFAULT 0,
  `last_login_ip` varchar(45)  NOT NULL DEFAULT '',
  `created_at`    int(11)      NOT NULL DEFAULT 0,
  `updated_at`    int(11)      NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_username` (`username`),
  KEY `idx_dept` (`department_id`),
  KEY `idx_role` (`role`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='教师档案与登录账号';

-- ----------------------------
-- 3. 课程库
--    teacher_id = 0  => 全校公共课程（管理员维护）
--    teacher_id > 0  => 该教师的私有课程（仅本人可见）
-- ----------------------------
DROP TABLE IF EXISTS `ks_course`;
CREATE TABLE `ks_course` (
  `id`          int(10) unsigned NOT NULL AUTO_INCREMENT,
  `teacher_id`  int(10) unsigned NOT NULL DEFAULT 0 COMMENT '归属教师，0=全校公共',
  `name`        varchar(64)  NOT NULL DEFAULT '' COMMENT '课程名称',
  `classes`     varchar(255) NOT NULL DEFAULT '' COMMENT '默认授课班级，逗号分隔',
  `price`       decimal(10,2) NOT NULL DEFAULT 0.00 COMMENT '课时单价（元/节）',
  `status`      tinyint(1)   NOT NULL DEFAULT 1,
  `created_at`  int(11)      NOT NULL DEFAULT 0,
  `updated_at`  int(11)      NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_teacher` (`teacher_id`),
  KEY `idx_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='课程库';

-- ----------------------------
-- 4. 常用课程收藏（按教师隔离）
-- ----------------------------
DROP TABLE IF EXISTS `ks_course_favorite`;
CREATE TABLE `ks_course_favorite` (
  `id`         int(10) unsigned NOT NULL AUTO_INCREMENT,
  `teacher_id` int(10) unsigned NOT NULL DEFAULT 0,
  `course_id`  int(10) unsigned NOT NULL DEFAULT 0,
  `created_at` int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_tc` (`teacher_id`,`course_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='教师常用课程收藏';

-- ----------------------------
-- 5. 学期配置
-- ----------------------------
DROP TABLE IF EXISTS `ks_term`;
CREATE TABLE `ks_term` (
  `id`         int(10) unsigned NOT NULL AUTO_INCREMENT,
  `name`       varchar(64) NOT NULL DEFAULT '' COMMENT '学期名称',
  `start_week` int(11) NOT NULL DEFAULT 1 COMMENT '开学周',
  `end_week`   int(11) NOT NULL DEFAULT 20 COMMENT '结束周',
  `start_date` date DEFAULT NULL COMMENT '第1周周一日期，用于推算授课日期',
  `is_current` tinyint(1) NOT NULL DEFAULT 0 COMMENT '是否当前学期',
  `created_at` int(11) NOT NULL DEFAULT 0,
  `updated_at` int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_current` (`is_current`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='学期配置';

-- ----------------------------
-- 5b. 班级（管理员维护，录入时多选）
--     班级归属院系，可按年级/状态筛选
--     课时表 ks_lesson.classes 仍是文本快照（多班用逗号分隔），
--     此表只为录入时提供选项池 + 统一命名
-- ----------------------------
DROP TABLE IF EXISTS `ks_class`;
CREATE TABLE `ks_class` (
  `id`            int(10) unsigned NOT NULL AUTO_INCREMENT,
  `name`          varchar(64)  NOT NULL DEFAULT '' COMMENT '班级名称',
  `department_id` int(10) unsigned NOT NULL DEFAULT 0 COMMENT '所属院系',
  `year`          smallint(5)  NOT NULL DEFAULT 0 COMMENT '入学年份，如 2023',
  `join_code`     varchar(16)  NOT NULL DEFAULT '' COMMENT '学生自助注册口令，空=不开放',
  `status`        tinyint(1)   NOT NULL DEFAULT 1 COMMENT '1启用 0停用',
  `sort`          int(11)      NOT NULL DEFAULT 0 COMMENT '排序',
  `remark`        varchar(255) NOT NULL DEFAULT '',
  `created_at`    int(11)      NOT NULL DEFAULT 0,
  `updated_at`    int(11)      NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_name` (`name`),
  KEY `idx_dept` (`department_id`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='班级';

-- ----------------------------
-- 6. 课时记录（核心表）
--    section: 1..6 分别对应 1-2节 / 3-4节 / 5-6节 / 7-8节 / 9-10节 / 11-12节
--    type:    normal 常规课 / makeup 补课 / swap 调课 / training 实训课
-- ----------------------------
DROP TABLE IF EXISTS `ks_lesson`;
CREATE TABLE `ks_lesson` (
  `id`          int(10) unsigned NOT NULL AUTO_INCREMENT,
  `teacher_id`  int(10) unsigned NOT NULL DEFAULT 0 COMMENT '授课教师（数据隔离主键）',
  `term_id`     int(10) unsigned NOT NULL DEFAULT 0 COMMENT '学期',
  `course_id`   int(10) unsigned NOT NULL DEFAULT 0 COMMENT '课程ID',
  `course_name` varchar(64)  NOT NULL DEFAULT '' COMMENT '课程名快照，防改价串账',
  `classes`     varchar(255) NOT NULL DEFAULT '' COMMENT '授课班级，逗号分隔',
  `week`        int(11)      NOT NULL DEFAULT 0 COMMENT '授课周',
  `weekday`     int(11)      NOT NULL DEFAULT 0 COMMENT '星期 1-7',
  `section`     int(11)      NOT NULL DEFAULT 0 COMMENT '节次 1-6',
  `teach_date`  date DEFAULT NULL COMMENT '实际授课日期',
  `periods`     decimal(5,1) NOT NULL DEFAULT 2.0 COMMENT '上课节数',
  `price`       decimal(10,2) NOT NULL DEFAULT 0.00 COMMENT '单价快照（元/节）',
  `amount`      decimal(10,2) NOT NULL DEFAULT 0.00 COMMENT '课时费用 = periods * price',
  `type`        enum('normal','makeup','swap','training') NOT NULL DEFAULT 'normal' COMMENT '上课类型',
  `remark`      varchar(500) NOT NULL DEFAULT '',
  `source`      varchar(16)  NOT NULL DEFAULT 'manual' COMMENT 'manual手动 / batch批量 / ai智能解析 / scan扫码 / import课程表导入',
  `deleted_at`  int(11)      NOT NULL DEFAULT 0 COMMENT '软删除时间戳，0=正常',
  `created_at`  int(11)      NOT NULL DEFAULT 0,
  `updated_at`  int(11)      NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_teacher` (`teacher_id`),
  KEY `idx_term` (`term_id`),
  KEY `idx_course` (`course_id`),
  KEY `idx_dup` (`teacher_id`,`term_id`,`week`,`weekday`,`section`),
  KEY `idx_date` (`teach_date`),
  KEY `idx_deleted` (`deleted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='课时记录';

-- ----------------------------
-- 7. 操作日志
-- ----------------------------
DROP TABLE IF EXISTS `ks_operation_log`;
CREATE TABLE `ks_operation_log` (
  `id`          int(10) unsigned NOT NULL AUTO_INCREMENT,
  `user_id`     int(10) unsigned NOT NULL DEFAULT 0,
  `user_name`   varchar(32) NOT NULL DEFAULT '' COMMENT '操作人姓名快照',
  `action`      varchar(24) NOT NULL DEFAULT '' COMMENT 'create/update/delete/restore/batch/ai_import/login/logout',
  `target_type` varchar(24) NOT NULL DEFAULT '' COMMENT 'lesson/course/teacher/term/setting/system',
  `target_id`   int(10) unsigned NOT NULL DEFAULT 0,
  `summary`     varchar(1000) NOT NULL DEFAULT '' COMMENT '操作摘要',
  `ip`          varchar(45) NOT NULL DEFAULT '',
  `created_at`  int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_user` (`user_id`),
  KEY `idx_created` (`created_at`),
  KEY `idx_target` (`target_type`,`target_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='操作日志';

-- ----------------------------
-- 8. AI 会话（按用户隔离）
-- ----------------------------
DROP TABLE IF EXISTS `ks_ai_conversation`;
CREATE TABLE `ks_ai_conversation` (
  `id`         int(10) unsigned NOT NULL AUTO_INCREMENT,
  `user_id`    int(10) unsigned NOT NULL DEFAULT 0,
  `title`      varchar(100) NOT NULL DEFAULT '',
  `created_at` int(11) NOT NULL DEFAULT 0,
  `updated_at` int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='AI会话';

-- ----------------------------
-- 9. AI 消息
-- ----------------------------
DROP TABLE IF EXISTS `ks_ai_message`;
CREATE TABLE `ks_ai_message` (
  `id`              int(10) unsigned NOT NULL AUTO_INCREMENT,
  `conversation_id` int(10) unsigned NOT NULL DEFAULT 0,
  `user_id`         int(10) unsigned NOT NULL DEFAULT 0 COMMENT '冗余，便于按用户隔离与统计',
  `role`            enum('user','assistant') NOT NULL DEFAULT 'user',
  `intent`          varchar(32) NOT NULL DEFAULT '' COMMENT 'parse/evaluate/qa/proof/chat',
  `content`         text COMMENT '消息正文',
  `payload`         text COMMENT '结构化数据 JSON（如解析预览表）',
  `created_at`      int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_conv` (`conversation_id`),
  KEY `idx_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='AI消息';

-- ----------------------------
-- 10. 教师个人 AI 配置
-- ----------------------------
DROP TABLE IF EXISTS `ks_teacher_ai_config`;
CREATE TABLE `ks_teacher_ai_config` (
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

-- ----------------------------
-- 11. 系统配置
-- ----------------------------
DROP TABLE IF EXISTS `ks_setting`;
CREATE TABLE `ks_setting` (
  `id`     int(10) unsigned NOT NULL AUTO_INCREMENT,
  `cfg_key`   varchar(50) NOT NULL DEFAULT '' COMMENT '配置键',
  `cfg_value` text COMMENT '配置值',
  `remark` varchar(200) NOT NULL DEFAULT '',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_key` (`cfg_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='系统配置';

-- ----------------------------
-- 12. AI 渠道（多厂商上游）
-- ----------------------------
DROP TABLE IF EXISTS `ks_ai_channel`;
CREATE TABLE `ks_ai_channel` (
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

-- ----------------------------
-- 13. AI 模型与计费倍率
-- ----------------------------
DROP TABLE IF EXISTS `ks_ai_model`;
CREATE TABLE `ks_ai_model` (
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

-- ----------------------------
-- 14. 教师 API 令牌（sk-，供外部调用）
-- ----------------------------
DROP TABLE IF EXISTS `ks_ai_token`;
CREATE TABLE `ks_ai_token` (
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

-- ----------------------------
-- 15. 教师 AI 额度（总余额 + 日/周/月周期限额）
-- ----------------------------
DROP TABLE IF EXISTS `ks_ai_quota`;
CREATE TABLE `ks_ai_quota` (
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

-- ----------------------------
-- 16. AI 用量日志（计费与审计依据）
-- ----------------------------
DROP TABLE IF EXISTS `ks_ai_usage_log`;
CREATE TABLE `ks_ai_usage_log` (
  `id`                int(10) unsigned NOT NULL AUTO_INCREMENT,
  `teacher_id`        int(10) unsigned NOT NULL DEFAULT 0,
  `owner_type`        enum('teacher','student') NOT NULL DEFAULT 'teacher' COMMENT '调用主体类型',
  `student_id`        int(10) unsigned NOT NULL DEFAULT 0 COMMENT 'owner_type=student 时的学生ID',
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
  KEY `idx_student` (`student_id`,`created_at`),
  KEY `idx_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='AI用量日志';

-- ----------------------------
-- 17. 通知公告
-- ----------------------------
DROP TABLE IF EXISTS `ks_notice`;
CREATE TABLE `ks_notice` (
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

-- ----------------------------
-- 18. 公告已读回执
-- ----------------------------
DROP TABLE IF EXISTS `ks_notice_read`;
CREATE TABLE `ks_notice_read` (
  `id`        int(10) unsigned NOT NULL AUTO_INCREMENT,
  `notice_id` int(10) unsigned NOT NULL DEFAULT 0,
  `user_id`   int(10) unsigned NOT NULL DEFAULT 0,
  `read_at`   int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_nr` (`notice_id`,`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='公告已读回执';

-- ----------------------------
-- 19. 学生档案 / 登录账号（学号登录，独立于教师体系）
-- ----------------------------
DROP TABLE IF EXISTS `ks_student`;
CREATE TABLE `ks_student` (
  `id`            int(10) unsigned NOT NULL AUTO_INCREMENT,
  `sno`           varchar(32)  NOT NULL DEFAULT '' COMMENT '学号（登录账号）',
  `password`      varchar(255) NOT NULL DEFAULT '' COMMENT '密码哈希 bcrypt',
  `name`          varchar(32)  NOT NULL DEFAULT '' COMMENT '姓名',
  `gender`        tinyint(1)   NOT NULL DEFAULT 0 COMMENT '0未知 1男 2女',
  `class_id`      int(10) unsigned NOT NULL DEFAULT 0 COMMENT '所属班级',
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

-- ----------------------------
-- 20. 学生出勤（一节 ks_lesson 对一个学生一条）
-- ----------------------------
DROP TABLE IF EXISTS `ks_student_attendance`;
CREATE TABLE `ks_student_attendance` (
  `id`          int(10) unsigned NOT NULL AUTO_INCREMENT,
  `lesson_id`   int(10) unsigned NOT NULL DEFAULT 0 COMMENT '课时ID',
  `student_id`  int(10) unsigned NOT NULL DEFAULT 0,
  `class_id`    int(10) unsigned NOT NULL DEFAULT 0 COMMENT '冗余班级，便于按班录入/统计',
  `status`      enum('present','absent','leave','late','early') NOT NULL DEFAULT 'present' COMMENT 'present出勤 absent缺勤 leave请假 late迟到 early早退',
  `note`        varchar(255) NOT NULL DEFAULT '' COMMENT '备注',
  `operator_id` int(10) unsigned NOT NULL DEFAULT 0 COMMENT '记录教师ID',
  `created_at`  int(11)      NOT NULL DEFAULT 0,
  `updated_at`  int(11)      NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_lesson_student` (`lesson_id`,`student_id`),
  KEY `idx_student` (`student_id`),
  KEY `idx_class` (`class_id`),
  KEY `idx_lesson` (`lesson_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='学生出勤记录';

-- ----------------------------
-- 21. 学生成绩与评价
-- ----------------------------
DROP TABLE IF EXISTS `ks_student_score`;
CREATE TABLE `ks_student_score` (
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

-- ----------------------------
-- 22. 学生 AI 额度（独立于教师 ks_ai_quota）
-- ----------------------------
DROP TABLE IF EXISTS `ks_student_ai_quota`;
CREATE TABLE `ks_student_ai_quota` (
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

-- ----------------------------
-- 23. 学生 AI 答疑会话
-- ----------------------------
DROP TABLE IF EXISTS `ks_student_ai_conversation`;
CREATE TABLE `ks_student_ai_conversation` (
  `id`         int(10) unsigned NOT NULL AUTO_INCREMENT,
  `student_id` int(10) unsigned NOT NULL DEFAULT 0,
  `title`      varchar(100) NOT NULL DEFAULT '',
  `created_at` int(11) NOT NULL DEFAULT 0,
  `updated_at` int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_student` (`student_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='学生AI答疑会话';

-- ----------------------------
-- 24. 学生 AI 答疑消息（留存供审计 / 学情画像）
-- ----------------------------
DROP TABLE IF EXISTS `ks_student_ai_message`;
CREATE TABLE `ks_student_ai_message` (
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

SET FOREIGN_KEY_CHECKS = 1;
