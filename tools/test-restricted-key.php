<?php

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/../system/library/paystack/http.php';
require __DIR__ . '/../system/library/paystack/restricted_key.php';
require __DIR__ . '/../system/library/paystack/webhook.php';

use Paystack\Restricted_Key as RK;
use Paystack\Webhook as WH;

$DENIED = '{"status":false,"message":"This restricted key is not permitted to perform this action"}';
$STUB = '{"status":false,"message":"Sorry, this feature is no longer available."}';
$MOR = '{"status":false,"message":"This feature is not available to merchant of record integrations."}';
$IDENT = '{"status":true,"data":{"id":123,"public_key":"pk_test_abcdef123456"}}';
$KEY = 'rk_test_SECRETMARKER0123456789';

function r($status, $body = '', $errno = 0)
{
    return array('status' => $status, 'body' => $body, 'errno' => $errno);
}

// Everything a good key looks like: required present, every refused scope denied.
function goodResponses()
{
    global $DENIED, $IDENT;
    $m = array('transactions:read' => r(200, '{"status":true}'), 'account:read' => r(200, $IDENT));
    foreach (RK::probes() as $p) {
        if ($p['sensitive']) {
            $m[$p['scope']] = r(403, $DENIED);
        }
    }
    return $m;
}

function stub(array $overrides = array(), &$calls = null)
{
    $map = array_merge(goodResponses(), $overrides);
    $calls = array();
    return function (array $requests, $deadline) use ($map, &$calls) {
        $calls[] = $requests;
        $out = array();
        foreach ($requests as $req) {
            $out[$req['id']] = isset($map[$req['id']]) ? $map[$req['id']] : r(0, '', 7);
        }
        return $out;
    };
}

function run(array $overrides = array(), $mode = 'test', &$calls = null)
{
    global $KEY;
    $rk = new RK(stub($overrides, $calls));
    return $rk->validate($mode === 'live' ? str_replace('rk_test_', 'rk_live_', $KEY) : $KEY, $mode);
}

// Probe table
$probes = RK::probes();
$byScope = array();
foreach ($probes as $p) {
    $byScope[$p['scope']] = $p;
}
same('probe order starts with the baseline', $probes[0]['scope'], 'transactions:read');
same('account:read is second and required', array($probes[1]['scope'], $probes[1]['required']), array('account:read', true));
same('exactly two required probes', count(array_filter($probes, function ($p) { return $p['required']; })), 2);
check('transactions:write is refused, not required', $byScope['transactions:write']['sensitive'] && !$byScope['transactions:write']['required']);
same('write probe is POST check_authorization {}', array($byScope['transactions:write']['method'], $byScope['transactions:write']['path'], $byScope['transactions:write']['body']), array('POST', 'transaction/check_authorization', '{}'));
same('customers path', $byScope['customers']['path'], 'customer?perPage=1&from=2099-01-01');
same('balance path', $byScope['balance']['path'], 'balance');
same('only POST probe is check_authorization', array_keys(array_filter($probes, function ($p) { return $p['method'] === 'POST'; })), array(2));
same('refused probe count', count(array_filter($probes, function ($p) { return $p['sensitive']; })), 14);

