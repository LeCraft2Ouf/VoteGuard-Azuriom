<?php

namespace Azuriom\Plugin\VoteGuard;

use Azuriom\Models\User;
use Azuriom\Plugin\VoteGuard\Models\Suspect;
use Illuminate\Support\Facades\Cache;

class Blocklist
{
    private const CACHE_KEY = 'voteguard.blocked_ids';

    public function __construct(
        private Settings $settings,
    ) {}

    public function isBlocked(?User $user): bool
    {
        if ($user === null) {
            return false;
        }

        if ($this->blockedIds()->contains((int) $user->id)) {
            return true;
        }

        return $this->settings->isBlocklisted($user->name);
    }

    public function isUserIdBlocked(int $userId): bool
    {
        return $this->blockedIds()->contains($userId);
    }

    public function flush(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    /**
     * @return \Illuminate\Support\Collection<int, int>
     */
    private function blockedIds()
    {
        return Cache::remember(self::CACHE_KEY, 60, function () {
            return Suspect::query()
                ->where('blocked', true)
                ->pluck('user_id')
                ->map(fn ($id) => (int) $id)
                ->values();
        });
    }
}
