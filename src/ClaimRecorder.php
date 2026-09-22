<?php

namespace Azuriom\Plugin\VoteGuard;

use Azuriom\Models\User;
use Azuriom\Plugin\Vote\Models\Site;
use Azuriom\Plugin\VoteGuard\Models\Claim;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

class ClaimRecorder
{
    public function __construct(
        private VoteContext $context,
        private Honeypot $honeypot,
    ) {}

    public function record(Request $request, Response $response): void
    {
        $siteId = $this->siteId($request);
        $authenticated = $request->user() !== null;
        $user = $request->user() ?? $this->userByName($request->input('user'));
        $outcome = $this->outcome($response);
        $pendKey = 'voteguard.pend.'.($user?->id ?? 'ip'.md5((string) $this->context->ip)).'.'.($siteId ?? 0);

        if ($outcome === 'pending') {
            Cache::add($pendKey, 0, now()->addMinutes(15));
            Cache::increment($pendKey);

            return;
        }

        $asn = Networks::asn($request);

        Claim::create([
            'user_id' => $user?->id,
            'site_id' => $siteId,
            'vote_id' => $this->context->voteId,
            'outcome' => $outcome,
            'pendings' => min(65535, (int) Cache::pull($pendKey, 0)),
            'ip' => $this->context->ip,
            'asn' => $asn,
            'country' => Networks::country($request),
            'user_agent' => $this->context->userAgent !== null ? mb_substr($this->context->userAgent, 0, 255) : null,
            'authenticated' => $authenticated,
            'flags' => $this->flags($request, $siteId, $authenticated, $asn),
            'created_at' => now(),
        ]);
    }

    /**
     * @return list<string>
     */
    private function flags(Request $request, ?int $siteId, bool $authenticated, ?int $asn): array
    {
        $flags = [];

        if (! $this->context->hasSession()) {
            $flags[] = 'no_session';
        } elseif (! $this->context->hasClick($siteId)) {
            $flags[] = 'no_click';
        } else {
            $age = $this->context->clickAgeSeconds($siteId);

            if ($age !== null && $age < 4) {
                $flags[] = 'too_fast';
            }
        }

        if ($this->context->webdriver()) {
            $flags[] = 'webdriver';
        }

        if (BrowserCheck::botUserAgent($this->context->userAgent)) {
            $flags[] = 'bot_ua';
        }

        if (BrowserCheck::missingFetchMetadata($request)) {
            $flags[] = 'no_sec_fetch';
        }

        if (BrowserCheck::spoofedChrome($request)) {
            $flags[] = 'ua_spoof';
        }

        if (! $authenticated) {
            $flags[] = 'guest';
        }

        if (Networks::isHosting($asn)) {
            $flags[] = 'datacenter';
        }

        if ($siteId !== null && $siteId === $this->honeypot->siteId()) {
            $flags[] = 'honeypot';
        }

        return $flags;
    }

    private function outcome(Response $response): string
    {
        $status = $response->getStatusCode();
        $data = json_decode((string) $response->getContent(), true);
        $data = is_array($data) ? $data : [];

        if ($status === 200) {
            return match ($data['status'] ?? null) {
                'pending' => 'pending',
                'select_server' => 'select',
                default => $this->context->skipReward ? 'denied' : 'success',
            };
        }

        if ($status === 419) {
            return str_contains(strtolower((string) ($data['message'] ?? '')), 'csrf') ? 'csrf' : 'cooldown';
        }

        return match ($status) {
            401 => 'no_user',
            403 => 'forbidden',
            404 => 'not_found',
            422 => 'invalid',
            429 => 'throttled',
            default => 'error',
        };
    }

    private function siteId(Request $request): ?int
    {
        $site = $request->route('site');

        if ($site instanceof Site) {
            return (int) $site->id;
        }

        if (preg_match('#vote/site/(\d+)/done$#', trim($request->path(), '/'), $m) === 1) {
            return (int) $m[1];
        }

        return null;
    }

    private function userByName(mixed $name): ?User
    {
        if (! is_string($name) || trim($name) === '' || mb_strlen($name) > 50) {
            return null;
        }

        return User::query()->firstWhere('name', $name);
    }
}
