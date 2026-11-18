<?php

namespace App\Http\Controllers;

use App\Models\Destination;
use App\Models\Hotel;
use App\Models\TourPackage;
use App\Services\SettingsService;
use App\Support\Media;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Serves the built React SPA (public/app/index.html) for every non-API route and injects page-specific
 * SEO metadata server-side (title, description, Open Graph, canonical, JSON-LD) so crawlers and link
 * previews see real content for hotels, destinations and tours.
 */
class SpaController
{
    public function __invoke(Request $request, SettingsService $settings): Response
    {
        $index = public_path('app/index.html');
        if (! is_file($index)) {
            return response('<h1>StayVoyage</h1><p>The frontend has not been built yet. Run <code>npm run build</code> in /frontend.</p>', 503);
        }
        $html = file_get_contents($index);
        $site = $settings->get('site.name', 'StayVoyage');
        // Use the host the visitor actually opened (works on :8096, :8000, LAN IPs…), not APP_URL.
        $base = $request->getSchemeAndHttpHost();
        $path = '/'.ltrim($request->path(), '/');

        $meta = $this->metaFor($path, $site, $base) ?? [
            'title' => "{$site} — ".$settings->get('site.tagline'),
            'description' => 'Book hand-picked hotels, unlock up to 30 membership nights a month, and build your own day-by-day trips with StayVoyage.',
            'image' => $base.'/storage/seed/misc/hero-beach.jpg',
            'jsonld' => [
                '@context' => 'https://schema.org', '@type' => 'TravelAgency', 'name' => $site, 'url' => $base,
                'logo' => $base.'/app/favicon.svg', 'email' => $settings->get('site.support_email'), 'telephone' => $settings->get('site.support_phone'),
            ],
        ];

        $tags = '<title>'.e($meta['title']).'</title>'
            .'<meta name="description" content="'.e(Str::limit($meta['description'], 158)).'">'
            .'<link rel="canonical" href="'.e($base.($path === '/' ? '' : $path)).'">'
            .'<meta property="og:site_name" content="'.e($site).'">'
            .'<meta property="og:type" content="website">'
            .'<meta property="og:title" content="'.e($meta['title']).'">'
            .'<meta property="og:description" content="'.e(Str::limit($meta['description'], 200)).'">'
            .'<meta property="og:url" content="'.e($base.$path).'">'
            .'<meta property="og:image" content="'.e(Media::absolute($meta['image'])).'">'
            .'<meta name="twitter:card" content="summary_large_image">'
            .(isset($meta['jsonld']) ? '<script type="application/ld+json">'.json_encode($meta['jsonld'], JSON_UNESCAPED_SLASHES | JSON_HEX_TAG).'</script>' : '');
        if (str_starts_with($path, '/account') || str_starts_with($path, '/admin') || str_starts_with($path, '/partner') || str_starts_with($path, '/checkout')) {
            $tags .= '<meta name="robots" content="noindex, nofollow">';
        }

        $html = preg_replace('#<title>.*?</title>#s', '', $html);
        $html = str_replace('</head>', $tags.'</head>', $html);

        return response($html, 200, ['Content-Type' => 'text/html; charset=UTF-8', 'Cache-Control' => 'no-cache']);
    }

