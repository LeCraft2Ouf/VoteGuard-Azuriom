<?php

use Azuriom\Plugin\VoteGuard\Controllers\SessionController;
use Illuminate\Support\Facades\Route;

Route::post('/session', [SessionController::class, 'store'])
    ->middleware('throttle:30,1')
    ->name('session');

Route::post('/click', [SessionController::class, 'click'])
    ->middleware('throttle:60,1')
    ->name('click');
