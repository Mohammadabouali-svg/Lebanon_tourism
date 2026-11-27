<?php

use App\Http\Controllers\Api\Admin;
use App\Http\Controllers\Api\Auth\EmailVerificationController;
use App\Http\Controllers\Api\Auth\PasswordResetController;
use App\Http\Controllers\Api\Auth\RegistrationController;
use App\Http\Controllers\Api\Auth\SessionController;
use App\Http\Controllers\Api\BookingController;
use App\Http\Controllers\Api\EmailChangeController;
use App\Http\Controllers\Api\Operator;
use App\Http\Controllers\Api\ProfileController;
use App\Http\Controllers\Api\ReferenceDataController;
use App\Http\Controllers\Api\ReviewController;
use App\Http\Controllers\Api\TourController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API routes (all prefixed with /api)
|--------------------------------------------------------------------------
|
| Read this file top to bottom: it is the security map of the application.
| Access rules are attached to GROUPS of routes, so a new endpoint added to
| a group automatically gets that group's protection:
|
|   auth:sanctum    → must be logged in (session cookie)
|   active          → account not suspended
|   verified        → e-mail address confirmed
|   role:xxx        → must have that role
|   operator.approved → operator company approved by an admin
|   throttle:xxx    → rate limited (limits defined in AppServiceProvider)
|
| Run `make artisan c="route:list --path=api"` to see the final result.
*/

// --- Public ------------------------------------------------------------------

Route::get('reference-data', ReferenceDataController::class);
Route::get('tours', [TourController::class, 'index']);
Route::get('tours/{tour}', [TourController::class, 'show'])->whereNumber('tour');
Route::get('tours/{tour}/reviews', [ReviewController::class, 'index'])->whereNumber('tour');

// --- Authentication ------------------------------------------------------------

Route::middleware('throttle:auth')->group(function () {
    Route::post('register', [RegistrationController::class, 'traveler']);
    Route::post('register/operator', [RegistrationController::class, 'operator']);
    Route::post('login', [SessionController::class, 'store']);
    Route::post('forgot-password', [PasswordResetController::class, 'sendLink']);
    Route::post('reset-password', [PasswordResetController::class, 'reset']);
});

// Works logged in or not: the link may be opened on another device.
Route::post('email/verify/{id}/{hash}', [EmailVerificationController::class, 'verify'])
    ->middleware(['signed:relative', 'throttle:auth'])
    ->whereNumber('id')
    ->name('verification.verify');

// The link mailed to a NEW address (Exercise 4, D20). Same reasoning as
// above: works logged in or not; the signature proves the inbox.
Route::post('email/change/{id}/{hash}', [EmailChangeController::class, 'confirm'])
    ->middleware(['signed:relative', 'throttle:auth'])
    ->whereNumber('id')
    ->name('email-change.confirm');

// --- Any logged-in user ------------------------------------------------------

