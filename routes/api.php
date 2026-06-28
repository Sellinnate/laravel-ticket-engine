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
 * wire this file yourself. Controllers resolve {ticket} via the configured,
 * tenant-scoped model, so a cross-tenant id 404s.
 *
 * Route names are namespaced under `ticketing.` so they can't collide with a
 * host app's own `tickets.*` names — a collision would break route caching or
 * make route() resolve to the wrong handler.
 */

Route::get('tickets', [TicketController::class, 'index'])->name('ticketing.tickets.index');
Route::post('tickets', [TicketController::class, 'store'])->name('ticketing.tickets.store');
Route::get('tickets/{ticket}', [TicketController::class, 'show'])->name('ticketing.tickets.show');

Route::post('tickets/{ticket}/messages', [MessageController::class, 'store'])->name('ticketing.tickets.messages.store');
Route::post('tickets/{ticket}/transitions', [TransitionController::class, 'store'])->name('ticketing.tickets.transitions.store');
Route::post('tickets/{ticket}/assignment', [AssignmentController::class, 'store'])->name('ticketing.tickets.assignment.store');
Route::post('tickets/{ticket}/attachments', [AttachmentController::class, 'store'])->name('ticketing.tickets.attachments.store');
Route::post('tickets/{ticket}/csat', [CsatController::class, 'store'])->name('ticketing.tickets.csat.store');
