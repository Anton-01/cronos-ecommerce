<?php

use Cronos\Payments\Interfaces\Http\WebhookController;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function (): void {
            // Provider callbacks: no session, no CSRF, own rate limit. The controller only stores and queues.
            Route::middleware('throttle:120,1')->group(function () {
                Route::get('/robots.txt', [\Cronos\Seo\Interfaces\Http\SeoController::class, 'robots'])->name('seo.robots');
                Route::get('/sitemap.xml', [\Cronos\Seo\Interfaces\Http\SeoController::class, 'index'])->name('seo.sitemap');
                Route::get('/sitemaps/{name}.xml', [\Cronos\Seo\Interfaces\Http\SeoController::class, 'file'])->where('name', '[A-Za-z0-9-]{1,40}')->name('seo.sitemap.file');
            });
            Route::middleware(['signed', 'throttle:60,1'])->get('/exports/{export}/download', \Cronos\Reports\Interfaces\Http\ExportDownloadController::class)->name('exports.download');
            Route::middleware('throttle:webhooks')->post('/webhooks/shipping/{carrier}', \Cronos\Shipping\Interfaces\Http\ShippingWebhookController::class)->name('webhooks.shipping');
            Route::middleware('throttle:webhooks')->post('/webhooks/{provider}', WebhookController::class)->name('webhooks.receive');
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Behind a load balancer/CDN the real visitor IP (and so their country and clock) comes from X-Forwarded-For: list its
        // addresses in TRUSTED_PROXIES (comma separated, or * for all). Left empty, forwarded headers are ignored.
        $proxies = array_filter(array_map('trim', explode(',', (string) env('TRUSTED_PROXIES', ''))));
        $proxies === [] || $middleware->trustProxies(at: $proxies === ['*'] ? '*' : $proxies);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
