<?php
// Heading
$_['heading_title']         = 'Paystack';

// Text
$_['text_payment']          = 'Payment';
$_['text_success']          = 'Success: You have modified Paystack details!';
$_['text_edit']             = 'Edit Paystack';
$_['text_paystack']         = '<a target="_BLANK" href="http://www.paystack.com"><img src="view/image/payment/paystack.png" alt="Paystack" title="Paystack" style="border: 1px solid #cccccc;" /></a>';
$_['text_pay']              = 'Pay';
$_['text_disable_payment']  = 'Disable Payment Method';
$_['text_saved_rk']         = 'Saved restricted key …%s — leave blank to keep';
$_['text_saved_sk']         = 'Saved secret key …%s — leave blank to keep';
$_['text_public_from_rk']    = 'The public key comes from Paystack for a restricted key and can\'t be edited.';
$_['text_saved_key']        = 'Saved key — leave blank to keep';
$_['text_set_webhook']      = 'To receive webhooks, set your webhook URL on your <a href="https://dashboard.paystack.co/#/settings/developer">Paystack Dashboard</a> to the URL below, then paste the webhook signing secret (whsec_&hellip;) from the Dashboard into the matching field. A secret key works without a signing secret; a restricted key needs one to receive webhooks. Customers returning to the shop complete their orders either way.';
$_['text_saved_whsec']      = 'Saved signing secret …%s — leave blank to keep';
$_['text_saved_whsec_short'] = 'Saved signing secret — leave blank to keep';
$_['text_webhook_warning']  = 'The key in use is a restricted key and no webhook signing secret is saved for this mode, so webhooks will be ignored until you add one. Orders are still completed when the customer returns to the shop.';

// Entry
$_['entry_live_secret']     = 'Live Secret or Restricted Key:';
$_['entry_live_public']     = 'Live Public Key:';
$_['entry_test_secret']     = 'Test Secret or Restricted Key:';
$_['entry_test_public']     = 'Test Public Key:';

$_['entry_test_webhook_secret'] = 'Test Webhook Signing Secret:';
$_['entry_live_webhook_secret'] = 'Live Webhook Signing Secret:';
$_['entry_test_webhook_remove'] = 'Remove saved test webhook secret';
$_['entry_live_webhook_remove'] = 'Remove saved live webhook secret';
$_['entry_test_remove']     = 'Remove saved test key';
$_['entry_live_remove']     = 'Remove saved live key';

$_['entry_live']            = 'Live Mode';
$_['entry_debug']           = 'Debug Mode';
$_['entry_total']           = 'Total';
$_['entry_order_status']    = 'Order Status';
$_['entry_pending_status']    = 'Pending Status ';
$_['entry_canceled_status']    = 'Canceled Status';
$_['entry_failed_status']   = 'Failed Status';
$_['entry_geo_zone']        = 'Geo Zone';
$_['entry_status']          = 'Status';
$_['entry_sort_order']      = 'Sort Order';

// Tab
$_['tab_general']           = 'General';
$_['tab_order_status']      = 'Order Status';

// Help
$_['help_live']             = 'Use the live mode to process transactions. Request Go Live on the Paystack Dashboard to get live keys.';
$_['help_debug']            = 'Logs additional information to the system log';
$_['help_total']            = 'The checkout total the order must reach before this payment method becomes active';
$_['help_secret']           = 'Paste a secret key (sk_) or a restricted key (rk_). A restricted key needs only Account: read and Transactions: read. Keys with Transactions: write or any permission that moves money are refused. The saved key is never shown; leave the field blank to keep it.';
$_['help_public']           = 'Filled in automatically from Paystack when you use a restricted key. Required when you use a secret key.';
$_['help_webhook_secret']    = 'The whsec_ signing secret shown next to your webhook URL on the Paystack Dashboard. The saved secret is never shown; leave the field blank to keep it.';
$_['help_remove']           = 'A blank key field keeps the saved key. Tick this to delete it instead (not possible for the mode in use).';

// Error
$_['error_permission']      = 'Warning: You do not have permission to modify the Paystack extension!';
$_['error_test_keys']       = 'Both test keys are required for test mode!';
$_['error_live_keys']       = 'Both live keys are required for live mode!';

$_['error_key_invalid']         = 'This key is invalid, revoked, or not enabled for restricted keys on your Paystack account.';
$_['error_key_scope']           = 'This restricted key can\'t be used: %s. It needs Account: read and Transactions: read only. Transactions: write and permissions that can move money or change your account settings aren\'t allowed.';
$_['error_key_scope_enable']    = 'enable %s';
$_['error_key_scope_remove']    = 'remove these permissions: %s';
$_['error_key_account']         = 'Paystack didn\'t return this key\'s account details. Check the key is from the right account and mode, or contact Paystack Support.';
$_['error_key_blocked']         = 'Paystack refused our check of this key. If you use an IP whitelist on your Paystack account, add this server\'s address and try again.';
$_['error_key_unverifiable']    = 'We couldn\'t reach Paystack to check this key. Please try again.';
$_['error_key_wrong_mode']      = 'This key does not belong in the %s mode field.';
$_['error_key_stored_key_invalid'] = 'The saved %s key is not valid. Paste your key again.';
$_['error_key_bad_shape']       = 'This is not a valid secret key (sk_) or restricted key (rk_).';
$_['error_key_missing_key']     = 'A secret or restricted key is required for the mode in use.';
$_['error_key_missing_public_key'] = 'A public key is required when you use a secret key in the mode in use.';
$_['error_key_bad_public_key']  = 'This is not a valid public key for the %s mode.';
$_['error_key_cannot_remove_active'] = 'You can\'t remove the key for the mode in use.';
$_['error_webhook_secret']    = 'This is not a valid webhook signing secret (it starts with whsec_).';