    private function metaFor(string $path, string $site, string $base): ?array
    {
        if (preg_match('#^/hotels/([a-z0-9-]+)$#', $path, $m)) {
            return Cache::remember('seo.hotel.'.md5($base).'.'.$m[1], 600, function () use ($m, $site, $base) {
                $h = Hotel::query()->active()->where('slug', $m[1])->with('coverImage', 'destination')->first();

                return $h ? [
                    'title' => ($h->meta_title ?: "{$h->name}, {$h->city}")." | {$site}",
                    'description' => $h->meta_description ?: ($h->short_description ?: Str::limit(strip_tags((string) $h->description), 155)),
                    'image' => Media::absolute($h->cover_url) ?? $base.'/storage/seed/misc/hero-beach.jpg',
                    'jsonld' => array_filter([
                        '@context' => 'https://schema.org', '@type' => 'Hotel', 'name' => $h->name, 'url' => $base.'/hotels/'.$h->slug,
                        'image' => Media::absolute($h->cover_url), 'description' => $h->short_description, 'starRating' => ['@type' => 'Rating', 'ratingValue' => $h->star_rating],
                        'address' => ['@type' => 'PostalAddress', 'streetAddress' => $h->address, 'addressLocality' => $h->city, 'addressRegion' => $h->state, 'addressCountry' => $h->country, 'postalCode' => $h->postal_code],
                        'geo' => $h->latitude ? ['@type' => 'GeoCoordinates', 'latitude' => $h->latitude, 'longitude' => $h->longitude] : null,
                        'priceRange' => $h->min_price ? 'From ₹'.number_format($h->min_price).' per night' : null,
                        'aggregateRating' => $h->review_count ? ['@type' => 'AggregateRating', 'ratingValue' => $h->rating_avg, 'reviewCount' => $h->review_count, 'bestRating' => 5] : null,
                    ]),
                ] : null;
            });
        }
        if (preg_match('#^/destinations/([a-z0-9-]+)$#', $path, $m)) {
            $d = Destination::query()->active()->where('slug', $m[1])->first();

            return $d ? [
                'title' => ($d->meta_title ?: "Hotels & trips in {$d->name}")." | {$site}",
                'description' => $d->meta_description ?: Str::limit((string) $d->description, 155),
                'image' => Media::absolute($d->image_url),
                'jsonld' => ['@context' => 'https://schema.org', '@type' => 'TouristDestination', 'name' => $d->name, 'description' => $d->tagline, 'image' => Media::absolute($d->image_url)],
            ] : null;
        }
        if (preg_match('#^/tours/([a-z0-9-]+)$#', $path, $m)) {
            $t = TourPackage::query()->active()->where('slug', $m[1])->first();

            return $t ? [
                'title' => ($t->meta_title ?: "{$t->name} — {$t->duration_days} days")." | {$site}",
                'description' => $t->meta_description ?: $t->summary,
                'image' => Media::absolute($t->image_url),
                'jsonld' => ['@context' => 'https://schema.org', '@type' => 'TouristTrip', 'name' => $t->name, 'description' => $t->summary, 'image' => Media::absolute($t->image_url),
                    'offers' => ['@type' => 'Offer', 'price' => $t->price_from, 'priceCurrency' => 'INR']],
            ] : null;
        }
        $static = [
            '/hotels' => ['Search hotels', 'Compare hand-picked hotels, resorts and villas with live availability and member pricing.'],
            '/membership' => ['StayVoyage Monthly Membership — 30 hotel nights', 'One monthly membership, up to 30 nights at eligible StayVoyage hotels. See how it works.'],
            '/tour-builder' => ['Build your own trip', 'Create a day-by-day itinerary with hotels, transfers, activities and guides — priced live.'],
            '/tours' => ['Tour packages', 'Curated multi-day tours you can book as-is or customise in the Tour Builder.'],
            '/destinations' => ['Destinations', 'Explore India and international favourites — Dubai, Bali, Maldives, Goa, Jaipur and more.'],
            '/offers' => ['Offers & coupons', 'Current StayVoyage deals on hotels, tours and memberships.'],
            '/about' => ['About us', 'Meet StayVoyage — a membership-first travel platform built in Ahmedabad.'],
            '/contact' => ['Contact us', 'Talk to the StayVoyage travel team — we reply within one business day.'],
            '/faq' => ['Help & FAQ', 'Answers about bookings, membership nights, payments, cancellations and refunds.'],
        ];
        if (isset($static[$path])) {
            return ['title' => $static[$path][0].' | '.$site, 'description' => $static[$path][1], 'image' => $base.'/storage/seed/misc/hero-beach.jpg'];
        }

        return null;
    }
}
