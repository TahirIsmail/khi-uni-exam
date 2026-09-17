<?php

use App\Models\User;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Database\UniqueConstraintViolationException;

test('a deactivated user cannot log in with a password', function () {
    $user = User::factory()->create();
    $user->forceFill(['is_active' => false])->save();

    $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password'])
        ->assertSessionHasErrors('email');

    $this->assertGuest();
});

test('a user deactivated during a session is signed out on the next request', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get(route('dashboard'))->assertOk();

    $user->forceFill(['is_active' => false])->save();

    $this->get(route('dashboard'))->assertRedirect(route('login'));
    $this->assertGuest();
});

test('a staff account linked to kmu-cms without a local password cannot use password login', function () {
    $user = User::factory()->create(['password' => null]);
    $user->forceFill(['cms_staff_id' => 101])->save();

    $this->post(route('login.store'), ['email' => $user->email, 'password' => ''])
        ->assertSessionHasErrors();
    $this->post(route('login.store'), ['email' => $user->email, 'password' => 'anything'])
        ->assertSessionHasErrors('email');

    $this->assertGuest();
});

test('cms identity fields cannot be mass assigned', function () {
    expect(fn () => new User(['cms_staff_id' => 1]))->toThrow(MassAssignmentException::class)
        ->and(fn () => new User(['is_active' => true]))->toThrow(MassAssignmentException::class);
});

test('a cms staff member can be linked to only one local user', function () {
    User::factory()->create()->forceFill(['cms_staff_id' => 7])->save();

    expect(fn () => User::factory()->create()->forceFill(['cms_staff_id' => 7])->save())
        ->toThrow(UniqueConstraintViolationException::class);
});
