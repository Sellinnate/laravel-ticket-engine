<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Selli\Ticketing\Http\Controllers\Api\AssignmentController;
use Selli\Ticketing\Http\Controllers\Api\AttachmentController;
use Selli\Ticketing\Http\Controllers\Api\CsatController;
use Selli\Ticketing\Http\Controllers\Api\MessageController;
use Selli\Ticketing\Http\Controllers\Api\TicketController;
use Selli\Ticketing\Http\Controllers\Api\TransitionController;

/*
 * Versioned ticketing API. Mounted by the service provider under the configured
 * prefix/version + middleware when ticketing.api.enabled is true, or publish and
 * wire this file yourself. The {ticket} binding resolves the configured ticket
 * model and is tenant-scoped, so a cross-tenant id 404s.
 */

Route::get('tickets', [TicketController::class, 'index'])->name('tickets.index');
Route::post('tickets', [TicketController::class, 'store'])->name('tickets.store');
Route::get('tickets/{ticket}', [TicketController::class, 'show'])->name('tickets.show');

Route::post('tickets/{ticket}/messages', [MessageController::class, 'store'])->name('tickets.messages.store');
Route::post('tickets/{ticket}/transitions', [TransitionController::class, 'store'])->name('tickets.transitions.store');
Route::post('tickets/{ticket}/assignment', [AssignmentController::class, 'store'])->name('tickets.assignment.store');
Route::post('tickets/{ticket}/attachments', [AttachmentController::class, 'store'])->name('tickets.attachments.store');
Route::post('tickets/{ticket}/csat', [CsatController::class, 'store'])->name('tickets.csat.store');
