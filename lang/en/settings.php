<?php

return [
    'title' => 'Settings',
    'save' => 'Save',
    'saved' => 'Settings saved',

    'smtp' => 'Email (SMTP)',
    'smtp_help' => 'The mail server used to send OTP codes and notifications. The password is stored encrypted.',
    'use_custom_smtp' => 'Use custom SMTP',
    'from_name' => 'From name',
    'from_address' => 'From address',

    'payme' => 'PayMe',
    'payme_help' => 'PayMe gateway credentials (recurring charges and card updates).',
    'test_payme' => 'Test PayMe connection',
    'payme_ok' => 'PayMe connection is valid ✓',
    'payme_failed' => 'PayMe connection failed — check the API URL and Seller ID',
    'payme_missing' => 'Enter the API URL and Seller ID before testing',

    'sms' => 'SMS (019)',
    'sms_help' => '019 account credentials for sending OTP codes by SMS.',
    'sms_sender' => 'Sender name',

    'test_email' => 'Send test email',
    'test_email_subject' => 'SMTP test — Mills',
    'test_email_body' => 'This is a test email from the system settings. If you received it, SMTP works.',
    'test_email_sent' => 'Test email sent to :email',
    'test_email_failed' => 'Test email failed',

    'shopify' => 'Shopify connection',
    'shopify_help' => 'Connect the app to the store. After changing scopes/settings in the Partner Dashboard you must reconnect to get a fresh token. Note: logging into this admin panel does NOT connect Shopify — use the button below.',
    'shop_domain' => 'Store domain',
    'installed_at' => 'Connected at',
    'connect_shopify' => 'Connect / Reconnect Shopify',
    'test_shopify' => 'Test Shopify connection',
    'shopify_ok' => 'Shopify connection is valid ✓ (:shop)',
    'shopify_invalid' => 'Token is not valid — please reconnect via the "Connect / Reconnect Shopify" button',
    'shopify_not_connected' => 'App is not connected to Shopify — click "Connect / Reconnect Shopify"',
];
