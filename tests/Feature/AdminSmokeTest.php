<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminSmokeTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_dashboard_renders(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get('/admin')->assertSuccessful();
    }
}