// classify
$base = $byScope['transactions:read'];
$sens = $byScope['settlements'];
$write = $byScope['transactions:write'];
$sub = $byScope['subaccounts'];
same('baseline 2xx present', RK::classify($base, 200, '', null), 'present');
same('baseline 299 present', RK::classify($base, 299, '', null), 'present');
same('baseline 401 invalid', RK::classify($base, 401, '', null), 'invalid');
same('baseline scope-denied 403 absent', RK::classify($base, 403, $DENIED, null), 'absent');
same('baseline other 403 indeterminate', RK::classify($base, 403, '{"message":"IP not allowed"}', null), 'indeterminate');
same('baseline 429 indeterminate', RK::classify($base, 429, '', null), 'indeterminate');
same('baseline 500 indeterminate', RK::classify($base, 500, '', null), 'indeterminate');
same('baseline 302 indeterminate', RK::classify($base, 302, '', null), 'indeterminate');
same('baseline status 0 indeterminate', RK::classify($base, 0, '', null), 'indeterminate');
same('baseline 400 not present', RK::classify($base, 400, '', null), 'indeterminate');
same('refused 200 present', RK::classify($sens, 200, '', 'present'), 'present');
same('refused 400 present', RK::classify($sens, 400, '', 'present'), 'present');
same('refused 404 indeterminate', RK::classify($sens, 404, '', 'present'), 'indeterminate');
same('refused scope 403 absent', RK::classify($sens, 403, $DENIED, 'present'), 'absent');
same('refused plain-text scope message absent', RK::classify($sens, 403, RK::SCOPE_DENIED_MESSAGE, 'present'), 'absent');
same('near-miss scope message fails closed', RK::classify($sens, 403, '{"message":"This restricted key is not permitted to perform this action."}', 'present'), 'indeterminate');
same('non-string message fails closed', RK::classify($sens, 403, '{"message":123}', 'present'), 'indeterminate');
same('403 with empty body fails closed', RK::classify($sens, 403, '', 'present'), 'indeterminate');
same('401 on refused after present baseline is present', RK::classify($sens, 401, '', 'present'), 'present');
same('401 on refused after absent baseline is indeterminate', RK::classify($sens, 401, '', 'absent'), 'indeterminate');
same('401 on required non-baseline is indeterminate', RK::classify($probes[1], 401, '', 'present'), 'indeterminate');
same('write probe 404 + stub message present', RK::classify($write, 404, $STUB, 'present'), 'present');
same('write probe plain-text stub message present', RK::classify($write, 404, RK::STUB_MESSAGE, 'present'), 'present');
same('write probe 404 other message indeterminate', RK::classify($write, 404, '{"message":"Route not found"}', 'present'), 'indeterminate');
same('write probe 404 empty body indeterminate', RK::classify($write, 404, '', 'present'), 'indeterminate');
same('write probe 200 indeterminate', RK::classify($write, 200, $STUB, 'present'), 'indeterminate');
same('write probe 400 indeterminate', RK::classify($write, 400, '', 'present'), 'indeterminate');
same('write probe scope-denied absent', RK::classify($write, 403, $DENIED, 'present'), 'absent');
same('subaccounts MOR 403 present', RK::classify($sub, 403, $MOR, 'present'), 'present');
same('subaccounts other 403 indeterminate', RK::classify($sub, 403, '{"message":"nope"}', 'present'), 'indeterminate');
same('MOR 403 on another refused probe is not present', RK::classify($sens, 403, $MOR, 'present'), 'indeterminate');
same('subaccounts 200 present', RK::classify($sub, 200, '', 'present'), 'present');

// messageOf
same('messageOf JSON message', RK::messageOf('{"message":"hi"}'), 'hi');
same('messageOf raw string', RK::messageOf('plain text'), 'plain text');
same('messageOf JSON without message returns raw body', RK::messageOf('{"a":1}'), '{"a":1}');
same('messageOf non-string message returns raw body', RK::messageOf('{"message":5}'), '{"message":5}');
same('messageOf non-string body', RK::messageOf(array('message' => 'x')), null);
same('messageOf null body', RK::messageOf(null), null);
same('messageOf empty string', RK::messageOf(''), '');

// validate: verdicts
$v = run();
same('all good is ok', $v['code'], 'ok');
same('ok carries the ident pk', $v['public_key'], 'pk_test_abcdef123456');
same('ok has no missing or sensitive', array($v['missing'], $v['sensitive'], $v['reason']), array(array(), array(), null));

$calls = null;
$v = run(array(), 'test', $calls);
same('baseline runs alone, then the rest in one batch', array(count($calls[0]), count($calls[1])), array(1, count($probes) - 1));
same('baseline is transactions:read', $calls[0][0]['id'], 'transactions:read');
$posts = array();
foreach ($calls as $batch) {
    foreach ($batch as $req) {
        if ($req['method'] === 'POST') {
            $posts[] = $req['path'] . '|' . $req['body'];
        }
        if ($req['key'] !== $KEY) {
            check('request carries the key', false);
        }
    }
}
same('only POST is check_authorization with {}', $posts, array('transaction/check_authorization|{}'));

$v = run(array('transactions:read' => r(401)), 'test', $calls);
same('baseline 401 is invalid_key', $v['code'], 'invalid_key');
same('baseline 401 stops after one call', count($calls), 1);

$v = run(array('transactions:read' => r(500)), 'test', $calls);
same('indeterminate baseline is unverifiable', array($v['code'], $v['reason']), array('unverifiable', 'transient'));
same('indeterminate baseline stops after one call', count($calls), 1);

$v = run(array('transactions:read' => r(403, '{"message":"IP"}')), 'test', $calls);
same('non-scope 403 baseline is blocked', array($v['code'], $v['reason']), array('unverifiable', 'blocked'));
same('blocked baseline stops after one call', count($calls), 1);

$v = run(array('transactions:read' => r(0, '', 28)), 'test', $calls);
same('baseline timeout is unverifiable', array($v['code'], count($calls)), array('unverifiable', 1));

$v = run(array('transactions:read' => r(403, $DENIED)), 'test', $calls);
same('absent baseline is missing_scope', array($v['code'], $v['missing']), array('missing_scope', array('Transactions: read')));
same('absent baseline still probes the rest', count($calls), 2);

$v = run(array('transactions:read' => r(403, $DENIED), 'account:read' => r(403, $DENIED)));
same('both required missing in table order', $v['missing'], array('Transactions: read', 'Account: read'));

$v = run(array('account:read' => r(403, $DENIED)));
same('lone ident refusal is missing_scope', array($v['code'], $v['missing']), array('missing_scope', array('Account: read')));

