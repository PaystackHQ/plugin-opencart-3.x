<?php

namespace Paystack;

class Restricted_Key
{
    const OVERALL_DEADLINE = 20;
    const SCOPE_DENIED_MESSAGE = 'This restricted key is not permitted to perform this action';
    const STUB_MESSAGE = 'Sorry, this feature is no longer available.';
    const MOR_MESSAGE = 'This feature is not available to merchant of record integrations.';
    const IDENT_SCOPE = 'account:read';

    private $requester;

    public function __construct(?callable $requester = null)
    {
        $this->requester = $requester ? $requester : array('Paystack\\Http', 'request');
    }

    // Table order is the reporting order. Never add a write-capable route. transactions:write, customers and
    // balance are refused because this plugin never needs them; a merchant-of-record 403 on subaccounts
    // proves the scope passed.
    public static function probes()
    {
        $refused = array(
            array('transactions:write', 'Transactions: write', 'POST', 'transaction/check_authorization', '{}', 'stub'),
            array('disputes_refunds', 'Disputes and Refunds', 'GET', 'refund?perPage=1'),
            array('bulk_charges', 'Bulk Charges', 'GET', 'bulkcharge'),
            array('direct_debit', 'Direct Debit', 'GET', 'directdebit/account'),
            array('preauthorizations', 'Preauthorizations', 'GET', 'preauthorization'),
            array('settlements', 'Settlements', 'GET', 'settlement?perPage=1'),
            array('subaccounts', 'Subaccounts', 'GET', 'subaccount', null, 'granted'),
            array('transfers', 'Transfers', 'GET', 'transfer/config'),
            array('virtual_accounts', 'Virtual Accounts', 'GET', 'pay-with-transfer/custom-accounts/status'),
            array('webhooks', 'Webhooks', 'GET', 'integration/webhooks/events'),
            array('connect', 'Connect', 'GET', 'connect/platforms'),
            array('payouts', 'Payouts', 'GET', 'settlement/instant/profile'),
            array('customers', 'Customers', 'GET', 'customer?perPage=1&from=2099-01-01'),
            array('balance', 'Balance', 'GET', 'balance'),
        );

        $probes = array(
            array('scope' => 'transactions:read', 'label' => 'Transactions: read', 'required' => true, 'sensitive' => false,
                'method' => 'GET', 'path' => 'transaction?perPage=1', 'body' => null, 'present' => 'ok'),
            array('scope' => 'account:read', 'label' => 'Account: read', 'required' => true, 'sensitive' => false,
                'method' => 'GET', 'path' => 'ident', 'body' => null, 'present' => 'ok'),
        );
        foreach ($refused as $r) {
            $probes[] = array('scope' => $r[0], 'label' => $r[1], 'required' => false, 'sensitive' => true,
                'method' => $r[2], 'path' => $r[3], 'body' => isset($r[4]) ? $r[4] : null,
                'present' => isset($r[5]) ? $r[5] : 'refused');
        }
        return $probes;
    }

    public static function decodeField($raw)
    {
        if (!is_string($raw)) {
            return '';
        }
        return trim(html_entity_decode($raw, ENT_QUOTES, 'UTF-8'));
    }

    public static function keyKind($key)
    {
        if (!is_string($key)) {
            return null;
        }
        if (strlen($key) <= 256 && preg_match('/^rk_(test|live)_[\x21-\x7E]{16,}\z/', $key)) {
            return 'rk';
        }
        if (preg_match('/^sk_(test|live)_\w+\z/', $key)) {
            return 'sk';
        }
        return null;
    }

    public static function keyMode($key, $kind = null)
    {
        if (!is_string($key)) {
            return null;
        }
        if ($kind === null && self::keyKind($key) === null && !self::isPublicKey($key)) {
            return null;
        }
        return substr($key, 3, 4) === 'live' ? 'live' : 'test';
    }

    public static function isPublicKey($pk, $mode = null)
    {
        if (!is_string($pk) || !preg_match('/^pk_(test|live)_\w+\z/', $pk, $m)) {
            return false;
        }
        return $mode === null || $m[1] === $mode;
    }

    public static function isWebhookSecret($v)
    {
        return is_string($v) && preg_match('/^whsec_[\x21-\x7E]+\z/', $v) === 1;
    }

    public static function last4($key)
    {
        if (!is_string($key) || strlen($key) < 20) {
            return null;
        }
        return substr($key, -4);
    }

