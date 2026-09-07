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
// ============================================================
return [
    'public_key' => <<<'PEM'
-----BEGIN PUBLIC KEY-----
MIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIBCgKCAQEA6z6/yvMKYTpXXciQB4Y0
YQ8GEtcE9dlPmQRX5gcz3w6eC8LF0HrFsM2gtZFBCq+TyguVF094TwV/oBF/vn4X
g16iyOhLz8X9vf13X4Kkga/x04aRgbebQQn0b6dcqHPr8WSl9yLZLdDdPXxd8jx4
/wQiExGL/slWPn9syX246Y7plLfso2vOphGOWhrqyQGw59Ozm6R+osLYU+23oJGI
7bcbulJpTwYUFR/vTlxUXi4Tn+NlEifRlmTrJAuHEUsqiKbd1AVFmtDD6UcxUzLH
GVXeehTexqO0IygND+kqrVg/HjpkSA7zFKFabRZO3ohymrUskxuY4u1xjigXbZT+
uQIDAQAB
-----END PUBLIC KEY-----
PEM,
];
