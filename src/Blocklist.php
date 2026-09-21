<?php

namespace Azuriom\Plugin\VoteGuard;

use Azuriom\Models\User;
use Azuriom\Plugin\VoteGuard\Models\Suspect;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Throwable;

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

        try {
            if ($this->blockedIds()->contains((int) $user->id)) {
                return true;
            }

            return $this->settings->isBlocklisted($user->name);
        } catch (Throwable) {
            return false;
        }
    }

    public function isUserIdBlocked(int $userId): bool
    {
        try {
            return $this->blockedIds()->contains($userId);
        } catch (Throwable) {
            return false;
        }
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
            if (! Schema::hasColumn('voteguard_suspects', 'blocked')) {
                return collect();
            }

            return Suspect::query()
                ->where('blocked', true)
                ->pluck('user_id')
                ->map(fn ($id) => (int) $id)
                ->values();
        });
    }
}
