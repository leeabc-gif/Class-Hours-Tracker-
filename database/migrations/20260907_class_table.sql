-- ============================================================
-- 20260907_class_table.sql
-- 班级表（管理员维护，录入时多选）
-- 安装向导以 `CREATE TABLE IF NOT EXISTS` 幂等执行
-- ============================================================

CREATE TABLE IF NOT EXISTS `ks_class` (
  `id`            int(10) unsigned NOT NULL AUTO_INCREMENT,
  `name`          varchar(64)  NOT NULL DEFAULT '' COMMENT '班级名称',
  `department_id` int(10) unsigned NOT NULL DEFAULT 0 COMMENT '所属院系',
  `year`          smallint(5)  NOT NULL DEFAULT 0 COMMENT '入学年份，如 2023',
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