$v = run(array('account:read' => r(401)));
same('401 on ident after 2xx baseline is unverifiable transient', array($v['code'], $v['reason']), array('unverifiable', 'transient'));

$v = run(array('account:read' => r(403, '{"message":"IP"}')));
same('non-scope 403 on ident is blocked', array($v['code'], $v['reason']), array('unverifiable', 'blocked'));

$v = run(array('settlements' => r(200)));
same('refused scope present is sensitive_scope', array($v['code'], $v['sensitive']), array('sensitive_scope', array('Settlements')));

$v = run(array('payouts' => r(400), 'disputes_refunds' => r(200), 'balance' => r(200)));
same('sensitive scopes listed in table order', $v['sensitive'], array('Disputes and Refunds', 'Payouts', 'Balance'));

$v = run(array('transactions:write' => r(404, $STUB)));
same('write probe matching the stub is refused scope present', array($v['code'], $v['sensitive']), array('sensitive_scope', array('Transactions: write')));

$v = run(array('transactions:write' => r(404, '{"message":"Route not found"}')));
same('write probe 404 with another message is unverifiable', $v['code'], 'unverifiable');

$v = run(array('transactions:write' => r(401)));
same('401 on write probe after 2xx baseline fails closed as present', array($v['code'], $v['sensitive']), array('sensitive_scope', array('Transactions: write')));

$v = run(array('customers' => r(200)));
same('customers refused', $v['sensitive'], array('Customers'));

$v = run(array('subaccounts' => r(403, $MOR)));
same('subaccounts MOR 403 is sensitive_scope', array($v['code'], $v['sensitive']), array('sensitive_scope', array('Subaccounts')));

$v = run(array('webhooks' => r(404)));
same('404 on refused probe is unverifiable', $v['code'], 'unverifiable');

$v = run(array('webhooks' => r(302)));
same('302 is unverifiable', $v['code'], 'unverifiable');

$v = run(array('connect' => r(429), 'payouts' => r(200)));
same('429 plus proven sensitive scope is sensitive_scope', $v['code'], 'sensitive_scope');

$v = run(array('transactions:read' => r(403, $DENIED), 'webhooks' => r(500)));
same('proven missing beats indeterminate', $v['code'], 'missing_scope');

$v = run(array('webhooks' => r(500), 'payouts' => r(200)));
same('proven sensitive beats indeterminate', $v['code'], 'sensitive_scope');

$v = run(array('transactions:read' => r(403, $DENIED), 'payouts' => r(200)));
same('missing_scope carries the refused list', array($v['code'], $v['missing'], $v['sensitive']), array('missing_scope', array('Transactions: read'), array('Payouts')));

$v = run(array('webhooks' => r(500), 'connect' => r(403, '{"message":"IP"}')));
same('blocked wins over transient', array($v['code'], $v['reason']), array('unverifiable', 'blocked'));

foreach (array(429, 500, 503, 0) as $s) {
    $v = run(array('webhooks' => r($s)));
    same("status $s is transient", array($v['code'], $v['reason']), array('unverifiable', 'transient'));
}

$v = run(array('transactions:read' => r(401), 'webhooks' => r(500)));
same('invalid_key beats everything', $v['code'], 'invalid_key');

// Ident parsing
$identCases = array(
    'id 0' => '{"data":{"id":0,"public_key":"pk_test_abc"}}',
    'id negative' => '{"data":{"id":-1,"public_key":"pk_test_abc"}}',
    'id too large' => '{"data":{"id":2147483648,"public_key":"pk_test_abc"}}',
    'id float' => '{"data":{"id":1.5,"public_key":"pk_test_abc"}}',
    'id missing' => '{"data":{"public_key":"pk_test_abc"}}',
    'id non-numeric string' => '{"data":{"id":"abc","public_key":"pk_test_abc"}}',
    'id leading zero string' => '{"data":{"id":"012","public_key":"pk_test_abc"}}',
    'pk missing' => '{"data":{"id":5}}',
    'pk not a string' => '{"data":{"id":5,"public_key":12}}',
    'pk wrong shape' => '{"data":{"id":5,"public_key":"sk_test_abc"}}',
    'pk trailing newline' => '{"data":{"id":5,"public_key":"pk_test_abc\n"}}',
    'pk wrong mode' => '{"data":{"id":5,"public_key":"pk_live_abc"}}',
    'data missing' => '{"status":true}',
    'not json' => 'oops',
    'empty body' => '',
);
foreach ($identCases as $name => $body) {
    $v = run(array('account:read' => r(200, $body)));
    same("ident $name is account", array($v['code'], $v['public_key']), array('account', null));
}
$v = run(array('account:read' => r(200, '{"data":{"id":"2147483647","public_key":"pk_test_abc"}}')));
same('ident numeric-string max id accepted', $v['code'], 'ok');
$v = run(array('account:read' => r(200, '{"data":{"id":1,"public_key":"pk_test_abc"}}')));
same('ident id 1 accepted', $v['code'], 'ok');
$v = run(array('account:read' => r(200, '{"data":{"id":5,"public_key":"pk_live_abc"}}')), 'live');
same('live ident pk accepted for live key', array($v['code'], $v['public_key']), array('ok', 'pk_live_abc'));
$v = run(array('account:read' => r(200, '{"data":{"id":5,"public_key":"pk_test_abc"}}')), 'live');
same('test ident pk refused for live key', $v['code'], 'account');
$v = run(array('account:read' => r(200, '{"data":{"id":5,"public_key":"pk_live_abc"}}'), 'webhooks' => r(200)));
same('account verdict does not mask a worse one', $v['code'], 'sensitive_scope');

