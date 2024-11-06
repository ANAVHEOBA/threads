<?php

use App\Http\Controllers\AccountController;
use App\Http\Controllers\AccountDepositController;
use App\Http\Controllers\AccountWithdrawalController;
use App\Http\Controllers\AuthenticationController;
use App\Http\Controllers\OnboardingController;
use App\Http\Controllers\PinController;
use App\Http\Controllers\TransactionController;
use App\Http\Controllers\TransferController;
use App\Http\Controllers\ThreadsAuthController;
use App\Http\Controllers\ThreadsController;
use App\Http\Controllers\MastodonAuthController;
use App\Http\Controllers\MastodonPostController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;




Route::prefix('auth')->group(function () {
//    dd(\request()->isProduction());
    Route::post('register', [AuthenticationController::class, 'register']);
    Route::post('login', [AuthenticationController::class, 'login']);
    Route::middleware("auth:sanctum")->group(function () {
        Route::get("user", [AuthenticationController::class, 'user']);
        Route::get('logout', [AuthenticationController::class, 'logout']);
    });
});

Route::middleware("auth:sanctum")->group(function () {
    Route::prefix('onboarding')->group(function () {
        Route::post('setup/pin', [PinController::class, 'setupPin']);
        Route::middleware('has.set.pin')->group(function () {
            Route::post('validate/pin', [PinController::class, 'validatePin']);
            Route::post('generate/account-number', [AccountController::class, 'store']);
        });
    });

    Route::middleware('has.set.pin')->group(function () {
        Route::prefix('account')->group(function () {
            Route::post('deposit', [AccountDepositController::class, 'store']);
            Route::post('withdraw', [AccountWithdrawalController::class, 'store']);
            Route::post('transfer', [TransferController::class, 'store']);
        });
        Route::prefix('transactions')->group(function () {
            Route::get('history', [TransactionController::class, 'index']);
        });
    });


    
     
    });


    Route::prefix('threads')->group(function () {
        Route::get('/auth', [ThreadsAuthController::class, 'redirect']);
        Route::get('/callback', [ThreadsAuthController::class, 'callback']);
        Route::post('/post', [ThreadsController::class, 'createPost']);
        Route::get('/user/{threads_user_id}', [ThreadsAuthController::class, 'getUserDetails']);
    });
    
    Route::prefix('mastodon')->group(function () {
        Route::post('/register', [MastodonController::class, 'registerApplication']);
        Route::post('/token', [MastodonController::class, 'getAccessToken']);
    });


    Route::prefix('mastodon')->group(function () {
        Route::get('/auth', [MastodonAuthController::class, 'redirect']);
        Route::get('/callback', [MastodonAuthController::class, 'callback']);
        Route::post('/post', [MastodonPostController::class, 'createPost']);
        Route::post('/media', [MastodonPostController::class, 'uploadMedia']);
    });

    /*curl -X POST \
    https://mastodon.social/api/v1/apps \
    -F 'client_name=anavheoba' \
    -F 'redirect_uris=https://2412-2c0f-f5c0-b24-3dd6-7ff6-d7b0-7336-a27e.ngrok-free.app/api/mastodon/callback' \
    -F 'scopes=read write push' \
    -F 'website=https://2412-2c0f-f5c0-b24-3dd6-7ff6-d7b0-7336-a27e.ngrok-free.app'*/