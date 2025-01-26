<?php

it('Guests registration with minimal info', function () {
    $response = $this->postJson('/users/register', [
        'name' => 'John',
        'surname' => 'Doe',
        'email' => '',
        'gender' => 'f',
        'password' => 'password',
        'password_confirmation' => 'password',
        'national_id' => '123456789',
        'birth_date' => '1990-01-01',
        'hometown' => 'damascus',
        'country' => 'SY',
    ]);
    // Assert the response status is 201
    $response->assertStatus(201);
});

// it('Guests registration with full info', function () {
//     $response = $this->postJson('/users/register', [
//         'name' => 'John',
//         'surname' => 'Doe',
//         'email' => '',
//         'gender' => 'f',
//         'password' => 'password',
//         'password_confirmation' => 'password',
//         'national_id' => '123456789',
//         'birth_date' => '1990-01-01',
//         'hometown' => 'damascus',
//         'country' => 'SY',
//     ]);
//     // Assert the response status is 201
//     $response->assertStatus(201);
// });
