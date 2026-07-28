<?php

namespace Tests\Feature;

use App\Models\AppSetting;
use App\Models\Customer;
use App\Modules\MillsSubscriptions\Services\OtpService;
use App\Modules\MillsSubscriptions\Services\Sms\SmsSender;
use App\Modules\MillsSubscriptions\Support\SmsTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The SMS wording is editable from the admin — and cannot be edited into something broken.
 *
 * A login message saved without `:code` is a text message that tells the customer nothing.
 * Every login would fail, silently, for everyone, until a customer complained — so the
 * placeholder is treated as part of the contract, not as advice.
 */
class SmsTemplateTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_shipped_wording_is_used_when_nothing_is_overridden(): void
    {
        $this->assertSame(__('otp.sms.body'), SmsTemplate::body('otp.sms.body'));
    }

    public function test_an_edited_message_is_what_actually_gets_sent(): void
    {
        AppSetting::put(SmsTemplate::settingKey('otp.sms.body'), 'Mills: הקוד שלך הוא :code');

        $this->assertSame('Mills: הקוד שלך הוא 987654', SmsTemplate::render('otp.sms.body', ['code' => '987654']));
    }

    public function test_a_message_missing_its_placeholder_falls_back_to_the_default(): void
    {
        // Belt and braces: the form refuses to save this, but if one ever reaches the database
        // by any other route, a code-less login SMS must not be what goes out.
        AppSetting::put(SmsTemplate::settingKey('otp.sms.body'), 'Welcome to Mills!');

        $rendered = SmsTemplate::render('otp.sms.body', ['code' => '123456']);

        $this->assertStringContainsString('123456', $rendered);
        $this->assertNotSame('Welcome to Mills!', $rendered);
    }

    public function test_the_card_update_message_keeps_its_link(): void
    {
        AppSetting::put(SmsTemplate::settingKey('subscriptions.sms_card_update'), 'עדכנו כרטיס: :url');

        $this->assertSame(
            'עדכנו כרטיס: https://x.test/pay',
            SmsTemplate::render('subscriptions.sms_card_update', ['url' => 'https://x.test/pay']),
        );

        // …and a version without the link is rejected, not sent.
        $this->assertFalse(SmsTemplate::isUsable('subscriptions.sms_card_update', 'עדכנו את הכרטיס בבקשה'));
    }

    public function test_the_login_sms_the_customer_receives_uses_the_edited_text(): void
    {
        AppSetting::put(SmsTemplate::settingKey('otp.sms.body'), 'Mills IL — code :code');

        // Bound as an INSTANCE: a closure would capture the array by value and the test would
        // read an empty list no matter what was sent.
        $spy = new class implements SmsSender
        {
            /** @var list<string> */
            public array $sent = [];

            public function send(string $phone, string $message): bool
            {
                $this->sent[] = $message;

                return true;
            }
        };
        $this->app->instance(SmsSender::class, $spy);

        Customer::query()->create(['email' => 'sms@example.com', 'phone' => '0521234567']);

        app(OtpService::class)->request('0521234567', OtpService::CHANNEL_SMS);

        // The end-to-end point: editing the text in the admin changes the real message.
        $this->assertCount(1, $spy->sent);
        $this->assertStringStartsWith('Mills IL — code ', $spy->sent[0]);
        $this->assertMatchesRegularExpression('/\d{6}$/', $spy->sent[0]);
    }
}
