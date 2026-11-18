<?php

namespace App\Support;

use Illuminate\Support\Str;

class Media
{
    /**
     * Browser URL for a stored file (public disk) or an external URL.
     *
     * Returns a ROOT-RELATIVE path ("/storage/seed/hotels/x.jpg") so images load no matter which host/port the
     * app is served from (Apache vhost :8096, `php artisan serve` :8000, the Vite dev proxy :5173, a LAN IP…).
     * Absolute URLs built from APP_URL break whenever APP_URL doesn't match how the site is being opened.
     */
    public static function url(?string $path): ?string
    {
        if (! $path) {
            return null;
        }
        if (Str::startsWith($path, ['http://', 'https://', '//', 'data:'])) {
            return $path;
        }
        if (Str::startsWith($path, '/')) {
            return $path;
        }

        return '/storage/'.ltrim(str_replace('\\', '/', $path), '/');
    }

    /** Absolute URL (for Open Graph / JSON-LD / emails) based on the current request's host, falling back to APP_URL. */
    public static function absolute(?string $pathOrUrl): ?string
    {
        if (! $pathOrUrl) {
            return null;
        }
        $url = Str::startsWith($pathOrUrl, ['http://', 'https://']) ? $pathOrUrl : self::url($pathOrUrl);
        if (Str::startsWith($url, ['http://', 'https://'])) {
            return $url;
        }
        $base = app()->runningInConsole() || ! request()
            ? rtrim((string) config('app.url'), '/')
            : request()->getSchemeAndHttpHost();

        return $base.$url;
    }
}
