<?php

use App\Services\PlugAndPay;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config(['services.plug_and_pay.api_key' => 'pnp-token']);
    Http::preventStrayRequests();
});

it('fetches all pages of orders with the filters and includes', function () {
    Http::fake([
        'api.plugandpay.com/v2/orders?*page=1*' => Http::response(['data' => [['id' => 1], ['id' => 2]], 'meta' => ['last_page' => 2]]),
        'api.plugandpay.com/v2/orders?*page=2*' => Http::response(['data' => [['id' => 3]], 'meta' => ['last_page' => 2]]),
    ]);

    $ids = app(PlugAndPay::class)->orders(['payment_status' => ['reversed', 'open']])->collect()->pluck('id')->all();

    expect($ids)->toBe([1, 2, 3]);
    Http::assertSent(fn (Request $request): bool => str_contains(urldecode($request->url()), 'payment_status=reversed,open')
        && str_contains(urldecode($request->url()), 'include=billing,payment')
        && $request->hasHeader('Authorization', 'Bearer pnp-token'));
});

it('fetches one order', function () {
    Http::fake(['api.plugandpay.com/v2/orders/18210996*' => Http::response(['data' => ['id' => 18210996, 'payment' => ['status' => 'reversed']]])]);

    expect(app(PlugAndPay::class)->order(18210996))->toMatchArray(['id' => 18210996, 'payment' => ['status' => 'reversed']]);
});

it('knows when it is not configured', function () {
    config(['services.plug_and_pay.api_key' => null]);

    expect(app(PlugAndPay::class)->isConfigured())->toBeFalse();
});
