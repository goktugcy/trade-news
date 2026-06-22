<?php

use App\Http\Controllers\Admin\AdminCatalogController;
use App\Http\Controllers\Admin\AdminDashboardController;
use App\Http\Controllers\Admin\AdminStockController;
use App\Http\Controllers\Admin\AdminStockHistoricalPriceController;
use App\Http\Controllers\Admin\AdminStooqHistoricalPriceController;
use App\Http\Controllers\Admin\AdminSystemController;
use App\Http\Controllers\Admin\AiSettingsController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified', 'admin'])
    ->prefix('admin')
    ->name('admin.')
    ->group(function () {
        Route::get('/', AdminDashboardController::class)->name('dashboard');

        // Stocks (full CRUD)
        Route::get('stocks', [AdminStockController::class, 'index'])->name('stocks.index');
        Route::post('stocks', [AdminStockController::class, 'store'])->name('stocks.store');
        Route::post('stocks/historical-prices/stooq', [AdminStooqHistoricalPriceController::class, 'store'])->name('stocks.historical-prices.stooq');
        Route::post('stocks/historical-prices/stooq/fetch', [AdminStooqHistoricalPriceController::class, 'fetchAll'])->name('stocks.historical-prices.stooq.fetch-all');
        Route::post('stocks/{stock:id}/historical-prices', [AdminStockHistoricalPriceController::class, 'store'])->name('stocks.historical-prices.store');
        Route::post('stocks/{stock:id}/historical-prices/stooq/fetch', [AdminStockHistoricalPriceController::class, 'fetchStooq'])->name('stocks.historical-prices.stooq.fetch');
        Route::put('stocks/{stock:id}', [AdminStockController::class, 'update'])->name('stocks.update');
        Route::delete('stocks/{stock:id}', [AdminStockController::class, 'destroy'])->name('stocks.destroy');

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

        // AI settings
        Route::get('ai-settings', [AiSettingsController::class, 'index'])->name('ai-settings.index');
        Route::patch('ai-settings', [AiSettingsController::class, 'updateSettings'])->name('ai-settings.update');
        Route::post('ai-settings/providers', [AiSettingsController::class, 'storeProvider'])->name('ai-settings.providers.store');
        Route::put('ai-settings/providers/{apiProvider}', [AiSettingsController::class, 'updateProvider'])->name('ai-settings.providers.update');
        Route::patch('ai-settings/providers/{apiProvider}/models/enable', [AiSettingsController::class, 'enableProviderModels'])->name('ai-settings.providers.models.enable');
        Route::delete('ai-settings/providers/{apiProvider}', [AiSettingsController::class, 'destroyProvider'])->name('ai-settings.providers.destroy');
        Route::post('ai-settings/models', [AiSettingsController::class, 'storeModel'])->name('ai-settings.models.store');
        Route::put('ai-settings/models/{aiModel}', [AiSettingsController::class, 'updateModel'])->name('ai-settings.models.update');
        Route::patch('ai-settings/models/{aiModel}/toggle', [AiSettingsController::class, 'toggleModel'])->name('ai-settings.models.toggle');
        Route::delete('ai-settings/models/{aiModel}', [AiSettingsController::class, 'destroyModel'])->name('ai-settings.models.destroy');
        Route::post('ai-settings/models/{aiModel}/activate', [AiSettingsController::class, 'activateModel'])->name('ai-settings.models.activate');
        Route::post('ai-settings/models/{aiModel}/test', [AiSettingsController::class, 'testModel'])->name('ai-settings.models.test');
        Route::patch('ai-settings/tasks/{task}', [AiSettingsController::class, 'updateTask'])->name('ai-settings.tasks.update');
        Route::post('ai-settings/tasks/{task}/test', [AiSettingsController::class, 'testTask'])->name('ai-settings.tasks.test');

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
