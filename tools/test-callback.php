<?php

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

require __DIR__ . '/../system/library/paystack/payment.php';

use Paystack\Payment as P;

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
        'unpaid_status_ids' => P::unpaidStatusIds(1, 5),
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
    return P::paymentDecision(array_merge($in, $over));
}

same('paid', decide(), 'paid');
same('paid from pending', decide(array('order_status_id' => 1)), 'paid');
same('paid from a status outside the unpaid set is ignore', decide(array('order_status_id' => 10)), 'ignore');
same('requested_amount preferred', decide(array(), array('requested_amount' => 500000, 'amount' => 507500)), 'paid');
same('fee case amount only is mismatch', decide(array(), array('amount' => 507500)), 'mismatch');
same('requested_amount mismatch', decide(array(), array('requested_amount' => 100, 'amount' => 500000)), 'mismatch');
same('requested_amount above amount is not trusted', decide(array('expected_amount' => 100), array('requested_amount' => 100, 'amount' => 50)), 'mismatch');
same('requested_amount equal to amount', decide(array(), array('requested_amount' => 500000, 'amount' => 500000)), 'paid');
same('requested_amount without int amount is ignore', decide(array(), array('requested_amount' => 500000, 'amount' => '500000')), 'ignore');
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

same('unpaid ids default', P::unpaidStatusIds(1, 5), array(0, 1));
same('unpaid ids strings', P::unpaidStatusIds('1', '5'), array(0, 1));
same('unpaid ids pending is paid', P::unpaidStatusIds(5, 5), array(0));
same('unpaid ids pending empty', P::unpaidStatusIds('', 5), array(0));
same('unpaid ids pending null', P::unpaidStatusIds(null, 5), array(0));
same('unpaid ids pending false', P::unpaidStatusIds(false, 5), array(0));
same('unpaid ids paid empty keeps pending', P::unpaidStatusIds(1, ''), array(0, 1));
same('unpaid ids pending zero is deduped', P::unpaidStatusIds(0, 5), array(0));
same('unpaid ids both empty', P::unpaidStatusIds(null, null), array(0));

same('parse valid', P::parseReference(REF), 42);
same('parse big id', P::parseReference('1234567-0123456789abc'), 1234567);
same('parse leading zero', P::parseReference('01-5f3a9c1b2d4e6'), null);
same('parse zero', P::parseReference('0-5f3a9c1b2d4e6'), null);
same('parse sign', P::parseReference('+1-5f3a9c1b2d4e6'), null);
same('parse no dash', P::parseReference('425f3a9c1b2d4e6'), null);
same('parse empty', P::parseReference(''), null);
same('parse array', P::parseReference(array(REF)), null);
same('parse null', P::parseReference(null), null);
same('parse int', P::parseReference(42), null);
same('parse trailing newline', P::parseReference(REF . "\n"), null);
same('parse overflow id', P::parseReference('99999999999999999999-5f3a9c1b2d4e6'), null);

same('gate new order', P::statusGate(0, array(0, 1), array(2, 1, 5)), 'proceed');
same('gate pending beats processing', P::statusGate(1, array(0, 1), array(2, 1, 5)), 'proceed');
same('gate paid', P::statusGate(2, array(0, 1), array(2, 1, 5)), 'already_paid');
same('gate complete', P::statusGate(5, array(0, 1), array(2, 1, 5)), 'already_paid');
same('gate merchant canceled', P::statusGate(7, array(0, 1), array(2, 1, 5)), 'ignore');

// Gate regressions: pending is unpaid even when listed as a processing status; the paid status never is
same('gate pending in both lists proceeds', P::statusGate(1, array(0, 1), array(1)), 'proceed');
same('gate status 0 in paid list still proceeds', P::statusGate(0, array(0), array(0, 5)), 'proceed');
same('gate is strict about types', P::statusGate('1', array(0, 1), array(5)), 'ignore');
same('gate empty lists ignore', P::statusGate(1, array(), array()), 'ignore');
same('gate paid-only status when pending equals paid', P::statusGate(5, P::unpaidStatusIds(5, 5), array(5)), 'already_paid');
same('gate pending==paid: status 0 proceeds', P::statusGate(0, P::unpaidStatusIds(5, 5), array(5)), 'proceed');
same('unpaid ids never contain the paid status', in_array(5, P::unpaidStatusIds(5, '5'), true), false);
same('unpaid ids string paid equals int pending', P::unpaidStatusIds('5', 5), array(0));
same('decision: order already in paid status is ignore', decide(array('order_status_id' => 5, 'unpaid_status_ids' => P::unpaidStatusIds(5, 5))), 'ignore');
same('decision: pending==paid, status 0 is paid', decide(array('order_status_id' => 0, 'unpaid_status_ids' => P::unpaidStatusIds(5, 5))), 'paid');

// requested_amount is used only when amount >= requested_amount
same('requested above amount, expected=amount is ignored, charged amount compared', decide(array('expected_amount' => 50), array('requested_amount' => 100, 'amount' => 50)), 'paid');
same('requested above amount, expected=requested is mismatch', decide(array('expected_amount' => 100), array('requested_amount' => 100, 'amount' => 50)), 'mismatch');
same('requested one above amount is not used', decide(array('expected_amount' => 500000), array('requested_amount' => 500001, 'amount' => 500000)), 'paid');
same('requested one below amount is used', decide(array(), array('requested_amount' => 499999, 'amount' => 500000)), 'mismatch');
same('requested zero with larger amount is mismatch', decide(array(), array('requested_amount' => 0, 'amount' => 500000)), 'mismatch');
same('requested float is not trusted', decide(array(), array('requested_amount' => 500000.0, 'amount' => 500000)), 'paid');

echo "passed: $pass, failed: $fail\n";
exit($fail ? 1 : 0);
