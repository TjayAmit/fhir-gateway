<?php

declare(strict_types=1);

use App\Http\Controllers\Api\InboundReferralController;
use App\Http\Controllers\Api\IntakeController;
use App\Http\Controllers\Api\PatientSearchController;
use App\Http\Controllers\Api\RegistryController;
use App\Http\Controllers\Api\TaskSearchController;
use Illuminate\Support\Facades\Route;

/*
 * Gateway API.
 *
 * Every route is authenticated with a per-system bearer token and rate limited per client.
 * The token is also the scope: a client's facility comes from its configuration, never from
 * the request, and reads are filtered by it server-side.
 *
 * Four surfaces:
 *   - registry, so a sender can choose a destination without knowing how we reach it
 *   - intake, where a plain-JSON referral becomes a PH Core / eReferral submission
 *   - Task and Patient, the FHIR-shaped read side
 *   - POST /fhir, where a facility refers a patient to us
 */

Route::middleware('gateway.client')->group(function (): void {

    Route::prefix('registry/v1')->middleware('throttle:fhir-read')->group(function (): void {
        Route::get('hcpn', [RegistryController::class, 'index'])->name('registry.hcpn.index');
    });

    Route::prefix('intake/v1')->group(function (): void {
        Route::post('requests', [IntakeController::class, 'store'])
            ->middleware('throttle:fhir-intake')
            ->name('intake.requests.store');

        Route::get('requests/{messageId}', [IntakeController::class, 'show'])
            ->middleware('throttle:fhir-read')
            ->name('intake.requests.show');
    });

    Route::prefix('fhir')->group(function (): void {
        Route::get('Task', [TaskSearchController::class, 'index'])
            ->middleware('throttle:fhir-search')
            ->name('fhir.task.index');

        Route::get('Task/{id}', [TaskSearchController::class, 'show'])
            ->middleware('throttle:fhir-read')
            ->name('fhir.task.show');

        // Identifier lookup against the native systems. Tightest bucket of the three.
        Route::get('Patient', [PatientSearchController::class, 'index'])
            ->middleware('throttle:fhir-search')
            ->name('fhir.patient.index');
    });

    // Flow 2: a facility refers a patient to us. Posted to the FHIR base, as a transaction.
    Route::post('fhir', [InboundReferralController::class, 'store'])
        ->middleware('throttle:fhir-intake')
        ->name('fhir.inbound.store');
});
