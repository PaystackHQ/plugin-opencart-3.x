<?php
class ControllerExtensionPaymentPaystack extends Controller
{
    private $error = array();

    public function index()
    {
        $this->load->language('extension/payment/paystack');

        $this->document->setTitle($this->language->get('heading_title'));

        $this->load->model('setting/setting');

        if (($this->request->server['REQUEST_METHOD'] == 'POST') && $this->validate()) {
            $this->model_setting_setting->editSetting('payment_paystack', $this->request->post);

            $this->session->data['success'] = $this->language->get('text_success');

            $this->response->redirect($this->url->link('marketplace/extension', 'user_token=' . $this->session->data['user_token'] . '&type=payment', true));
        }

        $data['webhook_url'] = HTTPS_CATALOG . 'index.php?route=extension/payment/paystack/callback';

        if (isset($this->error['warning'])) {
            $data['error_warning'] = $this->error['warning'];
        } else {
            $data['error_warning'] = '';
        }

        $data['breadcrumbs'][] = array(
            'text' => $this->language->get('text_home'),
            'href' => $this->url->link('common/dashboard', 'user_token=' . $this->session->data['user_token'], true)
        );

        $data['breadcrumbs'][] = array(
            'text' => $this->language->get('text_payment'),
            'href' => $this->url->link('marketplace/extension', 'user_token=' . $this->session->data['user_token'] . '&type=payment', true)
        );

        $data['breadcrumbs'][] = array(
            'text' => $this->language->get('heading_title'),
            'href' => $this->url->link('extension/payment/paystack', 'user_token=' . $this->session->data['user_token'], true)
        );

        $data['action'] = $this->url->link('extension/payment/paystack', 'user_token=' . $this->session->data['user_token'], true);

        $data['cancel'] = $this->url->link('marketplace/extension', 'user_token=' . $this->session->data['user_token'] . '&type=payment', true);

        if (isset($this->request->post['payment_paystack_live_public'])) {
            $data['payment_paystack_live_public'] = $this->request->post['payment_paystack_live_public'];
        } else {
            $data['payment_paystack_live_public'] = $this->config->get('payment_paystack_live_public');
        }

        if (isset($this->request->post['payment_paystack_test_public'])) {
            $data['payment_paystack_test_public'] = $this->request->post['payment_paystack_test_public'];
        } else {
            $data['payment_paystack_test_public'] = $this->config->get('payment_paystack_test_public');
        }

        if (isset($this->request->post['payment_paystack_live'])) {
            $data['payment_paystack_live'] = $this->request->post['payment_paystack_live'];
        } else {
            $data['payment_paystack_live'] = $this->config->get('payment_paystack_live');
        }

        $data['webhook_warning'] = false;
        foreach (array('test', 'live') as $mode) {
            $data['error_keys_' . $mode] = isset($this->error['keys_' . $mode]) ? $this->error['keys_' . $mode] : '';

            $stored_whsec = $this->config->get('payment_paystack_' . $mode . '_webhook_secret');
            $has_whsec = is_string($stored_whsec) && $stored_whsec !== '';
            $data[$mode . '_has_webhook_secret'] = $has_whsec;
            $data[$mode . '_webhook_placeholder'] = $this->saved_placeholder($stored_whsec, 'text_saved_whsec', 'text_saved_whsec_short');
            $data['error_webhook_' . $mode] = isset($this->error['webhook_' . $mode]) ? $this->error['webhook_' . $mode] : '';

            $stored = $this->config->get('payment_paystack_' . $mode . '_secret');
            $posted = $this->posted_field($this->request->post, $mode, 'secret');
            $stored_kind = \Paystack\Restricted_Key::keyKind($stored);
            $kind = $posted !== '' ? \Paystack\Restricted_Key::keyKind($posted) : $stored_kind;

            $data[$mode . '_has_key'] = is_string($stored) && $stored !== '';
            $data[$mode . '_uses_rk'] = $kind === 'rk';
            if (($mode === 'live') === (bool) $data['payment_paystack_live'] && $kind === 'rk' && !$has_whsec) {
                $data['webhook_warning'] = true;
            }
            $data[$mode . '_key_placeholder'] = $this->saved_placeholder($stored, $stored_kind === 'rk' ? 'text_saved_rk' : 'text_saved_sk', 'text_saved_key');
        }

        if (isset($this->request->post['payment_paystack_debug'])) {
            $data['payment_paystack_debug'] = $this->request->post['payment_paystack_debug'];
        } else {
            $data['payment_paystack_debug'] = $this->config->get('payment_paystack_debug');
        }

        if (isset($this->request->post['payment_paystack_total'])) {
            $data['payment_paystack_total'] = $this->request->post['payment_paystack_total'];
        } else {
            $data['payment_paystack_total'] = $this->config->get('payment_paystack_total');
        }

        if (isset($this->request->post['payment_paystack_order_status_id'])) {
            $data['payment_paystack_order_status_id'] = $this->request->post['payment_paystack_order_status_id'];
        } else {
            $data['payment_paystack_order_status_id'] = $this->config->get('payment_paystack_order_status_id');
            // Unsaved, the select would fall to its first option (often Canceled) and paid orders would be saved as that.
            if ($data['payment_paystack_order_status_id'] === null) {
                $data['payment_paystack_order_status_id'] = 2;
            }
        }

        if (isset($this->request->post['payment_paystack_pending_status_id'])) {
            $data['payment_paystack_pending_status_id'] = $this->request->post['payment_paystack_pending_status_id'];
        } else {
            $data['payment_paystack_pending_status_id'] = $this->config->get('payment_paystack_pending_status_id');
            if ($data['payment_paystack_pending_status_id'] === null) {
                $data['payment_paystack_pending_status_id'] = $this->config->get('config_order_status_id');
            }
        }

        $this->load->model('localisation/order_status');

        $data['order_statuses'] = $this->model_localisation_order_status->getOrderStatuses();

        if (isset($this->request->post['payment_paystack_geo_zone_id'])) {
            $data['payment_paystack_geo_zone_id'] = $this->request->post['payment_paystack_geo_zone_id'];
        } else {
            $data['payment_paystack_geo_zone_id'] = $this->config->get('payment_paystack_geo_zone_id');
        }

        $this->load->model('localisation/geo_zone');

        $data['geo_zones'] = $this->model_localisation_geo_zone->getGeoZones();

        if (isset($this->request->post['payment_paystack_status'])) {
            $data['payment_paystack_status'] = $this->request->post['payment_paystack_status'];
        } else {
            $data['payment_paystack_status'] = $this->config->get('payment_paystack_status');
        }

        if (isset($this->request->post['payment_paystack_sort_order'])) {
            $data['payment_paystack_sort_order'] = $this->request->post['payment_paystack_sort_order'];
        } else {
            $data['payment_paystack_sort_order'] = $this->config->get('payment_paystack_sort_order');
        }

        $data['header'] = $this->load->controller('common/header');
        $data['column_left'] = $this->load->controller('common/column_left');
        $data['footer'] = $this->load->controller('common/footer');

        $this->response->setOutput($this->load->view('extension/payment/paystack', $data));
    }

