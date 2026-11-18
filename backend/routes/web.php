<?php

use App\Http\Controllers\SeoController;
use App\Http\Controllers\SpaController;
use Illuminate\Support\Facades\Route;

Route::get('sitemap.xml', [SeoController::class, 'sitemap']);
Route::get('robots.txt', [SeoController::class, 'robots']);

// Everything else is the React SPA (client-side routing). API, storage and built assets are excluded.
Route::get('/{any?}', SpaController::class)
    ->where('any', '^(?!api/|storage/|app/|up$|sanctum/).*$');
