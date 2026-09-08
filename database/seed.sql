-- ============================================================
-- 种子数据（演示用，正式上线前请清空课时记录并修改默认密码）
-- 默认账号：
--   管理员  admin      / admin123
--   教师    wanglaoshi / 123456
--   教师    lilaoshi   / 123456
--   教师    zhaolaoshi / 123456 （已禁用，用于演示禁用态）
-- ============================================================

SET NAMES utf8mb4;

-- ----------------------------
-- 系统配置
-- ----------------------------
INSERT INTO `ks_setting` (`cfg_key`,`cfg_value`,`remark`) VALUES
('school_name','XX中等职业学校','学校名称'),
('global_price','60.00','全局课时单价参考（元/节）'),
('week_standard_periods','12','周标准课时（用于工作量饱和度评估）'),
('ai_enabled','1','AI模块开关 1开 0关'),
('ai_provider','local','AI引擎：local=本地规则引擎；llm=调用真实大模型'),
('ai_api_url','','真实大模型接口地址，留空则回落本地规则引擎'),
('ai_api_key','','真实大模型 API Key'),
('ai_model','','真实大模型模型名'),
('app_version','1.0.0','系统当前版本（在线更新维护）'),
('update_manifest_url','https://cnb.cool/bmayan/class-hours-tracker/-/releases/latest/download/manifest.json','在线更新清单地址，默认指向 CNB 官方 Release 公开下载；管理员可在「基础配置」覆盖');

