<?php

use App\Http\Controllers\ESignaturesWebhookController;
use Filament\Facades\Filament;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => redirect(Filament::getDefaultPanel()->getUrl()));

Route::post('/webhooks/esignatures', ESignaturesWebhookController::class)
    ->withoutMiddleware(PreventRequestForgery::class)
    ->name('webhooks.esignatures');