// Slot mode and shape
$calls = null;
$rk = new RK(stub(array(), $calls));
$v = $rk->validate('rk_live_' . str_repeat('a', 20), 'test');
same('live rk in test slot is wrong_mode without probing', array($v['code'], $calls), array('wrong_mode', array()));
$v = $rk->validate('rk_test_' . str_repeat('a', 20), 'live');
same('test rk in live slot is wrong_mode', $v['code'], 'wrong_mode');
$v = $rk->validate('sk_test_abcdef', 'test');
same('sk passed to validate is invalid_key without probing', array($v['code'], $calls), array('invalid_key', array()));
$v = $rk->validate('rk_test_short', 'test');
same('short rk is invalid_key', $v['code'], 'invalid_key');

// Failures never throw and never leak the key
$leak = function (array $requests, $deadline) {
    throw new RuntimeException('boom ' . $requests[0]['key']);
};
$rk = new RK($leak);
$v = $rk->validate($KEY, 'test');
same('throwing requester is unverifiable', array($v['code'], $v['reason']), array('unverifiable', 'transient'));
check('throwing requester result has no key', strpos(json_encode($v), 'SECRETMARKER') === false);

$garbage = function (array $requests, $deadline) {
    return 'nope';
};
$rk = new RK($garbage);
$v = $rk->validate($KEY, 'test');
same('garbage requester result is unverifiable', $v['code'], 'unverifiable');

$batchThrow = 0;
$laterThrow = function (array $requests, $deadline) use (&$batchThrow) {
    if ($batchThrow++ === 0) {
        return array('transactions:read' => r(200));
    }
    throw new RuntimeException('later ' . $requests[0]['key']);
};
$rk = new RK($laterThrow);
$v = $rk->validate($KEY, 'test');
same('requester throwing on the second batch is unverifiable', $v['code'], 'unverifiable');

$scenarios = array(
    run(), run(array('transactions:read' => r(401))), run(array('settlements' => r(200))),
    run(array('account:read' => r(200, 'x' . $KEY))), run(array('webhooks' => r(0, $KEY, 7))),
    run(array('transactions:read' => r(403, $DENIED))),
);
foreach ($scenarios as $i => $s) {
    check("scenario $i result and log have no key", strpos(json_encode($s), 'SECRETMARKER') === false);
}
same('log is scope:status/errno pairs', run(array('webhooks' => r(0, '', 7)))['log'] !== '' && strpos(run(array('webhooks' => r(0, '', 7)))['log'], 'webhooks:0/7') !== false, true);
same('log has the baseline first', strpos(run()['log'], 'transactions:read:200,account:read:200,') === 0, true);
same('missing requester entries count as transport failures', run(array('webhooks' => r(0)))['code'], 'unverifiable');

// decide direct
$mk = function ($scope, $outcome, $status = 200) use ($byScope) {
    return array('probe' => $byScope[$scope], 'outcome' => $outcome, 'status' => $status);
};
same('decide: invalid baseline', RK::decide(array($mk('transactions:read', 'invalid', 401)))['code'], 'invalid_key');
same('decide: lone present baseline is ok', RK::decide(array($mk('transactions:read', 'present')))['code'], 'ok');
same('decide: indeterminate 403 is blocked', RK::decide(array($mk('transactions:read', 'indeterminate', 403)))['reason'], 'blocked');

// Field and key helpers
same('decodeField decodes &amp; and trims', RK::decodeField("  rk_test_a&amp;b&quot;c&lt;d&gt;e&#039;f \n"), "rk_test_a&b\"c<d>e'f");
same('decodeField decodes once only', RK::decodeField('a&amp;amp;b'), 'a&amp;b');
same('decodeField non-string', RK::decodeField(array('x')), '');
same('decodeField null', RK::decodeField(null), '');
same('decodeField whitespace only', RK::decodeField(" \t\r\n"), '');

