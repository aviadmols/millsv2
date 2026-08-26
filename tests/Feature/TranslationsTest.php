<?php

namespace Tests\Feature;

use App\Domain\Billing\IdempotencyKey;
use App\Modules\MillsSubscriptions\Enums\LedgerStatus;
use App\Modules\MillsSubscriptions\Enums\PaymentState;
use App\Modules\MillsSubscriptions\Enums\SubscriptionStatus;
use App\Modules\MillsSubscriptions\Support\Timeline;
use Filament\Facades\Filament;
use Tests\TestCase;

/**
 * The Hebrew UI is the product. If a lang file is mangled, every admin screen fills with
 * "×¨×’×™×©×•×™×•×ª" and nothing fails — no exception, no test, just an unusable app.
 *
 * That is not hypothetical: a shell rewrite of these files read them as ANSI and wrote them
 * back as UTF-8, double-encoding every Hebrew string in the repo, and the whole suite stayed
 * green. These three assertions are what catch it.
 */
class TranslationsTest extends TestCase
{
    /** @return list<string> */
    private function langFiles(): array
    {
        return array_values(array_filter(
            glob(lang_path('*/*.php')) ?: [],
        ));
    }

    public function test_every_translation_file_is_valid_utf8(): void
    {
        foreach ($this->langFiles() as $file) {
            $contents = (string) file_get_contents($file);

            $this->assertTrue(
                mb_check_encoding($contents, 'UTF-8'),
                basename(dirname($file)).'/'.basename($file).' is not valid UTF-8',
            );
        }
    }

    public function test_no_translation_has_been_double_encoded(): void
    {
        // Hebrew (D7 xx / D6 xx) run through a cp1252 decoder and re-encoded comes out as
        // C3 97 (×) followed by another continuation byte. Real text never looks like this.
        foreach ($this->langFiles() as $file) {
            $contents = (string) file_get_contents($file);

            $this->assertDoesNotMatchRegularExpression(
                '/\x{00D7}[\x{0080}-\x{00BF}]|\x{00C3}\x{00A2}\x{20AC}/u',
                $contents,
                basename(dirname($file)).'/'.basename($file).' looks double-encoded — it was probably rewritten by a tool that read UTF-8 as ANSI',
            );
        }
    }

    public function test_hebrew_and_english_define_the_same_keys(): void
    {
        // A key present in one locale and missing in the other renders as the raw key
        // ("subscriptions.action_charge_now") on a live screen — visible, but only to whoever
        // happens to open that page.
        foreach (glob(lang_path('en/*.php')) ?: [] as $file) {
            $name = basename($file);
            $he = lang_path('he/'.$name);

            $this->assertFileExists($he, "lang/he/{$name} is missing");

            $enKeys = array_keys(require $file);
            $heKeys = array_keys(require $he);

            sort($enKeys);
            sort($heKeys);

            $this->assertSame($enKeys, $heKeys, "lang/en/{$name} and lang/he/{$name} do not define the same keys");
        }
    }

    public function test_hebrew_strings_are_actually_in_hebrew(): void
    {
        /*
         * Key parity says a Hebrew string EXISTS; it does not say it was translated. A copied
         * English value passes every check above and ships an English sentence into a Hebrew
         * screen. Brand and protocol names are the legitimate exception — "PayMe" and "API"
         * are not translated by anyone.
         */
        $allowed = ['PayMe', 'API', 'Shopify', 'Webhook', 'CRON', 'SMS', 'iCount'];

        foreach (glob(lang_path('he/*.php')) ?: [] as $file) {
            foreach ($this->flatten(require $file, basename($file, '.php')) as $key => $value) {
                // Strip placeholders (:count) and anything that is not a letter, then the
                // names nobody translates. What is left must contain Hebrew.
                $letters = preg_replace('/:[a-zA-Z_]+/', '', $value);
                $letters = str_ireplace($allowed, '', $letters);
                $letters = preg_replace('/[^\p{L}]/u', '', $letters ?? '');

                if ($letters === '' || $letters === null) {
                    continue;       // punctuation, a number, or a brand name alone
                }

                $this->assertMatchesRegularExpression(
                    '/\p{Hebrew}/u',
                    $value,
                    "lang/he/{$key} has no Hebrew in it — it looks untranslated: \"{$value}\"",
                );
            }
        }
    }

