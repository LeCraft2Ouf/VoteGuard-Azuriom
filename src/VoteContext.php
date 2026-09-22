<?php

namespace Azuriom\Plugin\VoteGuard;

class VoteContext
{
    public bool $fromVoteDone = false;

    public ?string $ip = null;

    public ?string $userAgent = null;

    public ?string $acceptLanguage = null;

    public ?string $accept = null;

    public bool $skipReward = false;

    public ?int $voteId = null;

    public ?string $token = null;

    /**
     * @var array<string, mixed>|null
     */
    public ?array $session = null;

    public function hasSession(): bool
    {
        return is_array($this->session);
    }

    public function hasClick(?int $siteId): bool
    {
        if (! $this->hasSession() || $siteId === null) {
            return false;
        }

        $clicks = $this->session['clicks'] ?? [];

        return isset($clicks[(string) $siteId]) || isset($clicks[$siteId]);
    }

    public function clickAgeSeconds(?int $siteId): ?int
    {
        if (! $this->hasClick($siteId)) {
            return null;
        }

        $clicks = $this->session['clicks'] ?? [];
        $at = (int) ($clicks[(string) $siteId] ?? $clicks[$siteId] ?? 0);

        if ($at <= 0) {
            return null;
        }

        return max(0, time() - $at);
    }

    public function webdriver(): bool
    {
        return (bool) ($this->session['webdriver'] ?? false);
    }
}
