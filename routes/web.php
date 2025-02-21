<?php

use App\Mail\UserReceivedVerification;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/test', function () {
    $user  = User::find(1);
    $targetUser = User::find(18);
    $sent = Mail::to('test@example.com')->send(new UserReceivedVerification($user, $targetUser));
    dd($user, $targetUser, $sent);
});