    public static function planMode(array $in)
    {
        $posted = self::str($in, 'posted');
        $stored = self::str($in, 'stored');
        $storedPublic = self::str($in, 'stored_public');
        $mode = isset($in['mode']) ? $in['mode'] : '';
        $isActive = !empty($in['is_active']);
        $becoming = !empty($in['becoming_active']);
        $live = $isActive || $becoming;
        $storedPkOk = self::isPublicKey($storedPublic, $mode);

        if (!empty($in['remove'])) {
            if ($live) {
                return self::plan('reject', '', null, 'cannot_remove_active');
            }
            return self::plan('clear', '', '', null);
        }

        if ($posted === '') {
            if ($stored === '') {
                return $live ? self::plan('reject', '', null, 'missing_key') : self::plan('empty', '', $storedPkOk ? $storedPublic : '', null);
            }
            if (self::keyKind($stored) !== 'rk') {
                // Keys saved before restricted keys were supported are kept exactly as saved until the merchant
                // pastes a restricted key, since the form can no longer re-enter them. They are only refused when
                // a mode is being switched on, because then the merchant is choosing to take payments with them.
                $usable = self::keyKind($stored) === 'sk' && self::keyMode($stored) === $mode && $storedPkOk;
                if ($becoming && !$usable) {
                    return self::plan('reject', '', null, 'stored_key_invalid');
                }
                return self::plan('keep_sk', $stored, $storedPublic, null);
            }
        }

        $key = $posted === '' ? $stored : $posted;
        $kind = self::keyKind($key);
        if ($kind === 'sk') {
            return self::plan('reject', '', null, 'sk_not_accepted');
        }
        if ($kind === null) {
            return self::plan('reject', '', null, 'bad_shape');
        }
        if (self::keyMode($key, $kind) !== $mode) {
            return self::plan('reject', '', null, 'wrong_mode');
        }

        if ($key !== $stored || $becoming || ($isActive && !$storedPkOk)) {
            return self::plan('probe', $key, null, null);
        }
        return self::plan('keep_rk', $key, $storedPkOk ? $storedPublic : '', null);
    }

    // Enabling the extension (status 0 to 1) counts as going live for the mode in use, so its scopes are re-checked.
    public static function modeFlags($mode, $postedLive, $storedLive, $postedStatus, $storedStatus)
    {
        $isLive = $mode === 'live';
        $active = $isLive === (bool) $postedLive;
        $switched = $isLive !== (bool) $storedLive;
        $enabled = (bool) $postedStatus && !$storedStatus;
        return array('is_active' => $active, 'becoming_active' => $active && ($switched || $enabled));
    }

    private static function str(array $in, $k)
    {
        return isset($in[$k]) && is_string($in[$k]) ? $in[$k] : '';
    }

    private static function plan($action, $key, $public, $error)
    {
        return array('action' => $action, 'key' => $key, 'public' => $public, 'error' => $error);
    }

    public static function messageOf($body)
    {
        if (!is_string($body)) {
            return null;
        }
        $json = json_decode($body, true);
        if (is_array($json) && isset($json['message']) && is_string($json['message'])) {
            return $json['message'];
        }
        return $body;
    }

    private static function is2xx($status)
    {
        return $status >= 200 && $status <= 299;
    }

    private static function isPresent(array $probe, $status, $body)
    {
        switch ($probe['present']) {
            case 'refused':
                return self::is2xx($status) || $status === 400;
            case 'granted':
                return self::is2xx($status) || $status === 400
                    || ($status === 403 && self::messageOf($body) === self::MOR_MESSAGE);
            case 'stub':
                return $status === 404 && self::messageOf($body) === self::STUB_MESSAGE;
            default:
                return self::is2xx($status);
        }
    }

    // Returns 'invalid' | 'absent' | 'present' | 'indeterminate'; $baselineOutcome is null for the baseline itself.
    public static function classify(array $probe, $status, $body, $baselineOutcome)
    {
        if ($status === 401) {
            if ($baselineOutcome === null) {
                return 'invalid';
            }
            // Only a present baseline proves the key is valid, so a 401 there on a refused scope fails closed.
            return $baselineOutcome === 'present' && !empty($probe['sensitive']) ? 'present' : 'indeterminate';
        }
        if ($status === 403 && self::messageOf($body) === self::SCOPE_DENIED_MESSAGE) {
            return 'absent';
        }
        return self::isPresent($probe, $status, $body) ? 'present' : 'indeterminate';
    }

    private static function verdict($code, array $missing = array(), array $sensitive = array(), $reason = null, $log = '')
    {
        return array('code' => $code, 'missing' => $missing, 'sensitive' => $sensitive, 'reason' => $reason,
            'public_key' => null, 'log' => $log);
    }

    // $results: list of ['probe' => probe, 'outcome' => string, 'status' => int], baseline first.
    public static function decide(array $results)
    {
        if ($results[0]['outcome'] === 'invalid') {
            return self::verdict('invalid_key');
        }

        $missing = array();
        $sensitive = array();
        foreach ($results as $r) {
            if (!empty($r['probe']['required']) && $r['outcome'] === 'absent') {
                $missing[] = $r['probe']['label'];
            }
            if (!empty($r['probe']['sensitive']) && $r['outcome'] === 'present') {
                $sensitive[] = $r['probe']['label'];
            }
        }
        if ($missing) {
            return self::verdict('missing_scope', $missing, $sensitive);
        }
        if ($sensitive) {
            return self::verdict('sensitive_scope', array(), $sensitive);
        }

        $blocked = false;
        $indeterminate = false;
        foreach ($results as $r) {
            if ($r['outcome'] === 'indeterminate') {
                $indeterminate = true;
                $blocked = $blocked || $r['status'] === 403;
            }
        }
        if ($indeterminate) {
            return self::verdict('unverifiable', array(), array(), $blocked ? 'blocked' : 'transient');
        }
        return self::verdict('ok');
    }

