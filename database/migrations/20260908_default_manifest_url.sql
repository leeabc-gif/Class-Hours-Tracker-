-- ============================================================
-- 2026-09-08 · 默认指向 CNB 官方 Release 公开下载 (push v* tag 自动 publish)
-- 公开仓库匿名可访问：https://cnb.cool/bmayan/class-hours-tracker/-/releases/latest/download/...
-- 仅在 cfg_value 为空(管理员从未设置过)时才覆盖，避免冲掉用户自定义。
-- =========================================================

UPDATE `ks_setting`
   SET `cfg_value` = 'https://cnb.cool/bmayan/class-hours-tracker/-/releases/latest/download/manifest.json',
       `remark`    = '在线更新清单地址，默认指向 CNB 官方 Release 公开下载；管理员可在「基础配置」覆盖'
 WHERE `cfg_key` = 'update_manifest_url'
   AND (`cfg_value` = '' OR `cfg_value` IS NULL);
