<?php

return [
    // Default SMS channel. 019 (Israeli provider) is the chosen provider (D13);
    // the adapter is built when credentials are provided.
    'default' => env('SMS_CHANNEL', '019'),

    '019' => [
        'base_url' => env('SMS_019_BASE_URL', 'https://019sms.co.il/api'),
        'username' => env('SMS_019_USERNAME'),
        'token' => env('SMS_019_TOKEN'),
        'sender' => env('SMS_019_SENDER', 'Mills'),
    ],
];
