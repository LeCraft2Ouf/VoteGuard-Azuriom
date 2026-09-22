<?php

namespace Azuriom\Plugin\VoteGuard;

use Azuriom\Plugin\Vote\Models\Site;
use Azuriom\Plugin\Vote\Models\Vote;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Cooldown réel par site : celui d'Azuriom, ou celui du listing s'il est plus long
 * (appris sur l'ensemble des joueurs : personne ne peut voter plus vite que le listing).
 */
class Cooldowns
{
    private const CACHE_KEY = 'voteguard.cooldowns';

    private const MIN_GAPS = 300;

    private const PERCENTILE = 0.02;

    private const MIN_RATIO = 1.3;

    /**
     * @var array<int, int>|null
     */
    private ?array $learned = null;

    public function for(?object $site): int
    {
        $configured = $this->configured($site);

        if ($site === null || $configured >= 6 * 3600) {
            return $configured;
        }

        return max($configured, $this->learned()[(int) $site->id] ?? 0);
    }

    public function configured(?object $site): int
    {
        $minutes = (int) ($site?->vote_delay ?? 90);

        if ($site !== null && filled($site->vote_reset_at) && $minutes <= 0) {
            return 86400;
        }

        return max(60, $minutes * 60);
    }

    /**
     * @return array<int, int>
     */
    public function learned(): array
    {
        if ($this->learned !== null) {
            return $this->learned;
        }

        try {
            $value = Cache::get(self::CACHE_KEY, []);
        } catch (Throwable) {
            $value = [];
        }

        return $this->learned = is_array($value) ? $value : [];
    }

    /**
     * @return array<int, int>
     */
    public function refresh(int $days = 60): array
    {
        if (! class_exists(Vote::class) || ! class_exists(Site::class)) {
            return [];
        }

        $since = now()->subDays($days);
        $learned = [];

        foreach (Site::query()->get() as $site) {
            $configured = $this->configured($site);

            if ($configured >= 6 * 3600) {
                continue;
            }

            $gaps = [];
            $prevUser = null;
            $prevAt = null;

            $rows = Vote::query()
                ->where('site_id', $site->id)
                ->where('created_at', '>=', $since)
                ->orderBy('user_id')
                ->orderBy('created_at')
                ->toBase()
                ->select(['user_id', 'created_at'])
                ->cursor();

            foreach ($rows as $row) {
                $at = strtotime((string) $row->created_at);

                if ($at === false) {
                    continue;
                }

                if ($prevUser === $row->user_id && $prevAt !== null && $at - $prevAt >= 60) {
                    $gaps[] = $at - $prevAt;
                }

                $prevUser = $row->user_id;
                $prevAt = $at;
            }

            if (count($gaps) < self::MIN_GAPS) {
                continue;
            }

            sort($gaps);
            $low = $gaps[(int) floor((count($gaps) - 1) * self::PERCENTILE)];

            if ($low >= $configured * self::MIN_RATIO) {
                $learned[(int) $site->id] = intdiv($low, 60) * 60;
            }
        }

        Cache::put(self::CACHE_KEY, $learned, now()->addDays(7));
        $this->learned = $learned;

        return $learned;
    }
}