$rk16 = 'rk_test_' . str_repeat('a', 16);
same('rk minimum length', RK::keyKind($rk16), 'rk');
same('rk too short', RK::keyKind('rk_test_' . str_repeat('a', 15)), null);
same('rk max total 256', RK::keyKind('rk_test_' . str_repeat('a', 248)), 'rk');
same('rk over 256', RK::keyKind('rk_test_' . str_repeat('a', 249)), null);
same('rk allows & " < >', RK::keyKind('rk_live_ab&cd"ef<gh>ij1234567'), 'rk');
same('rk rejects space', RK::keyKind('rk_test_aaaaaaaa aaaaaaaa'), null);
same('rk rejects trailing newline', RK::keyKind($rk16 . "\n"), null);
same('rk rejects CRLF', RK::keyKind($rk16 . "\r\nX: y"), null);
same('rk rejects non-ascii', RK::keyKind('rk_test_' . str_repeat("\xC3\xA9", 10)), null);
same('rk bad mode word', RK::keyKind('rk_prod_' . str_repeat('a', 16)), null);
same('sk shape', RK::keyKind('sk_live_abc123'), 'sk');
same('sk rejects trailing newline', RK::keyKind("sk_live_abc\n"), null);
same('sk rejects empty body', RK::keyKind('sk_live_'), null);
same('sk rejects punctuation', RK::keyKind('sk_live_abc-def'), null);
same('pk is not a key kind', RK::keyKind('pk_test_abc'), null);
same('keyKind non-string', RK::keyKind(null), null);
same('keyKind empty', RK::keyKind(''), null);

same('keyMode rk test', RK::keyMode($rk16), 'test');
same('keyMode rk live', RK::keyMode('rk_live_' . str_repeat('a', 16)), 'live');
same('keyMode sk live', RK::keyMode('sk_live_abc'), 'live');
same('keyMode pk test', RK::keyMode('pk_test_abc'), 'test');
same('keyMode invalid', RK::keyMode('xx_live_abc'), null);
same('keyMode trailing newline', RK::keyMode("sk_live_abc\n"), null);
same('keyMode non-string', RK::keyMode(5), null);

check('isPublicKey ok', RK::isPublicKey('pk_test_abc123'));
check('isPublicKey mode match', RK::isPublicKey('pk_live_abc', 'live'));
check('isPublicKey mode mismatch', !RK::isPublicKey('pk_live_abc', 'test'));
check('isPublicKey trailing newline', !RK::isPublicKey("pk_test_abc\n"));
check('isPublicKey script', !RK::isPublicKey("pk_test_abc');alert(1);//"));
check('isPublicKey empty body', !RK::isPublicKey('pk_test_'));
check('isPublicKey sk', !RK::isPublicKey('sk_test_abc'));
check('isPublicKey non-string', !RK::isPublicKey(null));

check('isWebhookSecret ok', RK::isWebhookSecret('whsec_abc123'));
check('isWebhookSecret trailing newline', !RK::isWebhookSecret("whsec_abc\n"));
check('isWebhookSecret empty', !RK::isWebhookSecret('whsec_'));
check('isWebhookSecret space', !RK::isWebhookSecret('whsec_a b'));

same('last4 of long key', RK::last4('rk_test_' . str_repeat('a', 12) . 'wxyz'), 'wxyz');
same('last4 of 20 chars', RK::last4(str_repeat('a', 16) . 'wxyz'), 'wxyz');
same('last4 of 19 chars is null', RK::last4(str_repeat('a', 19)), null);
same('last4 non-string', RK::last4(null), null);

// planMode
$SK = 'sk_test_abcdef123456';
$SK_LIVE = 'sk_live_abcdef123456';
$RK = 'rk_test_' . str_repeat('a', 20);
$RK_LIVE = 'rk_live_' . str_repeat('b', 20);
$PK = 'pk_test_abc123';
$PK_LIVE = 'pk_live_abc123';
function plan(array $over)
{
    return RK::planMode(array_merge(array(
        'posted' => '', 'stored' => '', 'stored_public' => '', 'mode' => 'test',
        'is_active' => false, 'becoming_active' => false, 'remove' => false,
    ), $over));
}
function act($p)
{
    return array($p['action'], $p['error']);
}

same('blank, nothing stored, active: missing_key', act(plan(array('is_active' => true))), array('reject', 'missing_key'));
same('blank, nothing stored, becoming active: missing_key', act(plan(array('becoming_active' => true))), array('reject', 'missing_key'));
same('blank, nothing stored, inactive: empty', act(plan(array())), array('empty', null));
same('empty has no key', plan(array())['key'], '');

$p = plan(array('posted' => $RK_LIVE, 'stored' => $RK, 'is_active' => true));
same('rk wrong slot mode', act($p), array('reject', 'wrong_mode'));
$p = plan(array('posted' => 'rk_test_short', 'is_active' => true));
same('bad shape rk', act($p), array('reject', 'bad_shape'));
$p = plan(array('posted' => "rk_test_" . str_repeat('a', 20) . "\n"));
same('bad shape trailing newline', act($p), array('reject', 'bad_shape'));
$p = plan(array('posted' => $RK_LIVE, 'mode' => 'test'));
same('live rk in test slot even when inactive', act($p), array('reject', 'wrong_mode'));
$p = plan(array('posted' => $SK_LIVE, 'mode' => 'test', 'is_active' => true));
same('posted sk of either mode is refused', act($p), array('reject', 'sk_not_accepted'));

