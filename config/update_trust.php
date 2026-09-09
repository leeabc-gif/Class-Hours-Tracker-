<?php
// ============================================================
// 在线更新「信任公钥」
//
// 出厂内置的公钥 PEM，用于校验远程更新清单(manifest.json)的
// RSA-SHA256 数字签名。发布端持有对应私钥，对清单规范化 JSON
// 签名后放入 signature 字段；本机验签通过才允许使用该清单。
//
// ⚠️ 安全说明：
//  - 这是 keshi「开发者默认」信任的公钥（供本站自托管更新源演示/自用）。
//  - 生产正式对外发布前，请用你自己的密钥对替换本文件，并确保私钥
//    只保存在发布端（绝不上传到站点、不提交到公开仓库）。
//  - 配套签名工具见 tools/sign_manifest.php。
//
// v1.0.2 私钥轮换说明：
//  - 由于历史 v1.0.1 私钥已遗失，本公钥为 v1.0.2 重新生成的密钥对的公钥部分
//  - v1.0.1 → v1.0.2 跨私钥升级：管理员请手动覆盖源码（解 keshi-1.0.2.zip），
//    不要走「检查更新」一键升级（验签会失败），升级完成后公钥自动同步为新值
//  - 对应私钥在发布端的 _env/ks_update_priv.pem，**永远不要上传到站点**
// ============================================================
return [
    'public_key' => <<<'PEM'
-----BEGIN PUBLIC KEY-----
MIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIBCgKCAQEAu33RkbQvEUftLUc5V6ZB
JhzcOHhXxOD7qLHdEceUEeihrdIhhmoVBGm1gnayuD3kvu5RcCuRjZO15kZLa51s
6A/Y1peZtsMVTlGbdRwb+hn2CgYb059tJl9x8SD5ASPAe9S37xwwS3NM/2WgfouM
GCK5A/+bjbZK0WvaSrpHUHoasz1z4WeaWMMk6wzNfYnnuVoboafEL4CE9HZGR+Hp
ZpkNkcLgBevvStsJP+XobNaDUcfCi0su2bqmW/VEEbaLf9ixh/TVTfsy2bcCD6iO
e8HqgNXpUsnzFfKEjWZ48qqCV4c7nBsNnjuhqTFyvhBkZI6eNdpwbwlJBvB8AmIA
oQIDAQAB
-----END PUBLIC KEY-----
PEM,
];
