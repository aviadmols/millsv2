<?php

return [
    'title' => 'SMS messages',

    'otp_title' => 'Login code',
    'otp_help' => 'Sent when a customer signs in to the personal area with their phone number.',
    'otp_placeholders' => 'Must contain :code — that is where the 6-digit code goes. Without it the customer receives a message with no code in it and cannot log in.',

    'card_title' => 'Card update link',
    'card_help' => 'Sent from the subscription screen when staff send a customer a link to update their card.',
    'card_placeholders' => 'Must contain :url — that is the link to the secure payment page. Without it the customer is asked to update a card with no way to do it.',

    'preview' => 'The customer will see',
    'missing_placeholder' => 'The message must contain :placeholder, or it will not work.',

    'test_title' => 'Send yourself a test',
    'test_help' => 'Sends the login-code message above, with 123456 in place of the real code, so you can read it on a real phone before customers do.',
    'test_phone' => 'Phone number',

    'save' => 'Save',
    'saved' => 'The SMS messages were saved',
    'send_test' => 'Send a test',
    'test_sent' => 'The test message was sent',
    'test_no_phone' => 'Enter a phone number first',
    'test_failed' => 'The test message could not be sent',
    'test_failed_help' => 'The message text is fine — this is the 019 connection. Check Settings → SMS: the username must match the token.',

    'reset' => 'Restore the defaults',
    'reset_confirm' => 'Your edits are discarded and the original wording comes back.',
    'reset_done' => 'The default wording was restored',
];