    private function saved_placeholder($stored, $fmt_key, $short_key)
    {
        if (!is_string($stored) || $stored === '') {
            return '';
        }
        $last4 = \Paystack\Restricted_Key::last4($stored);
        return $last4 === null ? $this->language->get($short_key) : sprintf($this->language->get($fmt_key), $last4);
    }

    private function posted_field($post, $mode, $name)
    {
        $key = 'payment_paystack_' . $mode . '_' . $name;
        return \Paystack\Restricted_Key::decodeField(isset($post[$key]) ? $post[$key] : '');
    }

    private function verdict_message($verdict, $mode)
    {
        $simple = array('invalid_key' => 'error_key_invalid', 'account' => 'error_key_account');
        $code = $verdict['code'];
        if (isset($simple[$code])) {
            return $this->language->get($simple[$code]);
        }
        if ($code === 'missing_scope' || $code === 'sensitive_scope') {
            $parts = array();
            if ($verdict['missing']) {
                $parts[] = sprintf($this->language->get('error_key_scope_enable'), implode(' and ', $verdict['missing']));
            }
            if ($verdict['sensitive']) {
                $parts[] = sprintf($this->language->get('error_key_scope_remove'), implode(', ', $verdict['sensitive']));
            }
            return sprintf($this->language->get('error_key_scope'), implode('; ', $parts));
        }
        if ($code === 'wrong_mode') {
            return sprintf($this->language->get('error_key_wrong_mode'), $mode);
        }
        return $this->language->get($code === 'unverifiable' && $verdict['reason'] === 'blocked' ? 'error_key_blocked' : 'error_key_unverifiable');
    }

