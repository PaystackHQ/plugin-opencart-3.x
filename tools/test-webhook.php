<?php

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

require __DIR__ . '/../system/library/paystack/restricted_key.php';

use Paystack\Restricted_Key as RK;

$pass = 0;
$fail = 0;

function same($name, $actual, $expected)
{
    global $pass, $fail;
    if ($actual === $expected) {
        $pass++;
    } else {
        $fail++;
        echo "FAIL: $name\n  expected: " . var_export($expected, true) . "\n  actual:   " . var_export($actual, true) . "\n";
    }
}

$now = 1700000000;
$body = '{"event":"charge.success","data":{"reference":"42-5f3a9c1b2d4e6"}}';
$whsec = 'whsec_abc123XYZ';
$sk = 'sk_test_abcdef123456';
$rk = 'rk_test_' . str_repeat('a', 24);

function sig($t, $input, $secret)
{
    return hash_hmac('sha512', $t . '.' . $input, $secret);
}

$good = 't=' . $now . ',v1=k1:' . sig($now, $body, $whsec);
same('valid', RK::verifySignature($good, $body, $whsec, $now), true);
same('valid within window', RK::verifySignature($good, $body, $whsec, $now + 300), true);
same('expired', RK::verifySignature($good, $body, $whsec, $now + 301), false);
same('future skew', RK::verifySignature($good, $body, $whsec, $now - 301), false);
same('wrong secret', RK::verifySignature($good, $body, 'whsec_other', $now), false);
same('tampered body', RK::verifySignature($good, $body . ' ', $whsec, $now), false);

$multi = 't=' . $now . ',v1=k0:' . str_repeat('0', 128) . ',v1=k1:' . sig($now, $body, $whsec);
same('multiple v1, one valid', RK::verifySignature($multi, $body, $whsec, $now), true);

same('malformed', RK::verifySignature('garbage', $body, $whsec, $now), false);
same('empty header', RK::verifySignature('', $body, $whsec, $now), false);
same('no v1', RK::verifySignature('t=' . $now, $body, $whsec, $now), false);
same('no t', RK::verifySignature('v1=k1:' . sig($now, $body, $whsec), $body, $whsec, $now), false);
same('non-digit t', RK::verifySignature('t=-1,v1=k1:' . sig('-1', $body, $whsec), $body, $whsec, 0), false);
same('two t=', RK::verifySignature('t=' . $now . ',t=' . $now . ',v1=k1:' . sig($now, $body, $whsec), $body, $whsec, $now), false);

$legacy = array('HTTP_X_PAYSTACK_SIGNATURE' => hash_hmac('sha512', $body, $sk));
same('legacy sk valid', RK::isWebhookAuthentic($body, '', $sk, $legacy, $now), true);
same('legacy sk wrong', RK::isWebhookAuthentic($body, '', $sk, array('HTTP_X_PAYSTACK_SIGNATURE' => 'deadbeef'), $now), false);
same('legacy sk empty header', RK::isWebhookAuthentic($body, '', $sk, array('HTTP_X_PAYSTACK_SIGNATURE' => ''), $now), false);
same('legacy refused when whsec set', RK::isWebhookAuthentic($body, $whsec, $sk, $legacy, $now), false);
same('whsec valid with sk', RK::isWebhookAuthentic($body, $whsec, $sk, array('HTTP_PAYSTACK_SIGNATURE' => $good), $now), true);

same('rk without whsec', RK::isWebhookAuthentic($body, '', $rk, array('HTTP_X_PAYSTACK_SIGNATURE' => hash_hmac('sha512', $body, $rk)), $now), false);
same('rk with whsec valid', RK::isWebhookAuthentic($body, $whsec, $rk, array('HTTP_PAYSTACK_SIGNATURE' => $good), $now), true);
same('empty secret', RK::isWebhookAuthentic($body, '', '', array('HTTP_X_PAYSTACK_SIGNATURE' => hash_hmac('sha512', $body, '')), $now), false);
same('no headers', RK::isWebhookAuthentic($body, '', $sk, array(), $now), false);

echo "passed: $pass, failed: $fail\n";
exit($fail ? 1 : 0);
