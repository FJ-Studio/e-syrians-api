<?php

use Illuminate\Support\Facades\Mail;

beforeEach(function () {
    Mail::fake();
});

it('Guests registration with minimal info', function () {
    $response = $this->postJson(route('users.register'), [
        'name' => 'John',
        'surname' => 'Doe',
        'email' => 'user@gmail.com',
        'gender' => 'm',
        'password' => 'password',
        'password_confirmation' => 'password',
        'national_id' => '123456789'.rand(1, 999),
        'birth_date' => '1990-01-01',
        'hometown' => 'damascus',
        'country' => 'TR',
        'ethnicity' => 'arab',
    ]);
    // asset response status
    $response->assertStatus(201);
    // assert user is created
    $this->assertDatabaseHas('users', [
        'email' => 'user@gmail.com',
    ]);
});

it('Assert users in SY provides their province', function () {
    $response = $this->postJson(route('users.register'), [
        'name' => 'John',
        'surname' => 'Doe',
        'email' => 'user.insyria@gmail.com',
        'gender' => 'm',
        'password' => 'password',
        'password_confirmation' => 'password',
        'national_id' => '123456789'.rand(1, 999),
        'birth_date' => '1990-01-01',
        'hometown' => 'damascus',
        'country' => 'SY',
        'ethnicity' => 'arab',
    ]);
    // asset response status (validation error)
    $response->assertStatus(422);
    // assert response has error messages regarding the city (at least)
    expect($response['messages'])->toHaveKey('city_inside_syria');

});
