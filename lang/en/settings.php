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

    'sms' => 'SMS (019)',
    'sms_help' => '019 account credentials for sending OTP codes by SMS.',
    'sms_sender' => 'Sender name',

    'test_email' => 'Send test email',
    'test_email_subject' => 'SMTP test — Mills',
    'test_email_body' => 'This is a test email from the system settings. If you received it, SMTP works.',
    'test_email_sent' => 'Test email sent to :email',
    'test_email_failed' => 'Test email failed',
];
