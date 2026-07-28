<?php

namespace App\Modules\MillsSubscriptions\Support;

use App\Models\AppSetting;

/**
 * The text of the SMS messages we send, editable from the admin.
 *
 * The wording used to live only in lang files, so changing "your Mills login code is…" meant
 * a code change and a deploy. It is marketing copy going to customers; it should not need an
 * engineer.
 *
 * Each template declares the placeholders it CANNOT do without. That is the load-bearing part:
 * an OTP message saved without `:code` is a text message that tells the customer nothing and
 * locks them out of their own account, and nobody would discover it until a customer complained.
 * The editor refuses to save such a message, and render() falls back to the shipped default if
 * a bad one ever gets in another way.
 */
class SmsTemplate
{
    /** key => the placeholders that MUST survive an edit. */
    public const TEMPLATES = [
        'otp.sms.body' => [':code'],
        'subscriptions.sms_card_update' => [':url'],
    ];

    /** Where an override for this template is stored. */
    public static function settingKey(string $key): string
    {
        return 'sms_template_'.str_replace('.', '_', $key);
    }

    /** The editable body: the admin's override if there is a usable one, else the default. */
    public static function body(string $key): string
    {
        $override = trim((string) AppSetting::get(self::settingKey($key), ''));

        if ($override !== '' && self::isUsable($key, $override)) {
            return $override;
        }

        return (string) __($key);
    }

    /**
     * The message to actually send, with the placeholders filled in.
     *
     * @param  array<string, string|int>  $replacements  e.g. ['code' => '123456']
     */
    public static function render(string $key, array $replacements = []): string
    {
        $body = self::body($key);

        foreach ($replacements as $name => $value) {
            $body = str_replace(':'.$name, (string) $value, $body);
        }

        return $body;
    }

    /** Does this text still carry every placeholder the message is useless without? */
    public static function isUsable(string $key, string $body): bool
    {
        foreach (self::TEMPLATES[$key] ?? [] as $placeholder) {
            if (! str_contains($body, $placeholder)) {
                return false;
            }
        }

        return true;
    }

    /** @return list<string> */
    public static function requiredPlaceholders(string $key): array
    {
        return self::TEMPLATES[$key] ?? [];
    }
}
