<?php

use App\Http\Controllers\AccessCodeController;
use App\Http\Controllers\FileUploadController;
use App\Http\Middleware\EnsureUploadCode;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('app');
});

Route::get('/api/access', [AccessCodeController::class, 'show']);

Route::post('/api/access', [AccessCodeController::class, 'store'])
    ->middleware('throttle:10,1,upload-access:');

Route::middleware(EnsureUploadCode::class)->group(function () {
    Route::post('/api/uploads', [FileUploadController::class, 'store'])
        ->middleware('throttle:30,1,upload-start:');

    Route::post('/api/uploads/{upload}/chunks', [FileUploadController::class, 'chunk'])
        ->middleware('throttle:5000,1,upload-chunk:');

    Route::post('/api/uploads/{upload}/complete', [FileUploadController::class, 'complete'])
        ->middleware('throttle:60,1,upload-complete:');
});
