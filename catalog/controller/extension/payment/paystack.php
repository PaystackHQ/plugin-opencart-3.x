<?php
class open_cart_paystack_plugin_tracker
{
    var $public_key;
    var $plugin_name;
    function __construct($plugin, $pk)
    {
        //configure plugin name
        //configure public key
        $this->plugin_name = $plugin;
        $this->public_key = $pk;
    }



    function log_transaction_success($trx_ref)
    {
        //send reference to logger along with plugin name and public key
        $url = "https://plugin-tracker.paystackintegrations.com/log/charge_success";

        $fields = [
            'plugin_name'  => $this->plugin_name,
            'transaction_reference' => $trx_ref,
            'public_key' => $this->public_key
        ];

        $fields_string = http_build_query($fields);

        $ch = curl_init();

        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $fields_string);

        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 3);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 3);

        //execute post
        $result = curl_exec($ch);
        //  echo $result;
    }
}


class ControllerExtensionPaymentPaystack extends Controller
{
    private function expected_amount($order_info)
    {
        return intval($this->currency->format($order_info['total'] * 100, $order_info['currency_code'], $order_info['currency_value'], false));
    }

    public function index()
    {
        $this->load->model('checkout/order');

        $this->load->language('extension/payment/paystack');

        $data['button_confirm'] = $this->language->get('button_confirm');

        $data['text_testmode'] = $this->language->get('text_testmode');
        $data['livemode'] = $this->config->get('payment_paystack_live');

        if ($this->config->get('payment_paystack_live')) {
            $data['key'] = $this->config->get('payment_paystack_live_public');
        } else {
            $data['key'] = $this->config->get('payment_paystack_test_public');
        }

        $order_info = $this->model_checkout_order->getOrder($this->session->data['order_id']);

        $data['currency'] = $order_info['currency_code'];
        $data['ref']      = uniqid('' . $this->session->data['order_id'] . '-');
        $data['amount']   = $this->expected_amount($order_info);
        $data['email']    = $order_info['email'];
        $data['callback'] = $this->url->link('extension/payment/paystack/callback', 'trxref=' . rawurlencode($data['ref']), 'SSL');

        return $this->load->view('extension/payment/paystack', $data);
    }

    private function query_api_transaction_verify($reference)
    {
        if ($this->config->get('payment_paystack_live')) {
            $skey = $this->config->get('payment_paystack_live_secret');
        } else {
            $skey = $this->config->get('payment_paystack_test_secret');
        }

        $res = \Paystack\Http::request(array(array(
            'id' => 'verify',
            'method' => 'GET',
            'path' => 'transaction/verify/' . rawurlencode($reference),
            'body' => null,
            'key' => (string) $skey,
            'timeout' => 15,
        )), 16);
        $status = $res['verify']['status'];
        $decoded = json_decode($res['verify']['body'], true);

        return array('status' => $status, 'body' => is_array($decoded) ? $decoded : null);
    }

    private function configured_status_ids($keys)
    {
        $ids = array();
        foreach ($keys as $key) {
            $value = $this->config->get($key);
            foreach (is_array($value) ? $value : array($value) as $id) {
                if ($id !== null && $id !== '' && $id !== false) {
                    $ids[] = (int) $id;
                }
            }
        }
        return $ids;
    }

    private function note_is_latest_comment($order_id, $note)
    {
        $query = $this->db->query("SELECT `comment` FROM `" . DB_PREFIX . "order_history` WHERE order_id = '" . (int) $order_id . "' ORDER BY date_added DESC, order_history_id DESC LIMIT 1");
        return $query->num_rows > 0 && $query->row['comment'] === $note;
    }

