<?php

it('Guests registration with minimal info', function () {
    $email = rand(1000, 999999).'@gmail.com';
    $response = $this->postJson('/users/register', [
        'name' => 'John',
        'surname' => 'Doe',
        'email' => $email,
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
        'email' => $email,
    ]);
});

it('Assert users in SY provides their province', function () {
    $email = rand(1000, 999999).'@gmail.com';
    $response = $this->postJson('/users/register', [
        'name' => 'John',
        'surname' => 'Doe',
        'email' => $email,
        'gender' => 'm',
        'password' => 'password',
        'password_confirmation' => 'password',
        'national_id' => '123456789'.rand(1, 999),
        'birth_date' => '1990-01-01',
        'hometown' => 'damascus',
        'country' => 'SY',
        'ethnicity' => 'arab',
    ]);
    $response
        ->assertStatus(422)
        ->assertJsonStructure([
            'success',
            'messages' => [
                'city_inside_syria',
            ],
            'data',
        ])
        ->assertJsonFragment([
            'success' => false,
        ]);
});
