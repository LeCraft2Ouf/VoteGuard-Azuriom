<?php

namespace Azuriom\Plugin\VoteGuard\Commands;

use Azuriom\Models\User;
use Azuriom\Plugin\Vote\Models\Site;
use Azuriom\Plugin\Vote\Models\Vote;
use Azuriom\Plugin\VoteGuard\Detector;
use Azuriom\Plugin\VoteGuard\Settings;
use Carbon\Carbon;
use Illuminate\Console\Command;

class DebugPlayerCommand extends Command
{
    protected $signature = 'voteguard:debug {name : Pseudo Azuriom}';

    protected $description = 'Affiche le diagnostic VoteGuard d’un joueur';

    public function handle(Detector $detector, Settings $settings): int
    {
        $name = (string) $this->argument('name');
        $user = User::whereRaw('LOWER(name) = ?', [strtolower($name)])->first();

        if ($user === null) {
            $this->error('Joueur introuvable.');

            return self::FAILURE;
        }

        $this->info("user={$user->id} {$user->name}");
        $this->line('min_votes='.$settings->minVotes().' stddev='.$settings->maxStddev().' sniper='.$settings->sniperSeconds().' watch='.$settings->watchScore());

        $sites = Vote::query()
            ->where('user_id', $user->id)
            ->where('created_at', '>=', now()->subDays(60))
            ->selectRaw('site_id, COUNT(*) as n')
            ->groupBy('site_id')
            ->get();

        foreach ($sites as $row) {
            $site = class_exists(Site::class) ? Site::find($row->site_id) : null;
            $this->line('site='.$row->site_id.' name='.($site->name ?? '?').' delay='.($site->vote_delay ?? '?').' reset='.var_export($site->vote_reset_at ?? null, true).' votes='.$row->n);
        }

        $score = $detector->analyzeUser($user->id);
        $suspect = \Azuriom\Plugin\VoteGuard\Models\Suspect::query()->where('user_id', $user->id)->first();

        $this->info('score='.$score);
        $this->info('flags='.json_encode($suspect->last_flags ?? []));
        $this->info('status='.($suspect->status ?? 'none'));

        $times = Vote::query()
            ->where('user_id', $user->id)
            ->where('site_id', $sites->first()->site_id ?? 0)
            ->where('created_at', '>=', now()->subDays(60))
            ->latest()
            ->limit(8)
            ->pluck('created_at')
            ->reverse()
            ->values();

        $prev = null;

        foreach ($times as $time) {
            $at = Carbon::parse($time);
            $gap = $prev ? (int) $at->diffInSeconds($prev, true) : null;
            $this->line('vote='.$at->toDateTimeString().' gap='.($gap ?? '—'));
            $prev = $at;
        }

        return self::SUCCESS;
    }
}
