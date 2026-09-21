<?php

namespace Azuriom\Plugin\VoteGuard\Commands;

use Azuriom\Models\User;
use Azuriom\Plugin\Vote\Models\Site;
use Azuriom\Plugin\Vote\Models\Vote;
use Azuriom\Plugin\VoteGuard\Detector;
use Azuriom\Plugin\VoteGuard\Models\Suspect;
use Azuriom\Plugin\VoteGuard\Settings;
use Carbon\Carbon;
use Illuminate\Console\Command;

class StatsCommand extends Command
{
    protected $signature = 'voteguard:stats {--days=60 : Fenêtre en jours}';

    protected $description = 'Dump des écarts de vote pour régler VoteGuard (n’écrit rien)';

    public function handle(Detector $detector, Settings $settings): int
    {
        if (! class_exists(Vote::class)) {
            $this->error('Plugin Vote introuvable.');

            return self::FAILURE;
        }

        $days = max(1, (int) $this->option('days'));
        $from = now()->subDays($days)->startOfDay();
        $to = now()->endOfDay();
        $min = $settings->minVotes();

        $this->info("fenetre={$from->toDateString()} -> {$to->toDateString()} min_votes={$min} sniper={$settings->sniperSeconds()}s");

        $sites = class_exists(Site::class)
            ? Site::query()->get()->keyBy('id')
            : collect();

        $this->table(['site_id', 'name', 'delay_min'], $sites->map(fn ($site) => [
            $site->id,
            $site->name,
            $site->vote_delay,
        ])->all());

        $kinds = ['early' => 0, 'sniper' => 0, 'tight' => 0, 'classic' => 0, 'sleep' => 0];
        $deltas = [
            '<0' => 0,
            '0-30s' => 0,
            '30-180s' => 0,
            '3-8m' => 0,
            '8-30m' => 0,
            '30-90m' => 0,
            '90m-6h' => 0,
            '6h+' => 0,
        ];
        $users = [];
        $prev = [];
        $votes = 0;

        $query = Vote::query()
            ->whereBetween('created_at', [$from, $to])
            ->orderBy('user_id')
            ->orderBy('site_id')
            ->orderBy('created_at')
            ->select(['user_id', 'site_id', 'created_at']);

        foreach ($query->cursor() as $vote) {
            $votes++;
            $siteId = (int) $vote->site_id;
            $userId = (int) $vote->user_id;
            $key = $userId.':'.$siteId;
            $expected = $detector->siteDelay($sites->get($siteId));

            if (isset($prev[$key])) {
                $gap = (int) Carbon::parse($vote->created_at)->diffInSeconds($prev[$key], true);
                $kind = $detector->classifyGap($gap, $expected);
                $kinds[$kind] = ($kinds[$kind] ?? 0) + 1;
                $deltas[$this->deltaBucket($gap - $expected)]++;

                $row = $users[$userId] ?? [
                    'snipers' => 0,
                    'tight' => 0,
                    'classic' => 0,
                    'sleeps' => 0,
                    'early' => 0,
                    'total' => 0,
                    'sum' => 0,
                    'awake' => 0,
                    'votes' => 0,
                ];
                $row['votes']++;
                $row['total']++;

                if ($kind === 'sniper') {
                    $row['snipers']++;
                    $row['sum'] += 100;
                    $row['awake']++;
                } elseif ($kind === 'tight') {
                    $row['tight']++;
                    $row['sum'] += 75;
                    $row['awake']++;
                } elseif ($kind === 'classic') {
                    $row['classic']++;
                    $row['awake']++;
                } elseif ($kind === 'sleep') {
                    $row['sleeps']++;
                } else {
                    $row['early']++;
                }

                $users[$userId] = $row;
            } else {
                $users[$userId] = $users[$userId] ?? [
                    'snipers' => 0,
                    'tight' => 0,
                    'classic' => 0,
                    'sleeps' => 0,
                    'early' => 0,
                    'total' => 0,
                    'sum' => 0,
                    'awake' => 0,
                    'votes' => 0,
                ];
                $users[$userId]['votes']++;
            }

            $prev[$key] = Carbon::parse($vote->created_at);
        }

        $kindTotal = max(1, array_sum($kinds));
        $this->info("votes={$votes} joueurs=".count($users));
        $this->table(['type', 'n', '%'], $this->pctRows($kinds, $kindTotal));
        $this->table(['delta_vs_cd', 'n', '%'], $this->pctRows($deltas, max(1, array_sum($deltas))));

        $bands = ['0-24' => 0, '25-49' => 0, '50-79' => 0, '80-100' => 0, 'ignore' => 0];
        $ranked = [];
        $ratios = [];

        foreach ($users as $userId => $row) {
            if ($row['awake'] < $min - 1) {
                $bands['ignore']++;

                continue;
            }

            $score = (int) round($row['sum'] / $row['awake']);
            $sleepRatio = $row['sleeps'] / max(1, $row['total']);
            $sniperRatio = $row['snipers'] / $row['awake'];
            $botRatio = ($row['snipers'] + $row['tight']) / $row['awake'];
            $flags = [];

            if ($row['snipers'] >= $min - 1 && $sniperRatio >= 0.40) {
                $flags[] = 'cooldown_sniper';
            }

            if (($row['snipers'] + $row['tight']) >= $min - 1 && $botRatio >= 0.50) {
                $flags[] = 'regular_interval';
            }

            if ($row['total'] >= 12 && $sleepRatio <= 0.10 && $score >= 40) {
                $flags[] = 'always_on';
                $score = min(100, $score + 10);
            }

            $ratios[] = round($sniperRatio * 100);
            $band = $score >= 80 ? '80-100' : ($score >= 50 ? '50-79' : ($score >= 25 ? '25-49' : '0-24'));
            $bands[$band]++;
            $ranked[] = [
                'id' => $userId,
                'score' => $score,
                'snipers' => $row['snipers'],
                'tight' => $row['tight'],
                'classic' => $row['classic'],
                'sleeps' => $row['sleeps'],
                'awake' => $row['awake'],
                'votes' => $row['votes'],
                'flags' => implode(',', $flags),
            ];
        }

        $this->table(['score', 'joueurs'], collect($bands)->map(fn ($n, $k) => [$k, $n])->values()->all());

        sort($ratios);
        $nRatios = count($ratios);
        if ($nRatios > 0) {
            $this->info('sniper_ratio% p50='.$ratios[(int) floor(($nRatios - 1) * 0.50)].
                ' p75='.$ratios[(int) floor(($nRatios - 1) * 0.75)].
                ' p90='.$ratios[(int) floor(($nRatios - 1) * 0.90)].
                ' p99='.$ratios[(int) floor(($nRatios - 1) * 0.99)]);
        }

        usort($ranked, fn ($a, $b) => $b['score'] <=> $a['score'] ?: $b['votes'] <=> $a['votes']);
        $top = array_slice($ranked, 0, 25);
        $names = User::query()->whereIn('id', array_column($top, 'id'))->pluck('name', 'id');
        $stored = Suspect::query()->whereIn('user_id', array_column($top, 'id'))->get()->keyBy('user_id');

        $this->info('top 25');
        $this->table(
            ['name', 'id', 'score', 'votes', 'sniper', 'tight', 'classic', 'sleep', 'flags', 'bdd_score', 'bdd_status'],
            array_map(function (array $row) use ($names, $stored) {
                $suspect = $stored->get($row['id']);

                return [
                    $names[$row['id']] ?? '?',
                    $row['id'],
                    $row['score'],
                    $row['votes'],
                    $row['snipers'],
                    $row['tight'],
                    $row['classic'],
                    $row['sleeps'],
                    $row['flags'] ?: '—',
                    $suspect->score ?? '—',
                    $suspect->status ?? '—',
                ];
            }, $top)
        );

        $this->table(
            ['bdd_status', 'n', 'score_moy'],
            Suspect::query()
                ->selectRaw('status, COUNT(*) as n, ROUND(AVG(score)) as avg_score')
                ->groupBy('status')
                ->get()
                ->map(fn ($row) => [$row->status, $row->n, $row->avg_score])
                ->all()
        );

        return self::SUCCESS;
    }

    private function deltaBucket(int $delta): string
    {
        if ($delta < 0) {
            return '<0';
        }

        if ($delta <= 30) {
            return '0-30s';
        }

        if ($delta <= 180) {
            return '30-180s';
        }

        if ($delta <= 480) {
            return '3-8m';
        }

        if ($delta <= 1800) {
            return '8-30m';
        }

        if ($delta <= 5400) {
            return '30-90m';
        }

        if ($delta <= 21600) {
            return '90m-6h';
        }

        return '6h+';
    }

    /**
     * @param  array<string, int>  $rows
     * @return list<array{0: string, 1: int, 2: string}>
     */
    private function pctRows(array $rows, int $total): array
    {
        $out = [];

        foreach ($rows as $key => $count) {
            $out[] = [$key, $count, round($count / $total * 100, 1).'%'];
        }

        return $out;
    }
}
