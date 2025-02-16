<?php

it('Guests registration with minimal info', function () {
    $response = $this->postJson('/users/register', [
        'name' => 'John',
        'surname' => 'Doe',
        'email' => '',
        'gender' => 'f',
        'password' => 'password',
        'password_confirmation' => 'password',
        'national_id' => '123456789' . rand(1, 999),
        'birth_date' => '1990-01-01',
        'hometown' => 'damascus',
        'country' => 'SY',
        'ethnicity' => 'arab',
    ]);
    // Assert the response status is 201
    $response->assertStatus(201);
});

it('Guests registration with full info', function () {
    $response = $this->postJson('/users/register', [
        'name' => 'Feras',
        'middle_name' => 'Mahmoud',
        'surname' => 'Jobeir',
        'email' => rand(1, 9999) . 'info@gmail.com',
        'gender' => 'm',
        'birth_date' => '1992-01-23',
        'phone' => '123456789' . rand(1, 999),
        'password' => 'password',
        'password_confirmation' => 'password',
        'national_id' => '123456789' . rand(1, 999),
        'hometown' => 'damascus',
        'country' => 'US',
        'city' => 'New York',
        'shelter' => false,
        'address' => '123 Main St',
        'education_level' => 'postgraduate',
        'skills' => 'PHP, Laravel, Vue.js',
        'marital_status' => 'single',
        'source_of_income' => 'freelance',
        'estimated_monthly_income' => 1000,
        'number_of_dependents' => 0,
        'health_status' => 'good',
        'health_insurance' => true,
        'easy_access_to_healthcare_services' => true,
        'religious_affiliation' => 'non-religious',
        'communication' => 'I speak Arabic and English',
        'more_info' => 'I am a software engineer',
        'other_nationalities[]' => 'US',
        'languages[]' => 'arabic',
        'languages[]' => 'english',
        'verified_at' => '2025-01-01',
        'ethnicity' => 'arab',

    ]);
    // Assert the response status is 201
    $response->assertStatus(201);
});
