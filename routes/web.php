<?php

use App\Http\Controllers\Admin\AmpIndexController;
use App\Http\Controllers\Admin\AuditController;
use App\Http\Controllers\Admin\CompanyController;
use App\Http\Controllers\Admin\ExportController;
use App\Http\Controllers\Admin\PeriodController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\AmpController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\PasswordResetController;
use App\Http\Controllers\CompanyDashboardController;
use App\Http\Controllers\EntryController;
use App\Http\Controllers\FinancialLineController;
use App\Http\Controllers\Group\ComparisonController;
use App\Http\Controllers\Group\GroupDashboardController;
use App\Http\Controllers\Group\TrackerController;
use App\Http\Controllers\ResultsController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Access model
|--------------------------------------------------------------------------
| company_user routes never take a company id: the company is always the
| logged-in user's own. Group routes (/group/...) take {company} and are
| restricted to hesb_admin + ceo by middleware, then checked again by
| CompanyPolicy. Every write route is additionally admin-only or policy-gated,
| so the CEO receives 403 on all of them.
*/

Route::pattern('year', '20[0-9]{2}');
Route::pattern('month', '0?[1-9]|1[0-2]');
Route::pattern('company', '[0-9]+');
Route::pattern('line', '[0-9]+');
Route::pattern('user', '[0-9]+');

Route::middleware('guest')->group(function () {
    Route::get('/login', [LoginController::class, 'create'])->name('login');
    Route::post('/login', [LoginController::class, 'store'])->middleware('throttle:login');
    Route::get('/forgot-password', [PasswordResetController::class, 'request'])->name('password.request');
    Route::post('/forgot-password', [PasswordResetController::class, 'email'])->middleware('throttle:login')->name('password.email');
    Route::get('/reset-password/{token}', [PasswordResetController::class, 'edit'])->name('password.reset');
    Route::post('/reset-password', [PasswordResetController::class, 'update'])->middleware('throttle:login')->name('password.update');
});

Route::middleware(['auth', 'active'])->group(function () {
    Route::post('/logout', [LoginController::class, 'destroy'])->name('logout');

    Route::get('/', function () {
        return auth()->user()->isCompanyUser()
            ? redirect()->route('dashboard')
            : redirect()->route('group.dashboard');
    })->name('home');

    /* ---------------- Company portal (own company only) ---------------- */
    Route::middleware('role:company_user')->group(function () {
        Route::get('/dashboard', [CompanyDashboardController::class, 'show'])->name('dashboard');

        Route::get('/entries/{year?}/{month?}', [EntryController::class, 'edit'])->name('entries.edit');
        Route::put('/entries/{year}/{month}', [EntryController::class, 'update'])->name('entries.update');
        Route::get('/entries/{year}/{month}/template', [EntryController::class, 'template'])->name('entries.template');
        Route::post('/entries/{year}/{month}/upload', [EntryController::class, 'upload'])->middleware('throttle:uploads')->name('entries.upload');

        Route::post('/lines', [FinancialLineController::class, 'store'])->name('lines.store');
        Route::delete('/lines/{line}', [FinancialLineController::class, 'destroy'])->name('lines.destroy');

        Route::get('/amp/{year?}', [AmpController::class, 'show'])->name('amp.show');
        Route::get('/amp/{year}/template', [AmpController::class, 'template'])->name('amp.template');

        Route::get('/results/{year?}', [ResultsController::class, 'show'])->name('results.show');
        Route::post('/results/upload', [ResultsController::class, 'upload'])->middleware('throttle:uploads')->name('results.upload');
        Route::post('/results/confirm', [ResultsController::class, 'confirm'])->name('results.confirm');
        Route::post('/results/cancel', [ResultsController::class, 'cancel'])->name('results.cancel');
    });

    /* ---------------- Group: admin + CEO (read) ---------------- */
    Route::middleware('role:hesb_admin,ceo')->prefix('group')->name('group.')->group(function () {
        Route::get('/', [GroupDashboardController::class, 'show'])->name('dashboard');
        Route::get('/compare', [ComparisonController::class, 'show'])->name('compare');
        Route::get('/tracker', [TrackerController::class, 'show'])->name('tracker');

        Route::prefix('companies/{company}')->group(function () {
            Route::get('/', [CompanyDashboardController::class, 'show'])->name('companies.dashboard');
            Route::get('/entries/{year?}/{month?}', [EntryController::class, 'edit'])->name('entries.edit');
            Route::get('/entries/{year}/{month}/template', [EntryController::class, 'template'])->name('entries.template');
            Route::get('/amp/{year?}', [AmpController::class, 'show'])->name('amp.show');
            Route::get('/amp/{year}/template', [AmpController::class, 'template'])->name('amp.template');
            Route::get('/results/{year?}', [ResultsController::class, 'show'])->name('results.show');

            /* writes: admin only (CEO → 403) */
            Route::middleware('role:hesb_admin')->group(function () {
                Route::put('/entries/{year}/{month}', [EntryController::class, 'update'])->name('entries.update');
                Route::post('/entries/{year}/{month}/upload', [EntryController::class, 'upload'])->middleware('throttle:uploads')->name('entries.upload');
                Route::post('/lines', [FinancialLineController::class, 'store'])->name('lines.store');
                Route::delete('/lines/{line}', [FinancialLineController::class, 'destroy'])->name('lines.destroy');
                Route::put('/amp/{year}', [AmpController::class, 'update'])->name('amp.update');
                Route::post('/amp/{year}/import', [AmpController::class, 'import'])->middleware('throttle:uploads')->name('amp.import');
                Route::post('/results/upload', [ResultsController::class, 'upload'])->middleware('throttle:uploads')->name('results.upload');
                Route::post('/results/confirm', [ResultsController::class, 'confirm'])->name('results.confirm');
                Route::post('/results/cancel', [ResultsController::class, 'cancel'])->name('results.cancel');
            });
        });
    });

    /* ---------------- Administration: admin only ---------------- */
    Route::middleware('role:hesb_admin')->prefix('admin')->name('admin.')->group(function () {
        Route::get('/amp', AmpIndexController::class)->name('amp.index');
        Route::get('/periods', [PeriodController::class, 'index'])->name('periods.index');
        Route::put('/periods', [PeriodController::class, 'update'])->name('periods.update');
        Route::resource('companies', CompanyController::class)->except(['show', 'destroy']);
        Route::resource('users', UserController::class)->except(['show', 'destroy']);
        Route::get('/audit', [AuditController::class, 'index'])->name('audit.index');
        Route::get('/exports', [ExportController::class, 'index'])->name('exports.index');
        Route::get('/exports/{type}', [ExportController::class, 'download'])->middleware('throttle:uploads')->name('exports.download');
    });
});
