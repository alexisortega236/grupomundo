<?php

use App\Http\Controllers\Api\V1\PropertyController;
use App\Http\Controllers\Api\V1\PropertyChangeController;
use App\Http\Middleware\IdentifyOAuthClient;
use Illuminate\Support\Facades\Route;

Route::middleware([
    IdentifyOAuthClient::using('properties:read'),
    'throttle:api-properties',
])->prefix('v1')->group(function (): void {
    Route::get('properties', [PropertyController::class, 'index']);
    Route::get('properties/{id}', [PropertyController::class, 'show'])->whereNumber('id');
    Route::get('property-changes', [PropertyChangeController::class, 'index']);
});