-- ----------------------------
-- 院系
-- ----------------------------
INSERT INTO `ks_department` (`id`,`name`,`sort`,`status`,`created_at`,`updated_at`) VALUES
(1,'机械工程系',1,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(2,'电气工程系',2,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(3,'信息工程系',3,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(4,'公共基础部',4,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP());

-- ----------------------------
-- 教师 / 管理员账号
-- ----------------------------
INSERT INTO `ks_teacher` (`id`,`username`,`password`,`name`,`department_id`,`position`,`role`,`status`,`created_at`,`updated_at`) VALUES
(1,'admin','$2y$10$TCHWAOUOyK8F.8qU3vMGhuKYd/A6QRqYYCoF6UdeX/qRPB9v.seye','系统管理员',0,'超级管理员','admin',1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(2,'wanglaoshi','$2y$10$GD8DVWjCzzc24TCw/6ryLOWl7Cvq85qHfH1BYoOWSqMIiqMiKRDVS','王建国',1,'讲师','teacher',1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(3,'lilaoshi','$2y$10$GD8DVWjCzzc24TCw/6ryLOWl7Cvq85qHfH1BYoOWSqMIiqMiKRDVS','李雪梅',2,'副教授','teacher',1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(4,'zhaolaoshi','$2y$10$GD8DVWjCzzc24TCw/6ryLOWl7Cvq85qHfH1BYoOWSqMIiqMiKRDVS','赵国强',3,'助教','teacher',0,UNIX_TIMESTAMP(),UNIX_TIMESTAMP());

-- ----------------------------
-- 课程库（teacher_id=0 为全校公共课程）
-- ----------------------------
INSERT INTO `ks_course` (`id`,`teacher_id`,`name`,`classes`,`price`,`status`,`created_at`,`updated_at`) VALUES
(1,0,'工业机器人导论','机器人2301班,机器人2302班',60.00,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(2,0,'PLC应用技术','电气2301班',55.00,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(3,0,'机械制图','机制2301班',50.00,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(4,0,'单片机原理','电子2301班',55.00,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(5,0,'液压与气动','机制2302班',50.00,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
-- 教师私有课程（仅王建国可见，单价可与公共库不同）
(6,2,'工业机器人综合实训','实训A班',80.00,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP());

-- ----------------------------
-- 班级（演示用 4 个院系 × 2-3 个班，覆盖 2023-2024 级）
-- ----------------------------
INSERT INTO `ks_class` (`id`,`name`,`department_id`,`year`,`status`,`sort`,`remark`,`created_at`,`updated_at`) VALUES
(1,'机器人2301班',1,2023,1,1,'',UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(2,'机器人2302班',1,2023,1,2,'',UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(3,'机制2301班',1,2023,1,3,'',UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(4,'机制2302班',1,2023,1,4,'',UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(5,'电气2301班',2,2023,1,5,'',UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(6,'电气2302班',2,2023,1,6,'',UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(7,'电子2301班',3,2023,1,7,'',UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(8,'电子2302班',3,2023,1,8,'',UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(9,'计算机2301班',3,2023,1,9,'',UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(10,'普高2401班',4,2024,1,10,'公共基础部示例',UNIX_TIMESTAMP(),UNIX_TIMESTAMP());

-- ----------------------------
-- 常用课程收藏
-- ----------------------------
INSERT INTO `ks_course_favorite` (`teacher_id`,`course_id`,`created_at`) VALUES
(2,1,UNIX_TIMESTAMP()),
(2,6,UNIX_TIMESTAMP()),
(3,2,UNIX_TIMESTAMP());

-- ----------------------------
-- 学期（第1周周一 = 2026-09-07）
-- ----------------------------
INSERT INTO `ks_term` (`id`,`name`,`start_week`,`end_week`,`start_date`,`is_current`,`created_at`,`updated_at`) VALUES
(1,'2026-2027学年第一学期',1,20,'2026-09-07',1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(2,'2025-2026学年第二学期',1,18,'2026-02-23',0,UNIX_TIMESTAMP(),UNIX_TIMESTAMP());

-- ----------------------------
-- 课时记录
--   section: 1=1-2节 2=3-4节 3=5-6节 4=7-8节 5=9-10节 6=11-12节
--   type:    normal=常规课 makeup=补课 swap=调课 training=实训课
-- ----------------------------
INSERT INTO `ks_lesson`
(`id`,`teacher_id`,`term_id`,`course_id`,`course_name`,`classes`,`week`,`weekday`,`section`,`teach_date`,`periods`,`price`,`amount`,`type`,`remark`,`source`,`deleted_at`,`created_at`,`updated_at`)
VALUES
-- 王建国（teacher_id=2）
(1, 2,1,1,'工业机器人导论','机器人2301班,机器人2302班',1,1,1,'2026-09-07',2,60.00,120.00,'normal','','batch',0,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(2, 2,1,1,'工业机器人导论','机器人2301班,机器人2302班',2,1,1,'2026-09-14',2,60.00,120.00,'normal','','batch',0,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(3, 2,1,1,'工业机器人导论','机器人2301班,机器人2302班',3,1,1,'2026-09-21',2,60.00,120.00,'normal','','batch',0,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(4, 2,1,1,'工业机器人导论','机器人2301班,机器人2302班',3,5,3,'2026-09-25',2,60.00,120.00,'normal','单周加课','manual',0,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(5, 2,1,1,'工业机器人导论','机器人2301班,机器人2302班',4,3,2,'2026-09-30',2,60.00,120.00,'normal','','manual',0,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
-- 下面这条故意留空实际授课日期，用于演示「逾期未填日期」提醒
(6, 2,1,1,'工业机器人导论','机器人2301班,机器人2302班',4,5,5,NULL,2,60.00,120.00,'swap','调课待补日期','manual',0,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(7, 2,1,1,'工业机器人导论','机器人2301班,机器人2302班',5,1,1,'2026-10-05',2,60.00,120.00,'normal','','batch',0,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(8, 2,1,6,'工业机器人综合实训','实训A班',6,4,3,'2026-10-15',2,80.00,160.00,'training','实训周','manual',0,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(9, 2,1,1,'工业机器人导论','机器人2301班,机器人2302班',7,1,1,'2026-10-19',2,60.00,120.00,'normal','','batch',0,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(10,2,1,1,'工业机器人导论','机器人2301班,机器人2302班',8,1,1,'2026-10-26',2,60.00,120.00,'normal','','batch',0,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(11,2,1,1,'工业机器人导论','机器人2301班,机器人2302班',9,2,2,'2026-11-03',2,60.00,120.00,'makeup','国庆补课后补','manual',0,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(12,2,1,1,'工业机器人导论','机器人2301班,机器人2302班',10,5,4,'2026-11-13',2,60.00,120.00,'swap','与实训周对调','manual',0,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(13,2,1,1,'工业机器人导论','机器人2301班,机器人2302班',11,1,1,'2026-11-16',2,60.00,120.00,'normal','','batch',0,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(14,2,1,1,'工业机器人导论','机器人2301班,机器人2302班',12,1,1,'2026-11-23',2,60.00,120.00,'normal','','batch',0,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
-- 李雪梅（teacher_id=3）
(15,3,1,2,'PLC应用技术','电气2301班',1,2,2,'2026-09-08',2,55.00,110.00,'normal','','batch',0,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(16,3,1,2,'PLC应用技术','电气2301班',2,2,2,'2026-09-15',2,55.00,110.00,'normal','','batch',0,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(17,3,1,2,'PLC应用技术','电气2301班',3,2,2,'2026-09-22',2,55.00,110.00,'normal','','batch',0,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(18,3,1,2,'PLC应用技术','电气2301班',4,2,2,'2026-09-29',2,55.00,110.00,'normal','','batch',0,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(19,3,1,2,'PLC应用技术','电气2301班',5,4,1,'2026-10-08',2,55.00,110.00,'normal','','manual',0,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(20,3,1,2,'PLC应用技术','电气2301班',6,2,2,'2026-10-13',2,55.00,110.00,'normal','','batch',0,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(21,3,1,2,'PLC应用技术','电气2301班',7,2,2,'2026-10-20',2,55.00,110.00,'normal','','batch',0,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(22,3,1,2,'PLC应用技术','电气2301班',8,6,3,'2026-10-31',2,55.00,110.00,'makeup','周六补课','manual',0,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
-- 赵国强（teacher_id=4，账号已禁用）
(23,4,1,4,'单片机原理','电子2301班',1,3,3,'2026-09-09',2,55.00,110.00,'normal','','batch',0,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(24,4,1,4,'单片机原理','电子2301班',2,3,3,'2026-09-16',2,55.00,110.00,'normal','','batch',0,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(25,4,1,4,'单片机原理','电子2301班',3,3,3,'2026-09-23',2,55.00,110.00,'normal','','batch',0,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(26,4,1,4,'单片机原理','电子2301班',4,3,3,'2026-09-30',2,55.00,110.00,'normal','','batch',0,UNIX_TIMESTAMP(),UNIX_TIMESTAMP());
