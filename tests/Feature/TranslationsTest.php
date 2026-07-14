<?php

namespace Tests\Feature;

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
}
