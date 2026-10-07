<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

uses(RefreshDatabase::class);

test('the home page sends guests to the admin login page', function () {
    get('/')->assertRedirect('/admin');

    get('/admin')->assertRedirect('/admin/login');
});

test('the home page sends logged in staff to the admin dashboard', function () {
    actingAs(User::factory()->create());

    get('/')->assertRedirect('/admin');

    get('/admin')->assertOk();
});
