<?php

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

require __DIR__ . '/../system/library/paystack/restricted_key.php';
require __DIR__ . '/../system/library/paystack/webhook.php';

use Paystack\Webhook as WH;

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
same('valid', WH::verifySignature($good, $body, $whsec, $now), true);
same('valid within window', WH::verifySignature($good, $body, $whsec, $now + 300), true);
same('expired', WH::verifySignature($good, $body, $whsec, $now + 301), false);
same('future skew', WH::verifySignature($good, $body, $whsec, $now - 301), false);
same('wrong secret', WH::verifySignature($good, $body, 'whsec_other', $now), false);
same('tampered body', WH::verifySignature($good, $body . ' ', $whsec, $now), false);

$multi = 't=' . $now . ',v1=k0:' . str_repeat('0', 128) . ',v1=k1:' . sig($now, $body, $whsec);
same('multiple v1, one valid', WH::verifySignature($multi, $body, $whsec, $now), true);

same('malformed', WH::verifySignature('garbage', $body, $whsec, $now), false);
same('empty header', WH::verifySignature('', $body, $whsec, $now), false);
same('no v1', WH::verifySignature('t=' . $now, $body, $whsec, $now), false);
same('no t', WH::verifySignature('v1=k1:' . sig($now, $body, $whsec), $body, $whsec, $now), false);
same('non-digit t', WH::verifySignature('t=-1,v1=k1:' . sig('-1', $body, $whsec), $body, $whsec, 0), false);
same('two t=', WH::verifySignature('t=' . $now . ',t=' . $now . ',v1=k1:' . sig($now, $body, $whsec), $body, $whsec, $now), false);

$legacy = array('HTTP_X_PAYSTACK_SIGNATURE' => hash_hmac('sha512', $body, $sk));
same('legacy sk valid', WH::isWebhookAuthentic($body, '', $sk, $legacy, $now), true);
same('legacy sk wrong', WH::isWebhookAuthentic($body, '', $sk, array('HTTP_X_PAYSTACK_SIGNATURE' => 'deadbeef'), $now), false);
same('legacy sk empty header', WH::isWebhookAuthentic($body, '', $sk, array('HTTP_X_PAYSTACK_SIGNATURE' => ''), $now), false);
same('legacy refused when whsec set', WH::isWebhookAuthentic($body, $whsec, $sk, $legacy, $now), false);
same('whsec valid with sk', WH::isWebhookAuthentic($body, $whsec, $sk, array('HTTP_PAYSTACK_SIGNATURE' => $good), $now), true);

same('rk without whsec', WH::isWebhookAuthentic($body, '', $rk, array('HTTP_X_PAYSTACK_SIGNATURE' => hash_hmac('sha512', $body, $rk)), $now), false);
same('rk with whsec valid', WH::isWebhookAuthentic($body, $whsec, $rk, array('HTTP_PAYSTACK_SIGNATURE' => $good), $now), true);
same('empty secret', WH::isWebhookAuthentic($body, '', '', array('HTTP_X_PAYSTACK_SIGNATURE' => hash_hmac('sha512', $body, '')), $now), false);
same('no headers', WH::isWebhookAuthentic($body, '', $sk, array(), $now), false);

// whsec set: legacy header refused, missing/odd headers refused
same('whsec set, no headers', WH::isWebhookAuthentic($body, $whsec, $sk, array(), $now), false);
same('whsec set, new header non-string', WH::isWebhookAuthentic($body, $whsec, $sk, array('HTTP_PAYSTACK_SIGNATURE' => array($good)), $now), false);
same('whsec set, new header bad', WH::isWebhookAuthentic($body, $whsec, $sk, array('HTTP_PAYSTACK_SIGNATURE' => 't=' . $now . ',v1=k1:00'), $now), false);
same('whsec set, expired new header', WH::isWebhookAuthentic($body, $whsec, $sk, array('HTTP_PAYSTACK_SIGNATURE' => $good), $now + 301), false);
same('whsec set, legacy valid for rk-less sk still refused', WH::isWebhookAuthentic($body, $whsec, $sk, $legacy + array('HTTP_PAYSTACK_SIGNATURE' => 'x'), $now), false);
same('whsec non-string falls to legacy', WH::isWebhookAuthentic($body, null, $sk, $legacy, $now), true);
same('non-string input', WH::isWebhookAuthentic(null, '', $sk, $legacy, $now), false);
same('legacy header non-string', WH::isWebhookAuthentic($body, '', $sk, array('HTTP_X_PAYSTACK_SIGNATURE' => array('a')), $now), false);
same('legacy non-key secret', WH::isWebhookAuthentic($body, '', 'junk', array('HTTP_X_PAYSTACK_SIGNATURE' => hash_hmac('sha512', $body, 'junk')), $now), false);
same('verify empty secret', WH::verifySignature($good, $body, '', $now), false);
same('verify non-string header', WH::verifySignature(null, $body, $whsec, $now), false);
same('verify non-string input', WH::verifySignature($good, null, $whsec, $now), false);
same('v1 without key prefix', WH::verifySignature('t=' . $now . ',v1=' . sig($now, $body, $whsec), $body, $whsec, $now), true);
same('uppercase hex signature rejected', WH::verifySignature('t=' . $now . ',v1=k1:' . strtoupper(sig($now, $body, $whsec)), $body, $whsec, $now), false);
same('t over 15 digits', WH::verifySignature('t=1' . str_repeat('0', 15) . ',v1=k1:' . sig('1' . str_repeat('0', 15), $body, $whsec), $body, $whsec, $now), false);
same('signature over a different timestamp', WH::verifySignature('t=' . $now . ',v1=k1:' . sig($now + 1, $body, $whsec), $body, $whsec, $now), false);
same('whitespace around parts', WH::verifySignature('t=' . $now . ' , v1=k1:' . sig($now, $body, $whsec), $body, $whsec, $now), true);

echo "passed: $pass, failed: $fail\n";
exit($fail ? 1 : 0);
