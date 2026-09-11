-- 升级 v1.1.3：AI 额度金额列精度提升
--
-- 背景：旧定义 balance/total_used 为 DECIMAL(14,2)，各周期 limit/used 为 DECIMAL(12,2)。
-- 按 New API 风格的「按实际 token 倍率计费」会产生 sub-cent 点数（如 0.0036 点），
-- 在 2 位小数下会被四舍五入吞掉（1000 - 0.0036 = 999.9964 → 1000.00），导致「扣了额度却没扣钱」。
--
-- 处置：将金额列统一提升到 DECIMAL(16,6)，可重复执行（MODIFY 幂等）。

ALTER TABLE `ks_ai_quota`
  MODIFY COLUMN `balance`       DECIMAL(16,6) NOT NULL DEFAULT '0.000000' COMMENT '总余额点数（管理员分配）',
  MODIFY COLUMN `total_used`    DECIMAL(16,6) NOT NULL DEFAULT '0.000000' COMMENT '累计消耗',
  MODIFY COLUMN `daily_limit`   DECIMAL(16,6) NOT NULL DEFAULT '0.000000' COMMENT '日限额，0=不限',
  MODIFY COLUMN `weekly_limit`  DECIMAL(16,6) NOT NULL DEFAULT '0.000000' COMMENT '周限额，0=不限',
  MODIFY COLUMN `monthly_limit` DECIMAL(16,6) NOT NULL DEFAULT '0.000000' COMMENT '月限额，0=不限',
  MODIFY COLUMN `daily_used`    DECIMAL(16,6) NOT NULL DEFAULT '0.000000',
  MODIFY COLUMN `weekly_used`   DECIMAL(16,6) NOT NULL DEFAULT '0.000000',
  MODIFY COLUMN `monthly_used`  DECIMAL(16,6) NOT NULL DEFAULT '0.000000';

-- 用量日志的 points 同样提升到 6 位小数，保持与额度口径一致
ALTER TABLE `ks_ai_usage_log`
  MODIFY COLUMN `points` DECIMAL(16,6) NOT NULL DEFAULT '0.000000' COMMENT '本次消耗点数';
