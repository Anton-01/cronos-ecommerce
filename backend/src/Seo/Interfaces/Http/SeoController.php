<?php

declare(strict_types=1);

namespace Cronos\Seo\Interfaces\Http;

use Cronos\Seo\Application\RobotsBuilder;
use Cronos\Seo\Application\SitemapBuilder;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;

final class SeoController extends Controller
{
    public function robots(RobotsBuilder $robots): Response
    {
        return new Response($robots->build(), 200, ['Content-Type' => 'text/plain; charset=UTF-8', 'Cache-Control' => 'public, max-age=3600']);
    }

    public function index(SitemapBuilder $sitemap): Response
    {
        return $this->xml($sitemap->index());
    }

    public function file(string $name, SitemapBuilder $sitemap): Response
    {
        $xml = $sitemap->file($name);
        $xml !== null || abort(404);

        return $this->xml($xml);
    }

    private function xml(string $body): Response
    {
        return new Response($body, 200, ['Content-Type' => 'application/xml; charset=UTF-8', 'Cache-Control' => 'public, max-age=900']);
    }
}
