<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            // personal data
            $table->uuid('uuid')->unique();
            $table->string('name');
            $table->string('middle_name')->nullable();
            $table->string('surname');
            $table->string('national_id')->nullable();
            $table->string('national_id_hash')->nullable();
            $table->enum('gender', array_map(fn($case) => $case->value, \App\Enums\GenderEnum::cases()));
            $table->date('birth_date');
            $table->enum('hometown', array_map(fn($case) => $case->value, \App\Enums\HometownEnum::cases()));
            // e-data
            $table->string('email')->unique()->nullable();
            $table->string('email_hashed')->unique()->nullable();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('phone')->nullable();
            $table->string('phone_hashed')->nullable();
            $table->timestamp('phone_verified_at')->nullable();
            $table->string('social_avatar')->nullable();
            $table->string('google_id')->nullable();
            $table->string('password')->nullable();
            // location
            $table->enum('country', array_map(fn($case) => $case->value, \App\Enums\CountryEnum::cases()));
            $table->string('city')->nullable();
            $table->boolean('shelter')->nullable();
            $table->text('address')->nullable();
            $table->string('photo')->nullable();
            // education and work
            $table->enum('education_level', array_map(fn($case) => $case->value, \App\Enums\EducationLevelEnum::cases()))->nullable();
            $table->text('skills')->nullable();
            $table->enum('marital_status', array_map(fn($case) => $case->value, \App\Enums\MaritalStatusEnum::cases()))->nullable();
            $table->enum('source_of_income', array_map(fn($case) => $case->value, \App\Enums\IncomeSourceEnum::cases()))->nullable();
            $table->decimal('estimated_monthly_income')->nullable();
            $table->integer('number_of_dependents')->nullable();
            // health
            $table->enum('health_status', array_map(fn($case) => $case->value, \App\Enums\HealthStatusEnum::cases()))->nullable();
            $table->boolean('health_insurance')->nullable();
            $table->boolean('easy_access_to_healthcare_services')->nullable();
            // other
            $table->enum(
                'ethnicity',
                array_map(fn($case) => $case->value, \App\Enums\EthnicityEnum::cases())
            )->nullable();
            $table->enum('religious_affiliation', array_map(fn($case) => $case->value, \App\Enums\ReligiousAffiliationEnum::cases()))->nullable();
            $table->text('communication')->nullable();
            $table->text('more_info')->nullable();
            $table->text('other_nationalities')->nullable();
            $table->text('languages')->nullable();
            $table->timestampTz('verified_at')->nullable();
            $table->text('verification_reason')->nullable();
            $table->timestampTz('marked_as_fake_at')->nullable();
            $table->text('marked_as_fake_reason')->nullable();
            $table->string('record_place')->nullable();
            $table->string('record_id')->nullable();
            $table->rememberToken();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('users');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('sessions');
    }
};
