<?php
use App\Http\Controllers\UserController;
use App\Http\Controllers\WeaponDeliveryController;
use Illuminate\Support\Facades\Route;
Route::prefix('weapons-delivery')->group(function() {
    Route::middleware(['auth:sanctum'])->post('/' , [WeaponDeliveryController::class , 'store']);
});
Route::prefix('users')->group(function() {
    Route::middleware(['guest' , 'throttle:6,1'])->post('/login/social' , [UserController::class , 'social_login']);
    Route::post('login' , [UserController::class , 'login']);
    Route::middleware(['auth:sanctum'])->group(function() {
        Route::get('/me' , [UserController::class , 'me']);
        Route::post('/logout' , [UserController::class , 'logout']);
        Route::post('/verified/{uuid}' , [UserController::class , 'verifier']);
        Route::post('/mark-as-fake/{uuid}' , [UserController::class , 'markAsFake']);
        Route::put('/update/{uuid}' , [UserController::class , 'update']);

    });
    Route::get('' , [UserController::class , 'index']);
    Route::post('/store' , [UserController::class , 'store']);
});

Route::middleware(['auth:sanctum'])->group(function() {

    Route::resource('cities',\App\Http\Controllers\CityController::class);
});
