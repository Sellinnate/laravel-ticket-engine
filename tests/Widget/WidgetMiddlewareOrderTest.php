<?php

declare(strict_types=1);

use Selli\Ticketing\Facades\Ticketing;

it('keeps CORS and the key ahead of the throttle inside the api group', function (): void {
    ['key' => $key] = Ticketing::widgetKeys()->create('Acme', ['https://acme.example']);
    $headers = ['X-Ticketing-Key' => $key->public_key, 'Origin' => 'https://acme.example'];

    $this->getJson('/ticketing/widget/v1/config', $headers)->assertOk();
    $this->getJson('/ticketing/widget/v1/config', $headers)->assertOk();
    $this->getJson('/ticketing/widget/v1/config', $headers)
        ->assertStatus(429)
        ->assertHeader('Access-Control-Allow-Origin', 'https://acme.example');

    // Preflights are answered before any throttling.
    $this->call('OPTIONS', '/ticketing/widget/v1/config', server: ['HTTP_ORIGIN' => 'https://acme.example'])
        ->assertNoContent()
        ->assertHeader('Access-Control-Allow-Origin', 'https://acme.example');
});
