<?php

arch()
    ->expect('App')
    ->toUseStrictTypes()
    ->not->toUse(['die', 'dd', 'dump', 'exit', 'phpinfo', 'print_r', 'var_dump', 'var_export']);

arch('models')
    ->expect('App\Models')
    ->toUseTrait('Illuminate\Database\Eloquent\SoftDeletes')
    ->ignoring('App\Models\User')
    ->ignoring('App\Models\PollReaction')
    ->ignoring('App\Models\PollVote');

arch()
    ->expect('App\Models')
    ->toBeClasses()
    ->toExtend('Illuminate\Database\Eloquent\Model')
    ->toOnlyBeUsedIn('App')
    ->ignoring('App\Models\User');

arch()->preset()->php();
arch()->preset()->security()
    ->ignoring([
        'App\Providers\AppServiceProvider',
        'App\Http\Controllers\UserController',
    ]);
