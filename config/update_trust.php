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
MIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIBCgKCAQEAvVjMfQmSTrWxLHsqJX/c
nHEHmvjqSoNsr8sjCWiy4ucXDCjm2tQgbnlyflu8NYaO/hYTikRmznH4UvqRfCgF
wfTeM6u74B5lkHOh2dsxijcuyu2Yo3wrWL0j2dwI3rc/eqGq3Npgb8bNckQW6hay
eGIRzbrFsdS7sWvpkOyejTJXZyoXyXa4Janu4FnD3GbiAQWECzFfk0TuawKWxWd0
8xUO/Vw/OiVh8Wl7jiXPY8job8OS6EpRir/VMdI0/3zcKb+7nR0MeIppMTu3+CjP
TxbZamR0EwXgbkJgc7RKWuxGkWOsIKF0azm+2xkWaqM27yOGVrx4AdHsKV8FBS9o
xwIDAQAB
-----END PUBLIC KEY-----
PEM,
];