    public function test_every_dynamically_built_key_has_a_hebrew_translation(): void
    {
        /*
         * The keys nothing else can catch. `__('subscriptions.ctx_'.$state)` is invisible to
         * a key-parity check and to any IDE search, so a new enum case ships a screen that
         * says "card_update" in the middle of Hebrew — which is exactly what it did.
         */
        app()->setLocale('he');

        $expected = [];

        foreach ((new \ReflectionClass(Timeline::class))->getConstants() as $name => $value) {
            if (str_starts_with($name, 'KIND_')) {
                $expected['activity.kind_'.$value] = "Timeline::{$name}";
            }
            if (str_starts_with($name, 'ACTOR_')) {
                $expected['activity.actor_'.$value] = "Timeline::{$name}";
            }
        }

        foreach (SubscriptionStatus::cases() as $case) {
            $expected['subscriptions.status_'.$case->value] = 'SubscriptionStatus::'.$case->name;
        }

        foreach (PaymentState::cases() as $case) {
            $expected['subscriptions.pay_'.$case->value] = 'PaymentState::'.$case->name;
        }

        foreach (LedgerStatus::cases() as $case) {
            $expected['subscriptions.ledger_'.$case->value] = 'LedgerStatus::'.$case->name;
        }

        foreach ((new \ReflectionClass(IdempotencyKey::class))->getConstants() as $name => $value) {
            if (str_starts_with($name, 'CONTEXT_')) {
                $expected['subscriptions.ctx_'.$value] = "IdempotencyKey::{$name}";
            }
        }

        foreach ($expected as $key => $origin) {
            $this->assertNotSame(
                $key,
                __($key),
                "{$origin} renders as the raw key \"{$key}\" — add it to lang/he and lang/en",
            );
        }
    }

    public function test_every_screen_in_the_sidebar_is_named_in_hebrew(): void
    {
        /*
         * Filament invents a navigation label from the MODEL NAME when a resource does not
         * define one — so a resource with no getNavigationLabel() silently ships "Customers",
         * "Dogs", "Products" into a Hebrew sidebar. No lang file is missing and no key is
         * unused, which is why every other check here passed while four screens sat in
         * English for months.
         */
        app()->setLocale('he');

        $panel = Filament::getPanel('admin');

        foreach ($panel->getResources() as $resource) {
            $this->assertMatchesRegularExpression(
                '/\p{Hebrew}/u',
                $resource::getNavigationLabel(),
                $resource.' has no Hebrew navigation label — add getNavigationLabel() returning a lang key',
            );

            $this->assertMatchesRegularExpression(
                '/\p{Hebrew}/u',
                $resource::getModelLabel(),
                $resource.' has no Hebrew model label — page titles and buttons will read in English',
            );
        }

        foreach ($panel->getPages() as $page) {
            $title = (string) $page::getNavigationLabel();

            if ($title === '') {
                continue;
            }

            $this->assertMatchesRegularExpression(
                '/\p{Hebrew}/u',
                $title,
                $page.' has no Hebrew navigation label',
            );
        }
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, string>
     */
    private function flatten(array $values, string $prefix): array
    {
        $flat = [];

        foreach ($values as $key => $value) {
            $path = $prefix.'.'.$key;

            if (is_array($value)) {
                $flat += $this->flatten($value, $path);
            } elseif (is_string($value)) {
                $flat[$path] = $value;
            }
        }

        return $flat;
    }
}
