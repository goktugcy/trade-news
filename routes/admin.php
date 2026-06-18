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
        Route::post('news-sources', [AdminCatalogController::class, 'storeNewsSource'])->name('news-sources.store');
        Route::put('news-sources/{newsSource}', [AdminCatalogController::class, 'updateNewsSource'])->name('news-sources.update');
        Route::patch('news-sources/{newsSource}/toggle', [AdminCatalogController::class, 'toggleNewsSource'])->name('news-sources.toggle');
        Route::delete('news-sources/{newsSource}', [AdminCatalogController::class, 'destroyNewsSource'])->name('news-sources.destroy');

        // API providers (full CRUD)
        Route::get('providers', [AdminCatalogController::class, 'apiProviders'])->name('providers.index');
        Route::post('providers', [AdminCatalogController::class, 'storeApiProvider'])->name('providers.store');
        Route::put('providers/{apiProvider}', [AdminCatalogController::class, 'updateApiProvider'])->name('providers.update');
        Route::delete('providers/synthetic-data', [AdminCatalogController::class, 'purgeSyntheticData'])->name('providers.synthetic-data.destroy');
        Route::delete('providers/{apiProvider}', [AdminCatalogController::class, 'destroyApiProvider'])->name('providers.destroy');

        // Provider event history, sync logs, system notification center
        Route::get('provider-events', [AdminSystemController::class, 'providerEvents'])->name('provider-events.index');
        Route::get('sync-logs', [AdminSystemController::class, 'syncLogs'])->name('sync-logs.index');
        Route::get('system-notifications', [AdminSystemController::class, 'systemNotifications'])->name('system-notifications.index');

        // Users
        Route::get('users', [AdminCatalogController::class, 'users'])->name('users.index');
        Route::patch('users/{user}/admin', [AdminCatalogController::class, 'toggleAdmin'])->name('users.admin');

        // System: jobs / failed jobs / notification logs
        Route::get('jobs', [AdminSystemController::class, 'jobs'])->name('jobs.index');
        Route::post('jobs/{uuid}/retry', [AdminSystemController::class, 'retryFailed'])->name('jobs.retry');
        Route::delete('jobs/failed', [AdminSystemController::class, 'flushFailed'])->name('jobs.flush');
        Route::get('notifications', [AdminSystemController::class, 'notifications'])->name('notifications.index');
    });
