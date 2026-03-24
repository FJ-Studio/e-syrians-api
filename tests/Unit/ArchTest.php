<?php

// ───────────────────────────────────────────────
// General Architecture Rules
// ───────────────────────────────────────────────

arch()
    ->expect('App')
    ->toUseStrictTypes()
    ->not->toUse(['die', 'dd', 'dump', 'exit', 'phpinfo', 'print_r', 'var_dump', 'var_export']);

arch()->preset()->php();
arch()->preset()->security()
    ->ignoring([
        'App\Providers\AppServiceProvider',
        'App\Services\AuthService', // sha1 used for email verification hash comparison, not password hashing
    ]);

// ───────────────────────────────────────────────
// Models
// ───────────────────────────────────────────────

arch('models')
    ->expect('App\Models')
    ->toUseTrait('Illuminate\Database\Eloquent\SoftDeletes')
    ->ignoring('App\Models\User')
    ->ignoring('App\Models\PollReaction')
    ->ignoring('App\Models\PollVote')
    ->ignoring('App\Models\ProfileUpdate')
    ->ignoring('App\Models\WeaponDeliveryPoint');

arch()
    ->expect('App\Models')
    ->toBeClasses()
    ->toExtend('Illuminate\Database\Eloquent\Model')
    ->toOnlyBeUsedIn('App')
    ->ignoring('App\Models\User');

// ───────────────────────────────────────────────
// Contracts (Interfaces)
// ───────────────────────────────────────────────

arch('contracts are interfaces')
    ->expect('App\Contracts')
    ->toBeInterfaces();

// ───────────────────────────────────────────────
// Services implement their contracts
// ───────────────────────────────────────────────

arch('AuthService implements AuthServiceContract')
    ->expect('App\Services\AuthService')
    ->toImplement('App\Contracts\AuthServiceContract');

arch('ProfileService implements ProfileServiceContract')
    ->expect('App\Services\ProfileService')
    ->toImplement('App\Contracts\ProfileServiceContract');

arch('VerificationService implements VerificationServiceContract')
    ->expect('App\Services\VerificationService')
    ->toImplement('App\Contracts\VerificationServiceContract');

arch('PasswordService implements PasswordServiceContract')
    ->expect('App\Services\PasswordService')
    ->toImplement('App\Contracts\PasswordServiceContract');

arch('PollService implements PollServiceContract')
    ->expect('App\Services\PollService')
    ->toImplement('App\Contracts\PollServiceContract');

arch('FileUploadService implements FileUploadServiceContract')
    ->expect('App\Services\FileUploadService')
    ->toImplement('App\Contracts\FileUploadServiceContract');

arch('StatsService implements StatsServiceContract')
    ->expect('App\Services\StatsService')
    ->toImplement('App\Contracts\StatsServiceContract');

// ───────────────────────────────────────────────
// Controllers depend on abstractions, not concrete services
// ───────────────────────────────────────────────

arch('controllers do not depend on concrete services directly')
    ->expect('App\Http\Controllers\AuthController')
    ->toOnlyUse([
        'App\Http\Controllers\Controller',
        'App\Contracts\AuthServiceContract',
        'App\Http\Requests\User\CredentialsLoginRequest',
        'App\Http\Requests\User\SocialLoginRequest',
        'App\Http\Requests\User\UserEmailVerification',
        'App\Http\Requests\User\UserStoreRequest',
        'App\Http\Resources\UserResource',
        'App\Services\ApiService',
        'Illuminate\Http\JsonResponse',
        'Illuminate\Http\Request',
    ]);

arch('controllers are classes')
    ->expect('App\Http\Controllers')
    ->toBeClasses();

// ───────────────────────────────────────────────
// Exceptions
// ───────────────────────────────────────────────

arch('exceptions extend Exception')
    ->expect('App\Exceptions')
    ->toExtend('Exception');
