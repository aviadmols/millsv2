<?php

use Illuminate\Support\Facades\Route;

// Root goes straight to the admin panel; Filament redirects to /admin/login
// when unauthenticated. No default Laravel splash page.
Route::get('/', fn () => redirect('/admin'));