    private function record_mismatch($order_info, $current, $verified)
    {
        $order_id = (int) $order_info['order_id'];
        $data = isset($verified['data']) && is_array($verified['data']) ? $verified['data'] : array();
        $amount = isset($data['amount']) && is_int($data['amount']) ? $data['amount'] : '-';
        $requested = isset($data['requested_amount']) && is_int($data['requested_amount']) ? $data['requested_amount'] : '-';
        $currency = isset($data['currency']) && is_string($data['currency']) ? preg_replace('/[^A-Za-z]/', '', substr($data['currency'], 0, 8)) : '-';
        $this->log->write('PAYSTACK :: ORDER ' . $order_id . ' MISMATCH expected ' . $this->expected_amount($order_info) . ' ' . $order_info['currency_code'] . ', got amount ' . $amount . ' requested_amount ' . $requested . ' ' . $currency);

        $note = "Paystack payment did not match this order's amount or currency; check it in your Paystack Dashboard.";
        if ($current === 0) {
            // A status 0 order is hidden in the admin, so move it to pending where the note can be seen.
            $pending = $this->config->get('payment_paystack_pending_status_id');
            if ($pending !== null && $pending !== '' && $pending !== false) {
                $this->model_checkout_order->addOrderHistory($order_id, (int) $pending, $note, false);
            }
        } elseif (!$this->note_is_latest_comment($order_id, $note)) {
            $this->model_checkout_order->addOrderHistory($order_id, $current, $note, false);
        }
    }

    private function apply_verified_payment($order_id, $reference)
    {
        $this->load->model('checkout/order');
        $order_info = $this->model_checkout_order->getOrder($order_id);
        if (!$order_info) {
            return 'ignore';
        }

        $paid_status = $this->config->get('payment_paystack_order_status_id');
        $paid_ids = $this->configured_status_ids(array('payment_paystack_order_status_id', 'config_processing_status', 'config_complete_status'));
        $unpaid = \Paystack\Payment::unpaidStatusIds($this->config->get('payment_paystack_pending_status_id'), $paid_status);
        $current = (int) $order_info['order_status_id'];

        $gate = \Paystack\Payment::statusGate($current, $unpaid, $paid_ids);
        if ($gate !== 'proceed') {
            return $gate;
        }
        if ($paid_status === null || $paid_status === '' || $paid_status === false) {
            $this->log->write('PAYSTACK :: ORDER ' . (int) $order_id . ' not updated: no paid order status is configured');
            return 'unverified';
        }

        try {
            $verify = $this->query_api_transaction_verify($reference);
        } catch (\Throwable $e) {
            return 'unverified';
        }
        // Paystack answers an unknown reference with 404: a final answer, so the webhook must not be retried.
        if ($verify['status'] === 404 && is_array($verify['body']) && isset($verify['body']['status']) && $verify['body']['status'] === false) {
            return 'ignore';
        }
        if ($verify['status'] !== 200 || $verify['body'] === null) {
            return 'unverified';
        }

        $lock = 'pstk_' . substr(sha1(DB_DATABASE . DB_PREFIX), 0, 16) . '_' . (int) $order_id;
        $row = $this->db->query("SELECT GET_LOCK('" . $this->db->escape($lock) . "', 10) AS l");
        if (!isset($row->row['l']) || (int) $row->row['l'] !== 1) {
            return 'unverified';
        }

        try {
            $order_info = $this->model_checkout_order->getOrder($order_id);
            if (!$order_info) {
                $decision = 'ignore';
            } elseif (($gate = \Paystack\Payment::statusGate((int) $order_info['order_status_id'], $unpaid, $paid_ids)) !== 'proceed') {
                $decision = $gate;
            } else {
                $current = (int) $order_info['order_status_id'];
                $decision = \Paystack\Payment::paymentDecision(array(
                    'order_id' => (int) $order_id,
                    'order_status_id' => $current,
                    'unpaid_status_ids' => $unpaid,
                    'reference' => $reference,
                    'http_status' => $verify['status'],
                    'response' => $verify['body'],
                    'expected_amount' => $this->expected_amount($order_info),
                    'currency' => $order_info['currency_code'],
                ));

                if ($decision === 'paid') {
                    $this->model_checkout_order->addOrderHistory($order_id, (int) $paid_status);
                } elseif ($decision === 'mismatch') {
                    $this->record_mismatch($order_info, $current, $verify['body']);
                }
            }
        } finally {
            $this->db->query("SELECT RELEASE_LOCK('" . $this->db->escape($lock) . "')");
        }

        if ($decision === 'paid') {
            if ($this->config->get('payment_paystack_live')) {
                $pk = $this->config->get('payment_paystack_live_public');
            } else {
                $pk = $this->config->get('payment_paystack_test_public');
            }
            $pstk_logger = new open_cart_paystack_plugin_tracker('opencart-3.x', $pk);
            $pstk_logger->log_transaction_success($reference);
        }

        return $decision;
    }