    private static function parseIdent($body, $mode)
    {
        $json = is_string($body) ? json_decode($body, true) : null;
        $data = is_array($json) && isset($json['data']) && is_array($json['data']) ? $json['data'] : null;
        if ($data === null || !isset($data['id'], $data['public_key'])) {
            return null;
        }
        $id = $data['id'];
        if (is_string($id) && preg_match('/^[1-9]\d{0,9}\z/', $id)) {
            $id = (int) $id;
        }
        if (!is_int($id) || $id < 1 || $id > 2147483647) {
            return null;
        }
        return self::isPublicKey($data['public_key'], $mode) ? $data['public_key'] : null;
    }

    private function request(
        #[\SensitiveParameter]
        array $requests,
        $deadline
    )
    {
        try {
            $raw = call_user_func($this->requester, $requests, max(0.1, $deadline));
        } catch (\Throwable $e) {
            $raw = array();
        }
        $out = array();
        foreach ($requests as $req) {
            $r = is_array($raw) && isset($raw[$req['id']]) && is_array($raw[$req['id']]) ? $raw[$req['id']] : array();
            $out[$req['id']] = array(
                'status' => isset($r['status']) ? (int) $r['status'] : 0,
                'body' => isset($r['body']) && is_string($r['body']) ? $r['body'] : '',
                'errno' => isset($r['errno']) ? (int) $r['errno'] : 0,
            );
        }
        return $out;
    }

    private static function toRequest(
        array $probe,
        #[\SensitiveParameter]
        $key
    )
    {
        return array('id' => $probe['scope'], 'method' => $probe['method'], 'path' => $probe['path'],
            'body' => $probe['body'], 'key' => $key);
    }

    private static function timedOut()
    {
        return self::verdict('unverifiable', array(), array(), 'transient', '-');
    }

    public function validate(
        #[\SensitiveParameter]
        $key,
        $mode,
        $deadlineAt = null
    ) {
        try {
            if ($deadlineAt === null) {
                $deadlineAt = microtime(true) + self::OVERALL_DEADLINE;
            }
            if ($mode !== 'test' && $mode !== 'live') {
                return self::verdict('wrong_mode', array(), array(), null, '-');
            }
            $kind = self::keyKind($key);
            if ($kind !== 'rk') {
                return self::verdict('invalid_key', array(), array(), null, '-');
            }
            if (self::keyMode($key, $kind) !== $mode) {
                return self::verdict('wrong_mode', array(), array(), null, '-');
            }

            $probes = self::probes();
            $baselineProbe = array_shift($probes);
            $results = array();
            $identBody = '';

            if ($deadlineAt - microtime(true) <= 0) {
                return self::timedOut();
            }
            $res = $this->request(array(self::toRequest($baselineProbe, $key)), $deadlineAt - microtime(true));
            $r = $res[$baselineProbe['scope']];
            $baseline = self::classify($baselineProbe, $r['status'], $r['body'], null);
            $results[] = array('probe' => $baselineProbe, 'outcome' => $baseline, 'status' => $r['status'],
                'errno' => $r['errno']);

            // An absent baseline still proves the key is valid, so every other scope can be named.
            if ($baseline === 'present' || $baseline === 'absent') {
                $requests = array();
                foreach ($probes as $p) {
                    $requests[] = self::toRequest($p, $key);
                }
                if ($deadlineAt - microtime(true) <= 0) {
                    return self::timedOut();
                }
                $res = $this->request($requests, $deadlineAt - microtime(true));
                foreach ($probes as $p) {
                    $r = $res[$p['scope']];
                    $results[] = array('probe' => $p, 'outcome' => self::classify($p, $r['status'], $r['body'], $baseline),
                        'status' => $r['status'], 'errno' => $r['errno']);
                    if ($p['scope'] === self::IDENT_SCOPE) {
                        $identBody = $r['body'];
                    }
                }
            }

            $verdict = self::decide($results);
            if ($verdict['code'] === 'ok') {
                $pk = self::parseIdent($identBody, $mode);
                if ($pk === null) {
                    $verdict = self::verdict('account');
                } else {
                    $verdict['public_key'] = $pk;
                }
            }

            $log = array();
            foreach ($results as $r) {
                $log[] = $r['probe']['scope'] . ':' . $r['status'] . ($r['errno'] ? '/' . $r['errno'] : '');
            }
            $verdict['log'] = implode(',', $log);
            return $verdict;
        } catch (\Throwable $e) {
            return self::timedOut();
        }
    }
}
