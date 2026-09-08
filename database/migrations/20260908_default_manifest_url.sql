-- ============================================================
-- 2026-09-08 · 旧默认(cnb raw)无法访问，回退为空，由管理员在「基础配置」填入
-- 一个真实可达的 https raw URL(Gitee/对象存储/自建静态站均可)。
-- 仅在 cfg_value 为空(管理员从未设置过)时才覆盖，避免冲掉用户自定义。
-- =========================================================

UPDATE `ks_setting`
   SET `cfg_value` = '',
       `remark`    = '在线更新清单地址(HTTPS)，可指向 Gitee/自建对象存储等支持 raw 的源；CNB 仓库本身不提供单文件 raw 直链'
 WHERE `cfg_key` = 'update_manifest_url'
   AND (`cfg_value` = '' OR `cfg_value` IS NULL);