    private function redir_and_die($url, $onlymeta = false)
    {
        if (!headers_sent() && !$onlymeta) {
            header('Location: ' . $url);
        }
        echo "<meta http-equiv=\"refresh\" content=\"0;url=" . addslashes($url) . "\" />";
        die();
    }

    private function handle_webhook()
    {
        $input = (string) @file_get_contents('php://input');
        $mode = $this->config->get('payment_paystack_live') ? 'live' : 'test';
        $secret_key = (string) $this->config->get('payment_paystack_' . $mode . '_secret');
        $whsec = (string) $this->config->get('payment_paystack_' . $mode . '_webhook_secret');
        $debug = $this->config->get('payment_paystack_debug');

        if (!\Paystack\Webhook::isWebhookAuthentic($input, $whsec, $secret_key, $_SERVER, time())) {
            if ($debug) {
                $this->log->write('PAYSTACK :: WEBHOOK rejected: authentication failed');
            }
            http_response_code(401);
            exit();
        }

        $event = json_decode($input, true);
        $name = is_array($event) && isset($event['event']) && is_string($event['event']) ? $event['event'] : '';
        $status = 200;
        $order_id = null;
        $decision = '-';

        if ($name === 'charge.success' && isset($event['data']) && is_array($event['data'])
            && isset($event['data']['reference']) && is_string($event['data']['reference'])) {
            $order_id = \Paystack\Payment::parseReference($event['data']['reference']);
            if ($order_id !== null) {
                $decision = $this->apply_verified_payment($order_id, $event['data']['reference']);
                if ($decision === 'unverified') {
                    $status = 503;
                }
            }
        }

        if ($debug) {
            $this->log->write('PAYSTACK :: WEBHOOK event=' . preg_replace('/[^\w.\-]/', '_', substr($name, 0, 64)) . ' order=' . ($order_id === null ? '-' : $order_id) . ' decision=' . $decision);
        }
        http_response_code($status);
        exit();
    }

    public function callback()
    {
        if (isset($this->request->get['trxref'])) {
            $trxref = $this->request->get['trxref'];
            $decision = 'ignore';

            if (is_string($trxref)) {
                $order_id = \Paystack\Payment::parseReference($trxref);
                if ($order_id !== null) {
                    $decision = $this->apply_verified_payment($order_id, $trxref);
                    if ($this->config->get('payment_paystack_debug')) {
                        $this->log->write('PAYSTACK :: CALLBACK ORDER ' . $order_id . ' DECISION: ' . $decision);
                    }
                }
            }

            if ($decision === 'paid' || $decision === 'already_paid') {
                $redir_url = $this->url->link('checkout/success');
            } else {
                $redir_url = $this->url->link('checkout/checkout', '', 'SSL');
            }
            $this->redir_and_die($redir_url);
        } elseif (strtoupper($_SERVER['REQUEST_METHOD']) === 'POST'
            && (isset($_SERVER['HTTP_PAYSTACK_SIGNATURE']) || isset($_SERVER['HTTP_X_PAYSTACK_SIGNATURE']))) {
            $this->handle_webhook();
        }
    }
}
