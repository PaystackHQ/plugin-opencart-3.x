<?php

namespace Paystack;

class Payment
{
    public static function parseReference($ref)
    {
        if (!is_string($ref) || !preg_match('/^([1-9]\d*)-[0-9a-f]{13}\z/', $ref, $m)) {
            return null;
        }
        if (strlen($m[1]) > 18) {
            return null;
        }
        return (int) $m[1];
    }

    // Statuses a verified payment may move an order out of: OpenCart's 0 and the pending status, never the paid one.
    public static function unpaidStatusIds($pendingId, $paidId)
    {
        $paid = self::statusId($paidId);
        $pending = self::statusId($pendingId);
        $ids = array(0);
        if ($pending !== null && $pending !== $paid && $pending !== 0) {
            $ids[] = $pending;
        }
        return $ids;
    }

    // 'proceed' | 'already_paid' | 'ignore'. Unpaid is checked first because OpenCart's default
    // processing statuses include Pending, which is also the plugin's unpaid status.
    public static function statusGate($current, array $unpaidIds, array $paidIds)
    {
        if (in_array($current, $unpaidIds, true)) {
            return 'proceed';
        }
        return in_array($current, $paidIds, true) ? 'already_paid' : 'ignore';
    }

    // A 200 verify body that confirms a successful charge for exactly this reference.
    public static function isVerifiedSuccess($response, $reference)
    {
        return is_array($response) && isset($response['status'], $response['data']) && $response['status'] === true
            && is_array($response['data']) && isset($response['data']['reference'], $response['data']['status'])
            && $response['data']['reference'] === $reference && $response['data']['status'] === 'success';
    }

    private static function statusId($value)
    {
        if ($value === null || $value === '' || $value === false) {
            return null;
        }
        return (int) $value;
    }

    public static function paymentDecision(array $in)
    {
        $response = isset($in['response']) ? $in['response'] : null;
        if (!isset($in['http_status']) || $in['http_status'] !== 200 || !is_array($response)) {
            return 'ignore';
        }
        if (!isset($response['status']) || $response['status'] !== true) {
            return 'ignore';
        }
        $data = isset($response['data']) ? $response['data'] : null;
        if (!is_array($data) || !isset($data['reference']) || !isset($in['reference'])
            || !is_string($data['reference']) || $data['reference'] !== $in['reference']) {
            return 'ignore';
        }
        $orderId = self::parseReference($in['reference']);
        if ($orderId === null || $orderId !== $in['order_id']) {
            return 'ignore';
        }
        if (!in_array($in['order_status_id'], $in['unpaid_status_ids'], true)) {
            return 'ignore';
        }
        if (!isset($data['status']) || $data['status'] !== 'success') {
            return 'not_paid';
        }

        // A requested_amount above the charged amount would hide an underpayment.
        $amount = isset($data['amount']) ? $data['amount'] : null;
        if (isset($data['requested_amount']) && is_int($data['requested_amount'])
            && is_int($amount) && $amount >= $data['requested_amount']) {
            $amount = $data['requested_amount'];
        }
        if (!is_int($amount)) {
            return 'ignore';
        }
        $currency = isset($data['currency']) && is_string($data['currency']) ? $data['currency'] : '';
        if ($amount === $in['expected_amount'] && strtoupper($currency) === strtoupper($in['currency'])) {
            return 'paid';
        }
        return 'mismatch';
    }
}
