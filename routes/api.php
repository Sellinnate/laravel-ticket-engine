<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Selli\Ticketing\Http\Controllers\Api\AiController;
use Selli\Ticketing\Http\Controllers\Api\AssignmentController;
use Selli\Ticketing\Http\Controllers\Api\AttachmentController;
use Selli\Ticketing\Http\Controllers\Api\CsatController;
use Selli\Ticketing\Http\Controllers\Api\KnowledgeController;
use Selli\Ticketing\Http\Controllers\Api\MessageController;
use Selli\Ticketing\Http\Controllers\Api\ReportController;
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
Route::post('tickets/{ticket}/takeover', [AiController::class, 'takeOver'])->name('ticketing.tickets.takeover');
Route::post('tickets/{ticket}/escalate', [AiController::class, 'escalate'])->name('ticketing.tickets.escalate');
Route::post('tickets/{ticket}/articles', [KnowledgeController::class, 'link'])->name('ticketing.tickets.articles.store');

Route::get('kb/articles', [KnowledgeController::class, 'index'])->name('ticketing.kb.articles.index');
Route::post('kb/articles', [KnowledgeController::class, 'store'])->name('ticketing.kb.articles.store');
Route::get('kb/articles/{article}', [KnowledgeController::class, 'show'])->name('ticketing.kb.articles.show');
Route::patch('kb/articles/{article}', [KnowledgeController::class, 'update'])->name('ticketing.kb.articles.update');
Route::post('kb/articles/{article}/publish', [KnowledgeController::class, 'publish'])->name('ticketing.kb.articles.publish');
Route::post('kb/articles/{article}/archive', [KnowledgeController::class, 'archive'])->name('ticketing.kb.articles.archive');
Route::post('kb/articles/{article}/feedback', [KnowledgeController::class, 'feedback'])->name('ticketing.kb.articles.feedback');

Route::get('reports/metrics', [ReportController::class, 'metrics'])->name('ticketing.reports.metrics');
Route::get('reports/breakdown/{dimension}', [ReportController::class, 'breakdown'])->name('ticketing.reports.breakdown');
Route::get('reports/tickets', [ReportController::class, 'tickets'])->name('ticketing.reports.tickets');
