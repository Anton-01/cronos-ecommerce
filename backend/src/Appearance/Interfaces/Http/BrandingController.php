<?php

declare(strict_types=1);

namespace Cronos\Appearance\Interfaces\Http;

use Cronos\Appearance\Application\ThemeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Routing\Controller;

/** The two things a browser asks for by fixed address: /manifest.webmanifest and /favicon.ico, both following the live theme. */
final class BrandingController extends Controller
{
    public function manifest(ThemeService $themes): JsonResponse
    {
        $live = $themes->live();
        $c = $live['tokens']['colors'];
        $icons = [];
        foreach ((array) ($live['assets']['favicon']['files'] ?? []) as $f) {
            in_array($f['size'] ?? null, [192, 512], true) && $icons[] = ['src' => $f['url'], 'sizes' => $f['size'].'x'.$f['size'], 'type' => 'image/png', 'purpose' => 'any'];
        }
        $name = $live['tokens']['identity']['siteName'];

        return (new JsonResponse([
            'name' => $name, 'short_name' => mb_substr($name, 0, 12), 'start_url' => '/', 'display' => 'standalone', 'background_color' => $c['background'],
            'theme_color' => $live['tokens']['identity']['themeColor'] ?? $c['primary'], 'icons' => $icons,
        ]))->header('Content-Type', 'application/manifest+json')->header('Cache-Control', 'public, max-age=3600');
    }

    public function favicon(ThemeService $themes): RedirectResponse
    {
        $ico = collect((array) ($themes->live()['assets']['favicon']['files'] ?? []))->firstWhere('format', 'ico');
        abort_if($ico === null, 404);

        // The file name carries a content hash: the browser may keep it for a year, a new logo is a new address.
        return redirect()->away($ico['url'], 302)->header('Cache-Control', 'public, max-age=3600');
    }
}
