<?php

namespace Azuriom\Plugin\VoteGuard;

use Azuriom\Plugin\Vote\Models\Site;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Faux site de vote présent seulement dans le HTML brut (balise template, invisible et inerte
 * dans le navigateur). Seul un script qui parse la page peut le réclamer.
 */
class Honeypot
{
    private ?int $id = null;

    public function siteId(): int
    {
        if ($this->id !== null) {
            return $this->id;
        }

        try {
            return $this->id = (int) Cache::remember('voteguard.hp', now()->addDay(), function () {
                $id = 9000 + (crc32((string) config('app.key')) % 900);

                while (class_exists(Site::class) && Site::query()->whereKey($id)->exists()) {
                    $id++;
                }

                return $id;
            });
        } catch (Throwable) {
            return $this->id = 9000 + (crc32((string) config('app.key')) % 900);
        }
    }

    public function url(): string
    {
        return url('/vote/site/'.$this->siteId());
    }
}