$p = plan(array('posted' => $RK, 'stored' => '', 'is_active' => true));
same('rk changed: probe', act($p), array('probe', null));
same('probe key and public', array($p['key'], $p['public']), array($RK, null));
$p = plan(array('posted' => $RK, 'stored' => $RK_LIVE, 'mode' => 'test'));
same('rk changed on inactive mode: probe', $p['action'], 'probe');
$p = plan(array('posted' => $RK, 'stored' => $SK, 'is_active' => true));
same('sk to rk: probe', $p['action'], 'probe');

$p = plan(array('stored' => $RK, 'stored_public' => $PK, 'is_active' => true));
same('rk unchanged (blank) with good stored pk: keep_rk', act($p), array('keep_rk', null));
same('keep_rk returns stored pk and key', array($p['key'], $p['public']), array($RK, $PK));
$p = plan(array('posted' => $RK, 'stored' => $RK, 'stored_public' => $PK, 'is_active' => true));
same('rk unchanged (re-pasted) with good stored pk: keep_rk', $p['action'], 'keep_rk');
$p = plan(array('stored' => $RK, 'stored_public' => $PK, 'is_active' => false));
same('rk unchanged on inactive mode: keep_rk', $p['action'], 'keep_rk');
$p = plan(array('stored' => $RK, 'stored_public' => $PK, 'is_active' => true, 'becoming_active' => true));
same('rk unchanged but becoming active: probe', $p['action'], 'probe');
$p = plan(array('stored' => $RK, 'stored_public' => '', 'is_active' => true));
same('rk unchanged, stored pk missing on active: probe', $p['action'], 'probe');
$p = plan(array('stored' => $RK, 'stored_public' => $PK_LIVE, 'is_active' => true));
same('rk unchanged, stored pk wrong mode on active: probe', $p['action'], 'probe');
$p = plan(array('stored' => $RK, 'stored_public' => "pk_test_abc\n", 'is_active' => true));
same('rk unchanged, stored pk malformed on active: probe', $p['action'], 'probe');
$p = plan(array('stored' => $RK, 'stored_public' => $PK_LIVE, 'is_active' => false));
same('inactive rk with bad stored pk keeps rk with empty pk', array($p['action'], $p['public']), array('keep_rk', ''));

// Secret keys: a saved one keeps working with its saved public key; a newly pasted one is refused.
$p = plan(array('posted' => $SK, 'stored' => $RK, 'stored_public' => $PK, 'is_active' => true));
same('posted sk refused on active', act($p), array('reject', 'sk_not_accepted'));
$p = plan(array('posted' => $SK, 'is_active' => false));
same('posted sk refused on inactive', act($p), array('reject', 'sk_not_accepted'));
$p = plan(array('posted' => $SK, 'stored' => $SK, 'stored_public' => $PK, 'is_active' => true));
same('re-pasting the saved sk is refused too', act($p), array('reject', 'sk_not_accepted'));
$p = plan(array('stored' => $SK, 'stored_public' => $PK, 'is_active' => true));
same('stored sk blank on active: keep_sk', act($p), array('keep_sk', null));
same('keep_sk keeps stored key and stored pk', array($p['key'], $p['public']), array($SK, $PK));
$p = plan(array('stored' => $SK, 'stored_public' => $PK, 'becoming_active' => true));
same('stored sk becoming active with good pk: keep_sk', act($p), array('keep_sk', null));
// An unusable saved pair can't be re-entered any more, so it only blocks switching that mode on.
$p = plan(array('stored' => $SK, 'stored_public' => '', 'is_active' => true));
same('stored sk with no stored pk on active is kept', array($p['action'], $p['key'], $p['public']), array('keep_sk', $SK, ''));
$p = plan(array('stored' => $SK, 'stored_public' => $PK_LIVE, 'is_active' => true));
same('stored sk with wrong-mode stored pk on active is kept as saved', array($p['action'], $p['public']), array('keep_sk', $PK_LIVE));
$p = plan(array('stored' => 'SK_test_abc-def', 'stored_public' => 'Pk_Test_x', 'is_active' => true));
same('pair v2.1.0 accepted but now invalid is kept on active', array($p['action'], $p['key'], $p['public']), array('keep_sk', 'SK_test_abc-def', 'Pk_Test_x'));
$p = plan(array('stored' => $SK, 'stored_public' => '', 'becoming_active' => true));
same('stored sk with no stored pk becoming active is refused', act($p), array('reject', 'stored_key_invalid'));
$p = plan(array('stored' => $SK_LIVE, 'stored_public' => $PK, 'becoming_active' => true));
same('stored sk of the other mode becoming active is refused', act($p), array('reject', 'stored_key_invalid'));
$p = plan(array('stored' => $SK, 'stored_public' => '', 'is_active' => false));
same('stored sk with no pk on inactive is kept', array($p['action'], $p['key'], $p['public']), array('keep_sk', $SK, ''));
$p = plan(array('stored' => $SK_LIVE, 'stored_public' => $PK_LIVE, 'is_active' => false));
same('stored sk of the other mode on inactive is kept as saved', array($p['action'], $p['key'], $p['public']), array('keep_sk', $SK_LIVE, $PK_LIVE));
$p = plan(array('posted' => $RK, 'stored' => $SK, 'stored_public' => $PK, 'is_active' => true));
same('rk pasted over a stored sk: probe', $p['action'], 'probe');
$p = plan(array('stored' => 'legacy-garbage', 'is_active' => false));
same('unusable legacy stored key on inactive mode is kept untouched', array($p['action'], $p['key']), array('keep_sk', 'legacy-garbage'));
$p = plan(array('stored' => 'legacy-garbage', 'is_active' => true));
same('unusable legacy stored key on active mode is kept', array($p['action'], $p['key']), array('keep_sk', 'legacy-garbage'));
$p = plan(array('stored' => 'legacy-garbage', 'becoming_active' => true));
same('unusable legacy stored key on a mode becoming active is refused', act($p), array('reject', 'stored_key_invalid'));
$p = plan(array('posted' => 'legacy-garbage', 'is_active' => true));
same('unusable posted key is still bad_shape', act($p), array('reject', 'bad_shape'));