Route::middleware(['auth:sanctum', 'active'])->group(function () {
    Route::post('logout', [SessionController::class, 'destroy']);
    Route::post('email/verification-notification', [EmailVerificationController::class, 'resend'])
        ->middleware('throttle:6,1');

    Route::get('user', [ProfileController::class, 'show']);
    Route::put('user/profile', [ProfileController::class, 'update']);
    Route::put('user/password', [ProfileController::class, 'updatePassword']);
    // Not behind `verified`: a user who mistyped their address at sign-up
    // must be able to fix it. Throttled because each request sends an e-mail.
    Route::post('user/email-change', [EmailChangeController::class, 'store'])->middleware('throttle:6,1');
    Route::delete('user/email-change', [EmailChangeController::class, 'destroy']);
    Route::get('user/wallet', [ProfileController::class, 'wallet']);

    // --- Travelers -----------------------------------------------------------

    Route::middleware(['verified', 'role:traveler'])->group(function () {
        Route::get('bookings', [BookingController::class, 'index']);
        // A booking is for one departure (date) of a tour (Exercise 10, D23).
        Route::post('departures/{departure}/quote', [BookingController::class, 'quote'])->middleware('throttle:quotes');
        Route::post('departures/{departure}/bookings', [BookingController::class, 'store'])->middleware('throttle:bookings');
        Route::post('bookings/{booking}/cancel', [BookingController::class, 'cancel']);
        Route::post('tours/{tour}/reviews', [ReviewController::class, 'store'])->middleware('throttle:uploads');
    });

    // Authors and admins (moderation) can delete reviews; ReviewPolicy decides.
    Route::delete('reviews/{review}', [ReviewController::class, 'destroy']);

    // --- Tour operators ------------------------------------------------------

    Route::prefix('operator')
        ->middleware(['verified', 'role:operator', 'operator.approved'])
        ->group(function () {
            Route::get('dashboard', Operator\DashboardController::class);
            Route::get('bookings', [Operator\BookingController::class, 'index']);
            Route::get('bookings/export', [Operator\BookingController::class, 'export']);

            Route::get('tours', [Operator\TourController::class, 'index']);
            Route::post('tours', [Operator\TourController::class, 'store'])->middleware('throttle:uploads');
            Route::get('tours/{tour}', [Operator\TourController::class, 'show']);
            // POST + `_method=PUT`, because the body is multipart/form-data (see TourRequest).
            Route::put('tours/{tour}', [Operator\TourController::class, 'update'])->middleware('throttle:uploads');
            Route::delete('tours/{tour}', [Operator\TourController::class, 'destroy']);

            // The dates a tour runs on (Exercise 10, D23). Ownership: TourPolicy / DeparturePolicy.
            Route::get('tours/{tour}/departures', [Operator\DepartureController::class, 'index']);
            Route::post('tours/{tour}/departures', [Operator\DepartureController::class, 'store']);
            Route::put('departures/{departure}', [Operator\DepartureController::class, 'update']);
            Route::delete('departures/{departure}', [Operator\DepartureController::class, 'destroy']);
        });

    // --- Administrators ------------------------------------------------------

    Route::prefix('admin')
        ->middleware(['verified', 'role:admin'])
        ->group(function () {
            Route::get('dashboard', Admin\DashboardController::class);

            Route::get('users', [Admin\UserController::class, 'index']);
            Route::post('admins', [Admin\UserController::class, 'storeAdmin']);
            Route::post('users/{user}/suspend', [Admin\UserController::class, 'suspend']);
            Route::post('users/{user}/unsuspend', [Admin\UserController::class, 'unsuspend']);
            Route::post('users/{user}/wallet-credits', [Admin\UserController::class, 'creditWallet']);

            Route::get('operators', [Admin\OperatorController::class, 'index']);
            Route::post('operators/{user}/approve', [Admin\OperatorController::class, 'approve']);
            Route::post('operators/{user}/suspend', [Admin\OperatorController::class, 'suspend']);

            Route::get('tours', [Admin\TourController::class, 'index']);
            Route::patch('tours/{tour}/status', [Admin\TourController::class, 'updateStatus']);

            Route::get('bookings', [Admin\BookingController::class, 'index']);
            Route::get('bookings/export', [Admin\BookingController::class, 'export']);

            Route::apiResource('discounts', Admin\DiscountController::class)->except('show');

            // Read-only: events are written by the services, never through the API (D25).
            Route::get('audit-events', [Admin\AuditEventController::class, 'index']);

            Route::get('regions', [Admin\CatalogController::class, 'regions']);
            Route::post('regions', [Admin\CatalogController::class, 'storeRegion']);
            Route::put('regions/{region}', [Admin\CatalogController::class, 'updateRegion']);
            Route::delete('regions/{region}', [Admin\CatalogController::class, 'destroyRegion']);

            Route::get('cities', [Admin\CatalogController::class, 'cities']);
            Route::post('cities', [Admin\CatalogController::class, 'storeCity']);
            Route::put('cities/{city}', [Admin\CatalogController::class, 'updateCity']);
            Route::delete('cities/{city}', [Admin\CatalogController::class, 'destroyCity']);

            Route::get('categories', [Admin\CatalogController::class, 'categories']);
            Route::post('categories', [Admin\CatalogController::class, 'storeCategory']);
            Route::put('categories/{category}', [Admin\CatalogController::class, 'updateCategory']);
            Route::delete('categories/{category}', [Admin\CatalogController::class, 'destroyCategory']);
        });
});
