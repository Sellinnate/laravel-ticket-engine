<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Selli\Ticketing\Http\Controllers\Widget\ConfigController;
use Selli\Ticketing\Http\Controllers\Widget\ConversationController;
use Selli\Ticketing\Http\Controllers\Widget\KnowledgeController;
use Selli\Ticketing\Http\Middleware\ResolveWidgetKey;

/*
 * Public widget API (conversations + public knowledge base). Mounted by the
 * service provider under the configured prefix/version when
 * ticketing.widget.enabled is true, behind CORS and the widget rate limiters.
 * Every route first resolves the X-Ticketing-Key widget key, which also fixes
 * the tenant; conversation routes then require the conversation's bearer token.
 */

Route::middleware(ResolveWidgetKey::class)->group(function (): void {
    Route::get('config', [ConfigController::class, 'show'])->name('ticketing.widget.config');

    Route::post('conversations', [ConversationController::class, 'store'])
        ->middleware('throttle:ticketing-widget-start')
        ->name('ticketing.widget.conversations.store');
    Route::get('conversations/{conversation}/messages', [ConversationController::class, 'messages'])->name('ticketing.widget.conversations.messages');
    Route::post('conversations/{conversation}/messages', [ConversationController::class, 'post'])->name('ticketing.widget.conversations.post');
    Route::post('conversations/{conversation}/escalate', [ConversationController::class, 'escalate'])->name('ticketing.widget.conversations.escalate');
    Route::post('conversations/{conversation}/close', [ConversationController::class, 'close'])->name('ticketing.widget.conversations.close');

    Route::get('kb/search', [KnowledgeController::class, 'search'])->name('ticketing.widget.kb.search');
    Route::get('kb/articles/{slug}', [KnowledgeController::class, 'show'])->name('ticketing.widget.kb.show');
    Route::post('kb/articles/{slug}/feedback', [KnowledgeController::class, 'feedback'])->name('ticketing.widget.kb.feedback');
});

// CORS preflight for every widget route (answered by the WidgetCors middleware).
Route::options('{any}', fn () => response('', 204))->where('any', '.*')->name('ticketing.widget.preflight');
