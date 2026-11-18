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


require_once DIR_SYSTEM . 'library/paystack/restricted_key.php';

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

        $res = \Paystack\Restricted_Key::curlRequester(array(array(
            'id' => 'verify',
            'method' => 'GET',
            'path' => 'transaction/verify/' . rawurlencode($reference),
            'body' => null,
            'key' => (string) $skey,
        )), 10);
        $status = $res['verify']['status'];
        $decoded = json_decode($res['verify']['body'], true);

        return array('status' => $status, 'body' => is_array($decoded) ? $decoded : null);
    }

    private function configured_status_ids($keys)
    {
        $ids = array();
        foreach ($keys as $key) {
            $value = $this->config->get($key);
            if ($value !== null && $value !== '' && $value !== false) {
                $ids[] = (int) $value;
            }
        }
        return $ids;
    }

    private function apply_verified_payment($order_id, $reference)
    {
        $lock = 'paystack_order_' . DB_PREFIX . $order_id;
        $row = $this->db->query("SELECT GET_LOCK('" . $this->db->escape($lock) . "', 10) AS l");
        if (!isset($row->row['l']) || (int) $row->row['l'] !== 1) {
            return 'ignore';
        }

        try {
            $this->load->model('checkout/order');
            $order_info = $this->model_checkout_order->getOrder($order_id);
            if (!$order_info) {
                return 'ignore';
            }

            $verify = $this->query_api_transaction_verify($reference);

            $unpaid = array_merge(array(0), $this->configured_status_ids(array(
                'config_order_status_id',
                'payment_paystack_pending_status_id',
                'payment_paystack_failed_status_id',
                'payment_paystack_canceled_status_id',
            )));
            $current = (int) $order_info['order_status_id'];

            $decision = \Paystack\Restricted_Key::paymentDecision(array(
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
                $paid_status = $this->config->get('payment_paystack_order_status_id');
                if ($paid_status !== null && $paid_status !== '' && $paid_status !== false) {
                    $this->model_checkout_order->addOrderHistory($order_id, (int) $paid_status);

                    if ($this->config->get('payment_paystack_live')) {
                        $pk = $this->config->get('payment_paystack_live_public');
                    } else {
                        $pk = $this->config->get('payment_paystack_test_public');
                    }
                    $pstk_logger = new open_cart_paystack_plugin_tracker('opencart-3.x', $pk);
                    $pstk_logger->log_transaction_success($reference);
                }
            } elseif ($decision === 'mismatch') {
                // OpenCart treats a status 0 history entry specially, so only annotate real statuses
                if ($current !== 0) {
                    $this->model_checkout_order->addOrderHistory($order_id, $current, "Paystack payment did not match this order's amount or currency; check it in your Paystack Dashboard.", false);
                } elseif ($this->config->get('payment_paystack_debug')) {
                    $this->log->write('PAYSTACK :: ORDER ' . (int) $order_id . ' MISMATCH (status 0, no history written)');
                }
            }

            return $decision;
        } finally {
            $this->db->query("SELECT RELEASE_LOCK('" . $this->db->escape($lock) . "')");
        }
    }

    private function redir_and_die($url, $onlymeta = false)
    {
        if (!headers_sent() && !$onlymeta) {
            header('Location: ' . $url);
        }
        echo "<meta http-equiv=\"refresh\" content=\"0;url=" . addslashes($url) . "\" />";
        die();
    }

    public function callback()
    {
        if (isset($this->request->get['trxref'])) {
            $trxref = $this->request->get['trxref'];
            $decision = 'ignore';

            if (is_string($trxref)) {
                $order_id = \Paystack\Restricted_Key::parseReference($trxref);
                if ($order_id !== null) {
                    $decision = $this->apply_verified_payment($order_id, $trxref);
                    if ($this->config->get('payment_paystack_debug')) {
                        $this->log->write('PAYSTACK :: CALLBACK ORDER ' . $order_id . ' DECISION: ' . $decision);
                    }
                }
            }

            if ($decision === 'paid') {
                $redir_url = $this->url->link('checkout/success');
            } else {
                $redir_url = $this->url->link('checkout/checkout', '', 'SSL');
            }
            $this->redir_and_die($redir_url);
        } else if ((strtoupper($_SERVER['REQUEST_METHOD']) == 'POST') && array_key_exists('x-paystack-signature', $_SERVER)) { // WEBHOOK CODE

            // Retrieve the request's body
            $input = @file_get_contents("php://input");

            if ($this->config->get('payment_paystack_live')) {
                $secret_key = $this->config->get('payment_paystack_live_secret');
            } else {
                $secret_key = $this->config->get('payment_paystack_test_secret');
            }

            // validate event do all at once to avoid timing attack
            if ($_SERVER['HTTP_X_PAYSTACK_SIGNATURE'] !== hash_hmac('sha512', $input, $secret_key))
                exit();

            http_response_code(200);

            // parse event (which is json string) as object
            // Do something - that will not take long - with $event
            $event = json_decode($input);

            if ($event['event'] == 'charge.success') {

                $trxref = $event['data']['reference'];

                // order id is what comes before the first dash in trxref
                $order_id = substr($trxref, 0, strpos($trxref, '-'));
                // if no dash were in transation reference, we will have an empty order_id
                if (!$order_id) {
                    $order_id = 0;
                }

                $this->load->model('checkout/order');

                $order_info = $this->model_checkout_order->getOrder($order_id);

                $order_status_id = $this->config->get('config_order_status_id');

                if (array_key_exists('data', $event) && array_key_exists('status', $event['data']) && ($event['data']['status'] === 'success')) {
                    //PSTK Logger 
                    if ($this->config->get('payment_paystack_live')) {
                        $pk = $this->config->get('payment_paystack_live_public');
                    } else {
                        $pk = $this->config->get('payment_paystack_test_public');
                    }

                    $pstk_logger = new open_cart_paystack_plugin_tracker('opencart-3.x', $pk);
                    $pstk_logger->log_transaction_success($trxref);

                    //----------------------
                    $order_status_id = $this->config->get('payment_paystack_order_status_id');
                } elseif (array_key_exists('data', $event) && array_key_exists('status', $event['data']) && ($event['data']['status'] === 'failed')) {
                    $order_status_id = $this->config->get('payment_paystack_declined_status_id');
                } else {
                    $order_status_id = $this->config->get('payment_paystack_canceled_status_id');
                }

                $this->model_checkout_order->addOrderHistory($order_id, $order_status_id);
            }
            exit();
        }
    }
}
