<?php

namespace Tests\Feature;

use App\Filament\Resources\Subscriptions\SubscriptionResource;
use App\Models\Customer;
use App\Models\Subscription;
use App\Models\User;
use App\Modules\MillsSubscriptions\Enums\PaymentState;
use App\Modules\MillsSubscriptions\Enums\SubscriptionStatus;
use Filament\Facades\Filament;
use Filament\Support\Enums\Width;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The admin uses the whole window.
 *
 * Two separate things made it not:
 *
 *  - Filament caps page content at 7xl (1280px). On a wide monitor the subscription screen —
 *    which exists to show a two-column layout, an order, a product list and an order history
 *    at once — sat in the left 45% with a field of empty grey beside it.
 *  - A resource FORM's schema defaults to two columns, so every section rendered at half
 *    width with dead space next to it.
 *
 * The second one is why these assertions read the rendered grid instead of trusting the code:
 * the first guess at the cause was wrong, and only the markup could say so.
 */
class AdminLayoutTest extends TestCase
{
    use RefreshDatabase;

    private function subscription(): Subscription
    {
        $customer = Customer::query()->create(['email' => 'layout@example.com']);

        $subscription = new Subscription;
        $subscription->fill([
            'customer_id' => $customer->id,
            'payment_state' => PaymentState::PAYME->value,
            'frequency_months' => 1,
        ]);
        $subscription->forceFill(['status' => SubscriptionStatus::ACTIVE->value])->save();

        return $subscription;
    }

    /**
     * The columns of the page's own schema container.
     *
     * Getting this selector right is the whole test. The first attempt matched the outer page
     * WRAPPER — which carries no `lg:fi-grid-cols` class and is therefore always one column —
     * so it passed whatever the schema underneath it did. That false green is why a real fix
     * to the view screen was reverted as "a no-op", and the bug shipped.
     *
     * The schema container is the first div that actually declares responsive columns.
     */
    private function schemaColumns(string $html): string
    {
        preg_match('/<div class="fi-sc[^"]*lg:fi-grid-cols"[^>]*style="([^"]*)"/', $html, $m);

        return $m[1] ?? '(no schema container found)';
    }

    public function test_the_panel_is_not_capped_to_a_narrow_column(): void
    {
        $this->assertSame(Width::Full, Filament::getPanel('admin')->getMaxContentWidth());
    }

    public function test_the_view_screen_is_not_crammed_into_half_the_window(): void
    {
        $this->actingAs(User::factory()->create());

        $html = $this->get(SubscriptionResource::getUrl('view', ['record' => $this->subscription()]))
            ->assertOk()
            ->getContent();

        $columns = $this->schemaColumns($html);

        // repeat(2) here means the whole 2/3 + 1/3 layout is squeezed into the left half.
        $this->assertStringNotContainsString('repeat(2', $columns);
        // And the layout it exists to render is actually there.
        $this->assertStringContainsString('repeat(3', $html);
    }

    public function test_the_edit_form_lays_its_sections_out_full_width(): void
    {
        $this->actingAs(User::factory()->create());

        $html = $this->get(SubscriptionResource::getUrl('edit', ['record' => $this->subscription()]))
            ->assertOk()
            ->getContent();

        // A repeat(2) DEEPER down is correct — that is a section laying out its own fields.
        // It is the schema container itself that must not halve the page.
        $this->assertStringNotContainsString('repeat(2', $this->schemaColumns($html));
    }

    /**
     * Every resource form, not just the one someone happened to look at.
     *
     * This bug was reported three separate times, on three different screens, because the
     * default is inherited by every form that does not override it — so fixing them one at a
     * time just moves the complaint to the next page.
     */
    public function test_no_resource_form_inherits_the_two_column_default(): void
    {
        $forms = glob(app_path('Filament/Resources/*/Schemas/*Form.php'));

        $this->assertNotEmpty($forms);

        foreach ($forms as $form) {
            $source = (string) file_get_contents($form);

            // An empty scaffold renders nothing, so it cannot be laid out wrongly.
            if (str_contains($source, "->components([\n                //")) {
                continue;
            }

            $this->assertStringContainsString(
                '->columns(1)',
                $source,
                basename($form).' inherits Filament\'s 2-column default — its fields will render at half width',
            );
        }
    }

    public function test_the_dangerous_action_is_not_pushed_off_the_edge_of_the_screen(): void
    {
        $this->actingAs(User::factory()->create());

        $html = $this->get(SubscriptionResource::getUrl('view', ['record' => $this->subscription()]))
            ->assertOk()
            ->getContent();

        // Nine buttons in one row ran off the right of the window, and "Charge now" — the one
        // that takes money and cannot be undone — was the half that fell off. The rest live in
        // a menu now, so it must still be here, in the row.
        $this->assertStringContainsString(__('subscriptions.action_charge_now'), $html);
        $this->assertStringContainsString(__('subscriptions.more_actions'), $html);
    }
}
