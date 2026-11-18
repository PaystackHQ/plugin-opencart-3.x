<?php

namespace Paystack;

class Webhook
{
    public static function verifySignature(
        $header,
        $input,
        #[\SensitiveParameter]
        $whsec,
        $now
    ) {
        if (!is_string($header) || !is_string($input) || !is_string($whsec) || $whsec === '') {
            return false;
        }
        $t = array();
        $v1 = array();
        foreach (explode(',', $header) as $part) {
            $pair = explode('=', trim($part), 2);
            if (count($pair) !== 2) {
                continue;
            }
            if ($pair[0] === 't') {
                $t[] = $pair[1];
            } elseif ($pair[0] === 'v1') {
                $pos = strpos($pair[1], ':');
                $v1[] = $pos === false ? $pair[1] : substr($pair[1], $pos + 1);
            }
        }
        if (count($t) !== 1 || !ctype_digit($t[0]) || strlen($t[0]) > 15 || !$v1) {
            return false;
        }
        // Without a timestamp window a captured webhook could be replayed forever.
        if (abs($now - (int) $t[0]) > 300) {
            return false;
        }
        $expected = hash_hmac('sha512', $t[0] . '.' . $input, $whsec);
        $ok = false;
        foreach ($v1 as $candidate) {
            if (hash_equals($expected, $candidate)) {
                $ok = true;
            }
        }
        return $ok;
    }

    public static function isWebhookAuthentic(
        $input,
        #[\SensitiveParameter]
        $whsec,
        #[\SensitiveParameter]
        $secretKey,
        array $server,
        $now
    ) {
        if (!is_string($input)) {
            return false;
        }
        if (is_string($whsec) && $whsec !== '') {
            // Once a signing secret is set the legacy header is refused, so a leaked sk can't forge webhooks.
            return isset($server['HTTP_PAYSTACK_SIGNATURE']) && is_string($server['HTTP_PAYSTACK_SIGNATURE'])
                && self::verifySignature($server['HTTP_PAYSTACK_SIGNATURE'], $input, $whsec, $now);
        }
        if (Restricted_Key::keyKind($secretKey) === 'sk'
            && isset($server['HTTP_X_PAYSTACK_SIGNATURE']) && is_string($server['HTTP_X_PAYSTACK_SIGNATURE'])
            && $server['HTTP_X_PAYSTACK_SIGNATURE'] !== '') {
            return hash_equals(hash_hmac('sha512', $input, $secretKey), $server['HTTP_X_PAYSTACK_SIGNATURE']);
        }
        return false;
    }
}
