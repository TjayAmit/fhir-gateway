<?php

declare(strict_types=1);

use App\Models\Destination;

beforeEach(function () {
    Destination::query()->create([
        'hcpn_id' => 'telemedicine-internal',
        'display_name' => 'Telemedicine Service',
        'nhfr_code' => 'DOH000000000000001',
        'endpoint_url' => 'http://telemedicine.test/fhir',
        'auth_type' => 'bearer',
        'auth_credential_key' => 'telemedicine_token',
        'accepts' => ['referral', 'telemedicine'],
        'active' => true,
    ]);

    Destination::query()->create([
        'hcpn_id' => 'drstmh-kalibo',
        'display_name' => 'Dr. Rafael S. Tumbokon Memorial Hospital',
        'city' => 'Kalibo, Aklan',
        'nhfr_code' => '513',
        'endpoint_url' => null,
        'accepts' => ['referral'],
        'active' => true,
    ]);
});

it('lists only destinations that can actually receive a referral', function () {
    $this->withHeaders(asClient())->getJson('/api/registry/v1/hcpn')
        ->assertOk()
        ->assertJsonPath('count', 1)
        ->assertJsonPath('data.0.hcpn_id', 'telemedicine-internal')
        ->assertJsonPath('data.0.deliverable', true);
});

it('can show the full directory including facilities with no endpoint', function () {
    $this->withHeaders(asClient())->getJson('/api/registry/v1/hcpn?include_unreachable=1')
        ->assertOk()
        ->assertJsonPath('count', 2)
        // Listed so a sender knows the facility exists, flagged so they know it cannot receive.
        ->assertJsonPath('data.0.hcpn_id', 'drstmh-kalibo')
        ->assertJsonPath('data.0.deliverable', false);
});

it('filters by the request type a destination accepts', function () {
    $this->withHeaders(asClient())->getJson('/api/registry/v1/hcpn?accepts=telemedicine&include_unreachable=1')
        ->assertOk()
        ->assertJsonPath('count', 1)
        ->assertJsonPath('data.0.hcpn_id', 'telemedicine-internal');
});

it('never exposes endpoints or credentials', function () {
    $body = $this->withHeaders(asClient())->getJson('/api/registry/v1/hcpn?include_unreachable=1')->content();

    // A sender picks a destination; how we reach it is none of their business.
    expect($body)->not->toContain('telemedicine.test')
        ->and($body)->not->toContain('auth_credential_key')
        ->and($body)->not->toContain('telemedicine_token');
});

it('hides inactive destinations', function () {
    Destination::query()->where('hcpn_id', 'telemedicine-internal')->update(['active' => false]);

    $this->withHeaders(asClient())->getJson('/api/registry/v1/hcpn?include_unreachable=1')
        ->assertOk()
        ->assertJsonPath('count', 1)
        ->assertJsonPath('data.0.hcpn_id', 'drstmh-kalibo');
});
