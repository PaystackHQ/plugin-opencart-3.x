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

const REF = '42-5f3a9c1b2d4e6';

function decide(array $over = array(), array $data = array(), array $resp = array())
{
    $in = array(
        'order_id' => 42,
        'order_status_id' => 0,
        'unpaid_status_ids' => array(0, 1, 10, 7),
        'reference' => REF,
        'http_status' => 200,
        'response' => array_merge(array(
            'status' => true,
            'data' => array_merge(array(
                'status' => 'success',
                'reference' => REF,
                'amount' => 500000,
                'currency' => 'NGN',
            ), $data),
        ), $resp),
        'expected_amount' => 500000,
        'currency' => 'NGN',
    );
    return RK::paymentDecision(array_merge($in, $over));
}

same('paid', decide(), 'paid');
same('paid from pending', decide(array('order_status_id' => 1)), 'paid');
same('paid from failed status', decide(array('order_status_id' => 10)), 'paid');
same('paid from canceled status', decide(array('order_status_id' => 7)), 'paid');
same('requested_amount preferred', decide(array(), array('requested_amount' => 500000, 'amount' => 507500)), 'paid');
same('fee case amount only is mismatch', decide(array(), array('amount' => 507500)), 'mismatch');
same('requested_amount mismatch', decide(array(), array('requested_amount' => 100, 'amount' => 500000)), 'mismatch');
same('non-int requested_amount falls back to amount', decide(array(), array('requested_amount' => '500000')), 'paid');
same('amount mismatch', decide(array(), array('amount' => 100)), 'mismatch');
same('currency mismatch', decide(array(), array('currency' => 'GHS')), 'mismatch');
same('currency case-insensitive', decide(array(), array('currency' => 'ngn')), 'paid');
same('missing currency', decide(array(), array('currency' => null)), 'mismatch');

same('failed is not_paid', decide(array(), array('status' => 'failed')), 'not_paid');
same('abandoned is not_paid', decide(array(), array('status' => 'abandoned')), 'not_paid');
same('failed on paid order is ignore', decide(array('order_status_id' => 5), array('status' => 'failed')), 'ignore');

same('non-200', decide(array('http_status' => 404)), 'ignore');
same('string 200', decide(array('http_status' => '200')), 'ignore');
same('null response', decide(array('response' => null)), 'ignore');
same('status false', decide(array(), array(), array('status' => false)), 'ignore');
same('status string true', decide(array(), array(), array('status' => 'true')), 'ignore');
same('data not array', decide(array(), array(), array('data' => 'x')), 'ignore');
same('reference mismatch', decide(array(), array('reference' => '42-aaaaaaaaaaaaa')), 'ignore');
same('reference non-string', decide(array(), array('reference' => array(REF))), 'ignore');
same('order id mismatch', decide(array('order_id' => 43)), 'ignore');
same('order id string', decide(array('order_id' => '42')), 'ignore');

foreach (array('042-5f3a9c1b2d4e6', '+42-5f3a9c1b2d4e6', '-42-5f3a9c1b2d4e6', '0-5f3a9c1b2d4e6', '42-5F3A9C1B2D4E6', '42-5f3a9c1b2d4e', '42-5f3a9c1b2d4e67', "42-5f3a9c1b2d4e6\n", 'abc') as $bad) {
    same("garbage reference $bad", decide(array('reference' => $bad), array('reference' => $bad)), 'ignore');
}

same('already processing', decide(array('order_status_id' => 2)), 'ignore');
same('already complete', decide(array('order_status_id' => 5)), 'ignore');
same('amount string', decide(array(), array('amount' => '500000')), 'ignore');
same('amount float', decide(array(), array('amount' => 500000.0)), 'ignore');
same('amount missing', decide(array(), array('amount' => null)), 'ignore');

same('parse valid', RK::parseReference(REF), 42);
same('parse big id', RK::parseReference('1234567-0123456789abc'), 1234567);
same('parse leading zero', RK::parseReference('01-5f3a9c1b2d4e6'), null);
same('parse zero', RK::parseReference('0-5f3a9c1b2d4e6'), null);
same('parse sign', RK::parseReference('+1-5f3a9c1b2d4e6'), null);
same('parse no dash', RK::parseReference('425f3a9c1b2d4e6'), null);
same('parse empty', RK::parseReference(''), null);
same('parse array', RK::parseReference(array(REF)), null);
same('parse null', RK::parseReference(null), null);
same('parse int', RK::parseReference(42), null);
same('parse trailing newline', RK::parseReference(REF . "\n"), null);
same('parse overflow id', RK::parseReference('99999999999999999999-5f3a9c1b2d4e6'), null);

echo "passed: $pass, failed: $fail\n";
exit($fail ? 1 : 0);
