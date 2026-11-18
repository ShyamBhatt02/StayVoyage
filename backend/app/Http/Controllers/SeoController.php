<?php

namespace App\Http\Controllers;

use App\Models\Destination;
use App\Models\Hotel;
use App\Models\TourPackage;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;

class SeoController
{
    public function sitemap(): Response
    {
        $xml = Cache::remember('seo.sitemap', 3600, function () {
            $base = rtrim((string) config('app.url'), '/');
            $urls = collect(['/', '/hotels', '/tours', '/tour-builder', '/membership', '/destinations', '/offers', '/about', '/contact', '/faq'])
                ->map(fn ($p) => ['loc' => $base.$p, 'lastmod' => now()->toDateString(), 'priority' => $p === '/' ? '1.0' : '0.8']);

            Hotel::query()->active()->get(['slug', 'updated_at'])->each(fn ($h) => $urls->push(['loc' => "{$base}/hotels/{$h->slug}", 'lastmod' => $h->updated_at->toDateString(), 'priority' => '0.9']));
            Destination::query()->active()->get(['slug', 'updated_at'])->each(fn ($d) => $urls->push(['loc' => "{$base}/destinations/{$d->slug}", 'lastmod' => $d->updated_at->toDateString(), 'priority' => '0.8']));
            TourPackage::query()->active()->get(['slug', 'updated_at'])->each(fn ($t) => $urls->push(['loc' => "{$base}/tours/{$t->slug}", 'lastmod' => $t->updated_at->toDateString(), 'priority' => '0.8']));

            $body = $urls->map(fn ($u) => "<url><loc>".e($u['loc'])."</loc><lastmod>{$u['lastmod']}</lastmod><priority>{$u['priority']}</priority></url>")->implode('');

            return '<?xml version="1.0" encoding="UTF-8"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'.$body.'</urlset>';
        });

        return response($xml, 200, ['Content-Type' => 'application/xml']);
    }

    public function robots(): Response
    {
        $base = rtrim((string) config('app.url'), '/');
        $body = "User-agent: *\nAllow: /\nDisallow: /account\nDisallow: /admin\nDisallow: /partner\nDisallow: /checkout\nDisallow: /api/\n\nSitemap: {$base}/sitemap.xml\n";

        return response($body, 200, ['Content-Type' => 'text/plain']);
    }
}
