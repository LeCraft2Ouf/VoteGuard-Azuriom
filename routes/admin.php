<?php

use Azuriom\Plugin\VoteGuard\Controllers\Admin\DashboardController;
use Azuriom\Plugin\VoteGuard\Controllers\Admin\SettingController;
use Azuriom\Plugin\VoteGuard\Controllers\Admin\SuspectController;
use Illuminate\Support\Facades\Route;

Route::middleware('can:voteguard.manage')->group(function () {
    Route::get('/', [DashboardController::class, 'index'])->name('index');
    Route::post('/scan', [DashboardController::class, 'scan'])->name('scan');

    Route::get('/settings', [SettingController::class, 'index'])->name('settings');
    Route::post('/settings', [SettingController::class, 'save'])->name('settings.save');

    Route::get('/suspects/{suspect}', [SuspectController::class, 'show'])->name('show');
    Route::post('/suspects/{suspect}', [SuspectController::class, 'update'])->name('update');
});
