<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Selli\Ticketing\Facades\Ticketing;
use Selli\Ticketing\Tests\Fixtures\TestRequester;

beforeEach(function (): void {
    Carbon::setTestNow('2026-03-31 12:00:00');
});

afterEach(function (): void {
    Carbon::setTestNow();
});

it('serves the KPIs of a period to agents', function (): void {
    Ticketing::open(type: 'support', title: 'Today', channel: 'email');
    $this->actingAs(makeUser());

    $this->getJson(API.'/reports/metrics?from=2026-03-01&to=2026-03-31&channel=email')
        ->assertOk()
        ->assertJsonPath('data.volume.created', 1)
        ->assertJsonPath('data.backlog.open', 1)
        ->assertJsonPath('data.period.to', Carbon::parse('2026-03-31')->endOfDay()->toIso8601String());

    $this->getJson(API.'/reports/metrics?channel=web')->assertOk()->assertJsonPath('data.volume.created', 0);
});

it('keeps the reports from requesters', function (): void {
    $this->actingAs(TestRequester::query()->create(['name' => 'Customer']));

    $this->getJson(API.'/reports/metrics')->assertForbidden();
    $this->getJson(API.'/reports/breakdown/channel')->assertForbidden();
    $this->getJson(API.'/reports/tickets')->assertForbidden();
});

it('validates the period and the dimension', function (): void {
    $this->actingAs(makeUser());

    $this->getJson(API.'/reports/metrics?from=yesterday-ish')->assertUnprocessable()->assertJsonValidationErrors('from');
    $this->getJson(API.'/reports/metrics?from=2026-03-10&to=2026-03-01')->assertUnprocessable()->assertJsonValidationErrors('from');
    $this->getJson(API.'/reports/breakdown/mood')->assertUnprocessable()->assertJsonValidationErrors('dimension');
});

it('serves breakdowns as JSON or CSV, and the tickets as CSV', function (): void {
    Ticketing::open(type: 'support', title: 'Printer', channel: 'email');
    $this->actingAs(makeUser());

    $this->getJson(API.'/reports/breakdown/channel')
        ->assertOk()
        ->assertJsonPath('data.0.key', 'email')
        ->assertJsonPath('data.0.created', 1);

    $csv = $this->get(API.'/reports/breakdown/channel?format=csv');
    $csv->assertOk()->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
    expect($csv->streamedContent())->toContain("email,email,1,0,1,,\n");

    $tickets = $this->get(API.'/reports/tickets?channel=email');
    $tickets->assertOk()->assertDownload('tickets.csv');
    expect($tickets->streamedContent())->toContain(',Printer,');
});