// planMode: public is always final
$p = plan(array('stored_public' => $PK));
same('empty keeps a valid stored pk', array($p['action'], $p['public']), array('empty', $PK));
$p = plan(array('stored_public' => $PK_LIVE));
same('empty drops a wrong-mode stored pk', $p['public'], '');
$p = plan(array('stored' => 'legacy-garbage', 'stored_public' => $PK));
same('legacy keep_sk keeps a valid stored pk', array($p['action'], $p['public']), array('keep_sk', $PK));
$p = plan(array('stored' => 'legacy-garbage', 'stored_public' => 'junk'));
same('legacy keep_sk keeps the stored pk as saved', $p['public'], 'junk');
$p = plan(array('stored' => $RK, 'stored_public' => $PK, 'is_active' => true));
same('keep_rk public is final', $p['public'], $PK);

$p = plan(array('remove' => true, 'stored' => $SK, 'is_active' => false));
same('remove on inactive: clear', array($p['action'], $p['key'], $p['public']), array('clear', '', ''));
$p = plan(array('remove' => true, 'stored' => $SK, 'is_active' => true));
same('remove on active: cannot_remove_active', act($p), array('reject', 'cannot_remove_active'));
$p = plan(array('remove' => true, 'stored' => $SK, 'becoming_active' => true));
same('remove on a mode becoming active is refused', act($p), array('reject', 'cannot_remove_active'));
$p = plan(array('remove' => true, 'posted' => $SK, 'is_active' => true));
same('remove beats a posted key on active', act($p), array('reject', 'cannot_remove_active'));

$p = plan(array('posted' => $RK_LIVE, 'mode' => 'live', 'stored' => '', 'is_active' => true));
same('live mode rk: probe', $p['action'], 'probe');
$p = plan(array('posted' => 'x', 'stored' => $RK, 'is_active' => true));
same('bad posted key does not fall back to stored', act($p), array('reject', 'bad_shape'));

// planWebhookSecret
$WS = 'whsec_abc123';
$wsp = function (array $over) {
    return WH::planWebhookSecret(array_merge(array('posted' => '', 'stored' => '', 'remove' => false), $over));
};
same('whsec blank keeps stored', $wsp(array('stored' => $WS)), array('value' => $WS, 'error' => null));
same('whsec blank nothing stored', $wsp(array()), array('value' => '', 'error' => null));
same('whsec posted valid', $wsp(array('posted' => 'whsec_new', 'stored' => $WS)), array('value' => 'whsec_new', 'error' => null));
same('whsec posted invalid keeps stored with error', $wsp(array('posted' => 'nope', 'stored' => $WS)), array('value' => $WS, 'error' => 'bad_shape'));
same('whsec remove clears', $wsp(array('remove' => true, 'stored' => $WS)), array('value' => '', 'error' => null));
same('whsec remove beats a posted value', $wsp(array('remove' => true, 'posted' => 'whsec_new', 'stored' => $WS)), array('value' => '', 'error' => null));
same('whsec remove beats an invalid posted value', $wsp(array('remove' => true, 'posted' => 'nope')), array('value' => '', 'error' => null));
same('whsec non-string inputs', $wsp(array('posted' => null, 'stored' => null)), array('value' => '', 'error' => null));

