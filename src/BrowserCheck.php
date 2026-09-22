<?php

namespace Azuriom\Plugin\VoteGuard;

use Illuminate\Http\Request;

/**
 * Cohérence entre le navigateur annoncé (User-Agent) et les en-têtes qu'il envoie réellement.
 */
final class BrowserCheck
{
    public const BOT_UA = '/headlesschrome|puppeteer|playwright|selenium|webdriver|phantomjs|python-requests|python-urllib|aiohttp\/|httpx\/|curl\/|wget\/|go-http-client|okhttp|apache-httpclient|java\/|libwww-perl|scrapy|httpie|node-fetch|undici|axios\/\d|postmanruntime|insomnia|guzzlehttp/i';

    public static function botUserAgent(?string $ua): bool
    {
        $ua = (string) $ua;

        return $ua === '' || preg_match(self::BOT_UA, $ua) === 1;
    }

    /**
     * Les navigateurs modernes envoient toujours Sec-Fetch-* sur un appel fetch/XHR.
     * Les librairies HTTP (requests, Go, Node…) non, sauf ajout manuel.
     */
    public static function missingFetchMetadata(Request $request): bool
    {
        if ($request->headers->has('Sec-Fetch-Mode') || $request->headers->has('Sec-Fetch-Site')) {
            return false;
        }

        $ua = (string) $request->userAgent();

        if ($ua === '') {
            return true;
        }

        if (preg_match('/OS (\d+)_(\d+)(?:_\d+)? like Mac OS X/', $ua, $m) === 1) {
            return self::atLeast((int) $m[1], (int) $m[2], 16, 4);
        }

        if (! str_contains($ua, 'Chrome/') && preg_match('/Version\/(\d+)\.(\d+).*Safari\//', $ua, $m) === 1) {
            return self::atLeast((int) $m[1], (int) $m[2], 16, 4);
        }

        if (preg_match('/Firefox\/(\d+)/', $ua, $m) === 1) {
            return (int) $m[1] >= 90;
        }

        if (preg_match('/Chrome\/(\d+)/', $ua, $m) === 1) {
            return (int) $m[1] >= 80;
        }

        return false;
    }

    /**
     * Un Chromium récent envoie toujours sec-ch-ua en HTTPS. Un « Chrome » sans = User-Agent recopié.
     */
    public static function spoofedChrome(Request $request): bool
    {
        $ua = (string) $request->userAgent();

        if (preg_match('/Chrome\/(\d+)/', $ua, $m) !== 1 || (int) $m[1] < 90) {
            return false;
        }

        if (preg_match('/CriOS|EdgiOS|FxiOS|iPhone|iPad|; wv\)|SamsungBrowser|Firefox\//', $ua) === 1) {
            return false;
        }

        return ! $request->headers->has('sec-ch-ua');
    }

    private static function atLeast(int $major, int $minor, int $wantMajor, int $wantMinor): bool
    {
        return $major > $wantMajor || ($major === $wantMajor && $minor >= $wantMinor);
    }
}
