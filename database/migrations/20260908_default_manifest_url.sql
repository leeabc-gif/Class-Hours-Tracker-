-- ============================================================
-- 2026-09-08 · 默认指向 CNB 官方更新通道
-- 仅在 cfg_value 为空（管理员从未设置过）时才覆盖，避免冲掉用户自定义。
-- =========================================================

UPDATE `ks_setting`
   SET `cfg_value` = 'https://cnb.cool/bmayan/class-hours-tracker/-/raw/main/release/manifest.json',
       `remark`    = '在线更新清单地址，默认指向 CNB 官方发布通道；管理员可在「基础配置」覆盖'
 WHERE `cfg_key` = 'update_manifest_url'
   AND (`cfg_value` = '' OR `cfg_value` IS NULL);