// planMode: remaining branches
$p = plan(array('remove' => true, 'stored' => $SK, 'stored_public' => $PK));
same('clear drops the stored pk', array($p['action'], $p['public']), array('clear', ''));
$p = plan(array('posted' => $RK, 'stored' => $RK, 'stored_public' => $PK, 'is_active' => false, 'becoming_active' => true));
same('rk unchanged becoming active but inactive flag unset: probe', $p['action'], 'probe');
$p = plan(array('posted' => 5, 'stored' => 7, 'stored_public' => array(), 'is_active' => false));
same('non-string inputs behave as blank', act($p), array('empty', null));
$p = plan(array('stored' => 'legacy-garbage', 'is_active' => false, 'stored_public' => $PK_LIVE));
same('legacy keep_sk keeps a wrong-mode stored pk as saved', $p['public'], $PK_LIVE);
same('planWebhookSecret trailing newline is bad_shape', $wsp(array('posted' => "whsec_abc\n", 'stored' => $WS)), array('value' => $WS, 'error' => 'bad_shape'));
same('planWebhookSecret bare prefix is bad_shape', $wsp(array('posted' => 'whsec_', 'stored' => '')), array('value' => '', 'error' => 'bad_shape'));
same('planWebhookSecret sk-shaped is bad_shape', $wsp(array('posted' => 'sk_test_abc', 'stored' => $WS)), array('value' => $WS, 'error' => 'bad_shape'));

// modeFlags
same('flags: live mode, live posted, unchanged', RK::modeFlags('live', true, true, 1, 1), array('is_active' => true, 'becoming_active' => false));
same('flags: test mode inactive when live posted', RK::modeFlags('test', true, true, 1, 1), array('is_active' => false, 'becoming_active' => false));
same('flags: test mode active, unchanged', RK::modeFlags('test', false, false, 1, 1), array('is_active' => true, 'becoming_active' => false));
same('flags: switching test to live', RK::modeFlags('live', true, false, 1, 1), array('is_active' => true, 'becoming_active' => true));
same('flags: switching live to test', RK::modeFlags('test', false, true, 1, 1), array('is_active' => true, 'becoming_active' => true));
same('flags: inactive mode never becomes active', RK::modeFlags('test', true, false, 1, 1), array('is_active' => false, 'becoming_active' => false));
same('flags: status 0 to 1 for the active mode', RK::modeFlags('test', false, false, '1', '0'), array('is_active' => true, 'becoming_active' => true));
same('flags: status 0 to 1 with nothing stored', RK::modeFlags('test', false, false, '1', null), array('is_active' => true, 'becoming_active' => true));
same('flags: status 0 to 1 does not touch the other mode', RK::modeFlags('live', false, false, '1', '0'), array('is_active' => false, 'becoming_active' => false));
same('flags: status 1 to 0', RK::modeFlags('test', false, false, '0', '1'), array('is_active' => true, 'becoming_active' => false));
same('flags: status stays 1', RK::modeFlags('test', false, false, '1', '1'), array('is_active' => true, 'becoming_active' => false));

// Save-level deadline
$calls = null;
$rk = new RK(stub(array(), $calls));
$v = $rk->validate($KEY, 'test', microtime(true) - 1);
same('past deadline: unverifiable', array($v['code'], $v['reason']), array('unverifiable', 'transient'));
same('past deadline: no request sent', $calls, array());
$seen = array();
$rk = new RK(function (array $requests, $deadline) use (&$seen) {
    $seen[] = $deadline;
    $m = goodResponses();
    $out = array();
    foreach ($requests as $req) {
        $out[$req['id']] = $m[$req['id']];
    }
    return $out;
});
$at = microtime(true) + 7;
$v = $rk->validate($KEY, 'test', $at);
same('deadline honoured: ok', $v['code'], 'ok');
check('deadline is passed on as the time left', count($seen) === 2 && $seen[0] <= 7 && $seen[0] > 6 && $seen[1] <= $seen[0]);
$seen = array();
$v = $rk->validate($KEY, 'test');
check('default deadline is 20s', $v['code'] === 'ok' && $seen[0] <= 20 && $seen[0] > 19);

// Deadline expiring between the baseline and the batch
$calls = 0;
$rk = new RK(function (array $requests, $deadline) use (&$calls) {
    $calls++;
    usleep(150000);
    $m = goodResponses();
    $out = array();
    foreach ($requests as $req) {
        $out[$req['id']] = $m[$req['id']];
    }
    return $out;
});
$v = $rk->validate($KEY, 'test', microtime(true) + 0.05);
same('deadline expires after baseline: unverifiable, batch not sent', array($v['code'], $v['reason'], $calls), array('unverifiable', 'transient', 1));
same('deadline expired: log placeholder', $v['log'], '-');
$v = (new RK(stub()))->validate($KEY, 'test', 0.0);
same('deadline of exactly zero is expired', $v['code'], 'unverifiable');

// Http::request without network
same('Http with no requests returns nothing', \Paystack\Http::request(array(), 1), array());

// Default requester sanity: no network, only constructs
check('default requester is callable', is_callable(array('Paystack\Http', 'request')));
check('base url is https only', strpos(\Paystack\Http::BASE_URL, 'https://') === 0);

finish();
