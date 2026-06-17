<?php

use App\Http\Controllers\Admin\AdminCatalogController;
use App\Http\Controllers\Admin\AdminDashboardController;
use App\Http\Controllers\Admin\AdminStockController;
use App\Http\Controllers\Admin\AdminSystemController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified', 'admin'])
    ->prefix('admin')
    ->name('admin.')
    ->group(function () {
        Route::get('/', AdminDashboardController::class)->name('dashboard');

        // Stocks (full CRUD)
        Route::get('stocks', [AdminStockController::class, 'index'])->name('stocks.index');
        Route::post('stocks', [AdminStockController::class, 'store'])->name('stocks.store');
        Route::put('stocks/{stock}', [AdminStockController::class, 'update'])->name('stocks.update');
        Route::delete('stocks/{stock}', [AdminStockController::class, 'destroy'])->name('stocks.destroy');

        // News sources
        Route::get('news-sources', [AdminCatalogController::class, 'newsSources'])->name('news-sources.index');
        Route::patch('news-sources/{newsSource}/toggle', [AdminCatalogController::class, 'toggleNewsSource'])->name('news-sources.toggle');

        // API providers
        Route::get('providers', [AdminCatalogController::class, 'apiProviders'])->name('providers.index');
        Route::put('providers/{apiProvider}', [AdminCatalogController::class, 'updateApiProvider'])->name('providers.update');

        // Users
        Route::get('users', [AdminCatalogController::class, 'users'])->name('users.index');
        Route::patch('users/{user}/admin', [AdminCatalogController::class, 'toggleAdmin'])->name('users.admin');

        // System: jobs / failed jobs / notification logs
        Route::get('jobs', [AdminSystemController::class, 'jobs'])->name('jobs.index');
        Route::post('jobs/{uuid}/retry', [AdminSystemController::class, 'retryFailed'])->name('jobs.retry');
        Route::delete('jobs/failed', [AdminSystemController::class, 'flushFailed'])->name('jobs.flush');
        Route::get('notifications', [AdminSystemController::class, 'notifications'])->name('notifications.index');
    });
