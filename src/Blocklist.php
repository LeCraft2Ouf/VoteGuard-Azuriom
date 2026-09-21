<?php

namespace Azuriom\Plugin\VoteGuard;

use Azuriom\Models\User;
use Azuriom\Plugin\VoteGuard\Models\Suspect;
use Illuminate\Support\Facades\Cache;
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
            $blocked = Suspect::query()
                ->where('user_id', $user->id)
                ->where('blocked', true)
                ->exists();

            if ($blocked) {
                return true;
            }

            return $this->settings->isBlocklisted($user->name);
        } catch (Throwable) {
            return false;
        }
    }

    public function flush(): void
    {
        Cache::forget(self::CACHE_KEY);
    }
}
