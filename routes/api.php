<?php

use App\Http\Controllers\WebhookController;
use Illuminate\Support\Facades\Route;

Route::post('/webhooks/github', [WebhookController::class, 'handle'])
    ->name('webhooks.github');
