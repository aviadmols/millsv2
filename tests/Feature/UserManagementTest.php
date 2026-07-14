<?php

namespace Tests\Feature;

use App\Filament\Resources\Users\Pages\CreateUser;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Who can log in.
 *
 * Every user is a full admin — there are no roles — so this screen hands out complete access
 * to the customers, the orders and the "charge now" button. The things it must never do are
 * lock everybody out and quietly destroy a password.
 */
class UserManagementTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        return $user;
    }

    public function test_an_admin_can_create_a_login(): void
    {
        $this->admin();

        Livewire::test(CreateUser::class)
            ->fillForm([
                'name' => 'Sivan',
                'email' => 'sivan@example.com',
                'password' => 'a-long-enough-password',
                'password_confirmation' => 'a-long-enough-password',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $created = User::query()->where('email', 'sivan@example.com')->firstOrFail();

        // Stored hashed, and the new person can actually get in — a login that cannot log in
        // is the only failure this screen really has.
        $this->assertNotSame('a-long-enough-password', $created->password);
        $this->assertTrue(Hash::check('a-long-enough-password', $created->password));
    }

    public function test_a_password_is_not_confirmed_blindly(): void
    {
        $this->admin();

        Livewire::test(CreateUser::class)
            ->fillForm([
                'name' => 'Typo',
                'email' => 'typo@example.com',
                'password' => 'a-long-enough-password',
                'password_confirmation' => 'a-different-password',
            ])
            ->call('create')
            ->assertHasFormErrors(['password']);

        $this->assertNull(User::query()->where('email', 'typo@example.com')->first());
    }

    public function test_editing_a_name_does_not_destroy_the_password(): void
    {
        $this->admin();

        $target = User::factory()->create(['password' => Hash::make('the-original-password')]);

        Livewire::test(EditUser::class, ['record' => $target->getKey()])
            ->fillForm(['name' => 'Renamed', 'password' => '', 'password_confirmation' => ''])
            ->call('save')
            ->assertHasNoFormErrors();

        $target->refresh();

        // THE TRAP: an empty password box means "leave it alone". Saving it would hash the
        // empty string and lock this person out of their own account, from a rename.
        $this->assertSame('Renamed', $target->name);
        $this->assertTrue(Hash::check('the-original-password', $target->password));
    }

    public function test_a_password_can_actually_be_changed(): void
    {
        $this->admin();

        $target = User::factory()->create(['password' => Hash::make('the-original-password')]);

        Livewire::test(EditUser::class, ['record' => $target->getKey()])
            ->fillForm([
                'password' => 'a-brand-new-password',
                'password_confirmation' => 'a-brand-new-password',
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertTrue(Hash::check('a-brand-new-password', $target->refresh()->password));
    }

    // --- the lockouts ---------------------------------------------------------

    public function test_you_cannot_delete_yourself(): void
    {
        $me = $this->admin();
        User::factory()->create();   // so it is not merely the last-user rule stopping it

        // Deleting the account you are logged in with is a one-click lockout with no way back
        // in from the UI — somebody would need a shell on the production box.
        Livewire::test(EditUser::class, ['record' => $me->getKey()])
            ->assertActionHidden('delete');

        $this->assertNotNull(User::query()->find($me->getKey()));
    }

    public function test_you_cannot_delete_the_last_login(): void
    {
        $only = $this->admin();

        $this->assertSame(1, User::query()->count());

        Livewire::test(EditUser::class, ['record' => $only->getKey()])
            ->assertActionHidden('delete');
    }

    public function test_another_user_can_be_deleted(): void
    {
        $this->admin();
        $other = User::factory()->create();

        Livewire::test(ListUsers::class)
            ->callTableAction('delete', $other)
            ->assertHasNoErrors();

        $this->assertNull(User::query()->find($other->getKey()));
    }

    public function test_two_logins_cannot_share_an_email(): void
    {
        $this->admin();
        User::factory()->create(['email' => 'taken@example.com']);

        Livewire::test(CreateUser::class)
            ->fillForm([
                'name' => 'Clash',
                'email' => 'taken@example.com',
                'password' => 'a-long-enough-password',
                'password_confirmation' => 'a-long-enough-password',
            ])
            ->call('create')
            ->assertHasFormErrors(['email']);
    }
}