    private function validate()
    {
        if (!$this->user->hasPermission('modify', 'extension/payment/paystack')) {
            $this->error['warning'] = $this->language->get('error_permission');
            return false;
        }

        $post = $this->request->post;
        $live_posted = !empty($post['payment_paystack_live']);
        $live_stored = (bool) $this->config->get('payment_paystack_live');
        $status_posted = !empty($post['payment_paystack_status']);
        $status_stored = (bool) $this->config->get('payment_paystack_status');
        $deadline_at = microtime(true) + \Paystack\Restricted_Key::OVERALL_DEADLINE;

        $plans = array();
        // The active mode is planned, and so probed, first so an inactive key can't use up the shared deadline.
        $modes = $live_posted ? array('live', 'test') : array('test', 'live');
        foreach ($modes as $mode) {
            $flags = \Paystack\Restricted_Key::modeFlags($mode, $live_posted, $live_stored, $status_posted, $status_stored);
            $plans[$mode] = \Paystack\Restricted_Key::planMode(array(
                'posted' => $this->posted_field($post, $mode, 'secret'),
                'stored' => (string) $this->config->get('payment_paystack_' . $mode . '_secret'),
                'stored_public' => (string) $this->config->get('payment_paystack_' . $mode . '_public'),
                'posted_public' => $this->posted_field($post, $mode, 'public'),
                'mode' => $mode,
                'is_active' => $flags['is_active'],
                'becoming_active' => $flags['becoming_active'],
                'remove' => !empty($post['payment_paystack_' . $mode . '_remove']),
            ));

            if ($plans[$mode]['error'] !== null) {
                $this->error['keys_' . $mode] = sprintf($this->language->get('error_key_' . $plans[$mode]['error']), $mode);
            }
        }

        $whsecs = array();
        foreach (array('test', 'live') as $mode) {
            $secret_plan = \Paystack\Webhook::planWebhookSecret(array(
                'posted' => $this->posted_field($post, $mode, 'webhook_secret'),
                'stored' => (string) $this->config->get('payment_paystack_' . $mode . '_webhook_secret'),
                'remove' => !empty($post['payment_paystack_' . $mode . '_webhook_remove']),
            ));
            $whsecs[$mode] = $secret_plan['value'];
            if ($secret_plan['error'] !== null) {
                $this->error['webhook_' . $mode] = $this->language->get('error_webhook_secret');
            }
        }

        if ($this->error) {
            return false;
        }

        $debug = !empty($post['payment_paystack_debug']) || $this->config->get('payment_paystack_debug');
        $library = new \Paystack\Restricted_Key();
        $public = array();
        foreach ($plans as $mode => $plan) {
            if ($plan['action'] !== 'probe') {
                continue;
            }
            $verdict = $library->validate($plan['key'], $mode, $deadline_at);
            if ($debug) {
                $this->log->write('PAYSTACK :: rk check mode=' . $mode . ' code=' . $verdict['code'] . ' probes=' . $verdict['log']);
            }
            if ($verdict['code'] === 'ok') {
                $public[$mode] = $verdict['public_key'];
            } else {
                $this->error['keys_' . $mode] = $this->verdict_message($verdict, $mode);
            }
        }

        if ($this->error) {
            return false;
        }

        // The only place a public key is chosen: restricted-key modes never trust the posted one.
        foreach ($plans as $mode => $plan) {
            $pk = isset($public[$mode]) ? $public[$mode] : $plan['public'];
            $this->request->post['payment_paystack_' . $mode . '_secret'] = $plan['key'];
            $this->request->post['payment_paystack_' . $mode . '_public'] = $pk;
            unset($this->request->post['payment_paystack_' . $mode . '_remove']);
            $this->request->post['payment_paystack_' . $mode . '_webhook_secret'] = $whsecs[$mode];
            unset($this->request->post['payment_paystack_' . $mode . '_webhook_remove']);
        }

        return true;
    }
}
