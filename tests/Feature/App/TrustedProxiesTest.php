<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

const TRUSTED_PROXIES_CLOUDFLARE_IP = '172.68.1.10';
const TRUSTED_PROXIES_CLIENT_IP = '203.0.113.9';
const TRUSTED_PROXIES_FAKED_IP = '160.79.104.10';

beforeEach(function () {
    Route::get('/_test/client-ip', fn (Request $request): string => (string) $request->ip())
        ->name('test.client-ip');

    Route::getRoutes()->refreshNameLookups();
});

/**
 * A proxy keeps the X-Forwarded-For the client sent and appends the address
 * the request really came from.
 */
function trustedProxiesClientIp(object $test, string $remoteAddress): string
{
    return $test->withServerVariables(['REMOTE_ADDR' => $remoteAddress])
        ->withHeader('X-Forwarded-For', TRUSTED_PROXIES_FAKED_IP.', '.TRUSTED_PROXIES_CLIENT_IP)
        ->get(route('test.client-ip'))
        ->assertSuccessful()
        ->getContent();
}

test('cloudflare gives the client ip it appended, not one the client sent', function (string $cloudflareIp) {
    expect(trustedProxiesClientIp($this, $cloudflareIp))->toBe(TRUSTED_PROXIES_CLIENT_IP);
})->with([
    'ipv4' => TRUSTED_PROXIES_CLOUDFLARE_IP,
    'ipv6' => '2606:4700::1',
]);

test('a request that does not come from cloudflare keeps its own address', function () {
    expect(trustedProxiesClientIp($this, '198.51.100.7'))->toBe('198.51.100.7');
});

test('another proxy is trusted when listed', function () {
    config(['trustedproxy.proxies' => '10.0.0.0/8,192.168.1.1']);

    expect(trustedProxiesClientIp($this, '10.1.2.3'))->toBe(TRUSTED_PROXIES_CLIENT_IP)
        ->and(trustedProxiesClientIp($this, TRUSTED_PROXIES_CLOUDFLARE_IP))->toBe(TRUSTED_PROXIES_CLOUDFLARE_IP);
});

test('every proxy is trusted with a wildcard', function () {
    config(['trustedproxy.proxies' => '*']);

    expect(trustedProxiesClientIp($this, '198.51.100.7'))->toBe(TRUSTED_PROXIES_FAKED_IP);
});
