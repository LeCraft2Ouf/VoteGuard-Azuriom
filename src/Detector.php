<?php

namespace Azuriom\Plugin\VoteGuard;

use Azuriom\Models\User;
use Azuriom\Plugin\Vote\Models\Site;
use Azuriom\Plugin\Vote\Models\Vote;
use Azuriom\Plugin\VoteGuard\Models\Claim;
use Azuriom\Plugin\VoteGuard\Models\Detection;
use Azuriom\Plugin\VoteGuard\Models\Suspect;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Throwable;

class Detector
{
    /**
     * @var array<string, int>
     */
    private const LIVE_WEIGHTS = [
        'bot_ua' => 40,
        'webdriver' => 35,
    ];

    private const SNIPER_POINTS = 100;

    private const TIGHT_POINTS = 40;

    private const NEAR_POINTS = 25;

    private const NEAR_SECONDS = 1500;

    private const CONFIDENCE_AWAKE = 24;

    private const MIN_FLAG_AWAKE = 12;

    private const FILL_WINDOW = 14 * 86400;

    private const FILL_MIN_SPAN = 7 * 86400;

    private const FILL_MIN_VOTES = 12;

    private const FILL_MAX_COOLDOWN = 6 * 3600;

    private const CLAIM_DAYS = 14;

    private const CLAIM_MIN = 6;

    private const CLAIM_RATIO = 0.8;

    /**
     * Au-delà, le signal touche la majorité des joueurs : c'est l'infra (proxy, thème) qui le casse, pas des bots.
     */
    private const GLOBAL_BREAKER = 0.6;

    public function __construct(
        private Settings $settings,
        private VoteContext $context,
        private Cooldowns $cooldowns,
    ) {}

    public function handleLive(Vote $vote): void
    {
        if (! $this->settings->enabled()) {
            return;
        }

        $user = $vote->user;

        if ($user === null || $this->settings->isWhitelisted($user->name)) {
            return;
        }

        $analysis = $this->analyze($user->id);
        $live = $this->liveFlags();
        $flags = array_values(array_unique(array_merge($analysis['flags'], $live)));
        $score = min(100, $analysis['score'] + $this->weight($live));

        if ($score < $this->settings->watchScore()) {
            return;
        }

        Detection::create([
            'user_id' => $user->id,
            'vote_id' => $vote->id,
            'site_id' => $vote->site_id,
            'ip' => $this->context->ip,
            'user_agent' => $this->truncate($this->context->userAgent, 512),
            'score' => $score,
            'flags' => $flags,
            'source' => 'live',
        ]);

        $this->upsertSuspect($user, $score, $flags, 1);
        $this->notify($user, $score, $flags);
    }

    public function analyzeUser(int $userId, ?Carbon $from = null, ?Carbon $to = null): int
    {
        $user = User::find($userId);

        if ($user === null) {
            $orphan = Suspect::query()->firstWhere('user_id', $userId);

            if ($orphan !== null && ! $orphan->isLocked()) {
                $orphan->score = 0;
                $orphan->last_flags = [];
                $orphan->status = 'clear';
                $orphan->save();
            }

            return 0;
        }

        if ($this->settings->isWhitelisted($user->name)) {
            $this->downgradeExisting($user, 0, []);

            return 0;
        }

        [$from, $to] = $this->period($from, $to);

        $analysis = $this->analyze($userId, $from, $to);
        $score = $analysis['score'];
        $flags = $analysis['flags'];

        if ($score < $this->settings->watchScore()) {
            $this->downgradeExisting($user, $score, $flags);

            return $score;
        }

        $this->upsertSuspect($user, $score, $flags, 0);

        return $score;
    }

    /**
     * @return array{score: int, flags: list<string>, pattern: array<string, mixed>, claims: array<string, mixed>}
     */
    public function analyze(int $userId, ?Carbon $from = null, ?Carbon $to = null): array
    {
        $pattern = $this->patternAnalysis($userId, $from, $to);
        $claims = $this->claimAnalysis($userId);
        $flags = array_values(array_unique(array_merge($pattern['flags'], $claims['flags'])));
        $score = min(100, max($pattern['score'], $claims['floor']) + $claims['bonus']);
        $score = $this->capLowFill($score, $pattern['fill'] ?? null, $flags);

        if ($this->blockRecommended($pattern, $claims)) {
            $flags[] = 'recommend_block';
            $score = max($score, $this->settings->likelyScore());
        }

        return [
            'score' => $score,
            'flags' => $flags,
            'pattern' => $pattern,
            'claims' => $claims,
        ];
    }

    /**
     * Voter pile au cooldown sans volume peut être un joueur qui guette le compte à rebours :
     * revue manuelle, sauf preuve technique de script.
     *
     * @param  list<string>  $flags
     */
    public function capLowFill(int $score, ?int $fill, array $flags): int
    {
        $technical = array_intersect(['not_browser', 'honeypot', 'synced_account'], $flags) !== []
            || (in_array('ua_spoof', $flags, true) && in_array('no_session', $flags, true));

        if ($fill === null || $fill >= 40 || $technical) {
            return $score;
        }

        return min($score, max($this->settings->suspectScore(), $this->settings->likelyScore() - 5));
    }

    /**
     * @return array{scanned: int, flagged: int, offset: int, total: int, done: bool}
     */
    public function scanRecent(int $days = 60, int $limit = 400, int $offset = 0, ?int $chunk = null, ?Carbon $from = null, ?Carbon $to = null): array
    {
        $empty = ['scanned' => 0, 'flagged' => 0, 'offset' => 0, 'total' => 0, 'done' => true];

        if (! class_exists(Vote::class)) {
            return $empty;
        }

        if ($offset === 0) {
            try {
                $this->cooldowns->refresh();
            } catch (Throwable) {
                //
            }
        }

        [$from, $to] = $this->period($from ?? now()->subDays($days), $to ?? now());

        $query = Vote::query()
            ->select('user_id', DB::raw('COUNT(*) as vote_count'))
            ->whereBetween('created_at', [$from, $to])
            ->groupBy('user_id')
            ->havingRaw('COUNT(*) >= ?', [$this->settings->minVotes()])
            ->orderByDesc('vote_count');

        if ($limit > 0) {
            $query->limit($limit);
        }

        $userIds = $query->pluck('user_id')->values();

        $extraIds = Suspect::query()
            ->whereNotIn('status', ['confirmed', 'false_positive'])
            ->whereNotIn('user_id', $userIds)
            ->pluck('user_id')
            ->concat($this->trapUserIds())
            ->unique()
            ->reject(fn ($id) => $userIds->contains($id));

        $userIds = $userIds->concat($extraIds)->values();
        $total = $userIds->count();
        $slice = $chunk === null
            ? $userIds->slice($offset)
            : $userIds->slice($offset, $chunk);

        $flagged = 0;

        foreach ($slice as $userId) {
            if ($this->analyzeUser((int) $userId, $from, $to) >= $this->settings->watchScore()) {
                $flagged++;
            }
        }

        $processed = $offset + $slice->count();

        return [
            'scanned' => $slice->count(),
            'flagged' => $flagged,
            'offset' => $processed,
            'total' => $total,
            'done' => $processed >= $total,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function intervalRows(int $userId, int $take = 25): array
    {
        if (! class_exists(Vote::class)) {
            return [];
        }

        $votes = Vote::query()
            ->with('site')
            ->where('user_id', $userId)
            ->latest()
            ->limit($take)
            ->get()
            ->reverse()
            ->values();

        $lastBySite = [];
        $rows = [];

        foreach ($votes as $vote) {
            $siteId = (int) $vote->site_id;
            $expected = $this->cooldowns->for($vote->site);
            $gap = null;
            $kind = 'first';

            if (isset($lastBySite[$siteId])) {
                $gap = (int) $vote->created_at->diffInSeconds($lastBySite[$siteId], true);
                $kind = $this->gapKind($gap, $expected);
            }

            $rows[] = [
                'site' => $vote->site->name ?? '#'.$siteId,
                'at' => $vote->created_at,
                'at_label' => $vote->created_at->format('d/m/Y H:i:s'),
                'gap' => $gap,
                'gap_label' => $gap === null ? '—' : $this->formatDuration($gap),
                'expected' => $expected,
                'expected_label' => $this->formatDuration($expected),
                'delta' => $gap === null ? null : $gap - $expected,
                'delta_label' => $gap === null
                    ? '—'
                    : (($gap - $expected >= 0 ? '+' : '−').$this->formatDuration(abs($gap - $expected))),
                'sniper' => $kind === 'sniper',
                'kind' => $kind,
            ];

            $lastBySite[$siteId] = $vote->created_at;
        }

        return array_reverse($rows);
    }

    /**
     * Score = moyenne des votes éveillés, pondérée par le volume.
     * Sniper = 100, limite (+3–8 min) = 40, aléa (+8–25 min) = 25, classique = 0.
     *
     * @return array<string, mixed>
     */
    public function patternAnalysis(int $userId, ?Carbon $from = null, ?Carbon $to = null): array
    {
        $zero = [
            'score' => 0,
            'flags' => [],
            'snipers' => 0,
            'tight' => 0,
            'nears' => 0,
            'classic' => 0,
            'sleeps' => 0,
            'awake' => 0,
            'total' => 0,
            'fill' => null,
            'fill_site' => null,
            'recent_fill' => null,
            'deep_night' => 0,
            'offsets' => [],
        ];

        if (! class_exists(Vote::class)) {
            return $zero;
        }

        [$from, $to] = $this->period($from, $to);
        $timezone = (string) config('app.timezone', 'Europe/Paris');

        $siteIds = Vote::query()
            ->where('user_id', $userId)
            ->whereBetween('created_at', [$from, $to])
            ->distinct()
            ->pluck('site_id');

        $merged = $zero;
        $suspicions = [];
        $nightVotes = 0;
        $deepNight = 0;
        $rawVotes = 0;
        $bestFill = null;
        $bestRecent = null;

        foreach ($siteIds as $siteId) {
            $stamps = [];

            $votes = Vote::query()
                ->where('user_id', $userId)
                ->where('site_id', (int) $siteId)
                ->whereBetween('created_at', [$from, $to])
                ->orderBy('created_at')
                ->pluck('created_at');

            foreach ($votes as $at) {
                $date = Carbon::parse($at);
                $stamps[] = $date->getTimestamp();
                $rawVotes++;
                $hour = $date->copy()->timezone($timezone)->hour;

                if ($hour < 6) {
                    $nightVotes++;

                    if ($hour >= 3) {
                        $deepNight++;
                    }
                }
            }

            $site = class_exists(Site::class) ? Site::find($siteId) : null;
            $expected = $this->cooldowns->for($site);
            $part = $this->classifyStamps($stamps, $expected);
            $fill = $this->fillRate($stamps, $expected);
            $recent = $this->recentFillRate($stamps, $expected);

            if ($recent !== null && ($bestRecent === null || $recent > $bestRecent)) {
                $bestRecent = $recent;
            }

            if ($fill !== null && ($bestFill === null || $fill > $bestFill)) {
                $bestFill = $fill;
                $merged['fill_site'] = $site->name ?? '#'.$siteId;
            }

            $suspicions = array_merge($suspicions, $part['suspicions']);
            $merged['snipers'] += $part['snipers'];
            $merged['tight'] += $part['tight'];
            $merged['nears'] += $part['nears'];
            $merged['classic'] += $part['classic'];
            $merged['sleeps'] += $part['sleeps'];
            $merged['total'] += $part['total'];
            $merged['offsets'] = array_merge($merged['offsets'], $part['offsets']);
        }

        $merged['awake'] = count($suspicions);
        $merged['fill'] = $bestFill !== null ? (int) round($bestFill * 100) : null;
        $merged['recent_fill'] = $bestRecent !== null ? (int) round($bestRecent * 100) : null;
        $merged['deep_night'] = $deepNight;

        $scored = $this->scoreMix($merged + [
            'sum' => array_sum($suspicions),
            'night_votes' => $nightVotes,
            'raw_votes' => $rawVotes,
        ]);

        $merged['score'] = $scored['score'];
        $merged['flags'] = $scored['flags'];
        unset($merged['offsets']);

        return $merged;
    }

    /**
     * @param  array<string, mixed>  $mix
     * @return array{score: int, flags: list<string>}
     */
    public function scoreMix(array $mix): array
    {
        $awake = (int) ($mix['awake'] ?? 0);
        $min = $this->settings->minVotes();
        $fill = isset($mix['fill']) ? (int) $mix['fill'] : null;
        $score = 0;
        $flags = [];

        if ($awake >= $min) {
            $snipers = (int) ($mix['snipers'] ?? 0);
            $tight = (int) ($mix['tight'] ?? 0);
            $nears = (int) ($mix['nears'] ?? 0);
            $sleeps = (int) ($mix['sleeps'] ?? 0);
            $total = max(1, (int) ($mix['total'] ?? 0));
            $sum = (int) ($mix['sum'] ?? (
                $snipers * self::SNIPER_POINTS
                + $tight * self::TIGHT_POINTS
                + $nears * self::NEAR_POINTS
            ));
            $score = (int) round(($sum / $awake) * min(1.0, $awake / self::CONFIDENCE_AWAKE));
            $sniperRatio = $snipers / $awake;
            $botRatio = ($snipers + $tight) / $awake;
            $scheduledRatio = ($snipers + $tight + $nears) / $awake;
            $sleepRatio = $sleeps / $total;
            $recent = isset($mix['recent_fill']) ? (int) $mix['recent_fill'] : null;
            $deepNight = (int) ($mix['deep_night'] ?? 0);
            $busy = ($fill !== null && $fill >= 50) || ($recent !== null && $recent >= 85);

            if ($awake >= self::MIN_FLAG_AWAKE && $snipers >= $min && $sniperRatio >= 0.40) {
                $flags[] = 'cooldown_sniper';
            }

            if ($awake >= self::MIN_FLAG_AWAKE && $sniperRatio >= 0.35 && $botRatio >= 0.50) {
                $flags[] = 'regular_interval';
            }

            if ($busy && $this->isJitterClock($mix['offsets'] ?? [], $min)) {
                $flags[] = 'regular_interval';
                $score = max($score, 45);
            }

            if ($busy && $awake >= self::MIN_FLAG_AWAKE && $scheduledRatio >= 0.60) {
                $flags[] = 'scheduled_vote';
                $score = max($score, $scheduledRatio >= 0.75 && $awake >= 16 ? 50 : 40);
            }

            $rawVotes = (int) ($mix['raw_votes'] ?? 0);
            $nightVotes = (int) ($mix['night_votes'] ?? 0);

            if ($busy && $rawVotes >= 24 && $nightVotes >= 8 && $nightVotes / $rawVotes >= 0.16) {
                $flags[] = 'night_vote';
                $score = min(100, $score + 5);
            }

            if ($total >= 20 && $sleepRatio <= 0.15 && $deepNight >= 4 && ($sniperRatio >= 0.40 || ($busy && $scheduledRatio >= 0.50))) {
                $flags[] = 'always_on';
                $score = min(100, $score + 10);
            }
        }

        if ($fill !== null && $fill >= 60) {
            $flags[] = 'high_fill';
            $timed = in_array('scheduled_vote', $flags, true) || in_array('cooldown_sniper', $flags, true);
            $score = max($score, match (true) {
                $fill >= 80 => 90,
                $fill >= 70 => $timed ? 80 : 65,
                default => $timed ? 60 : 40,
            });
        }

        return ['score' => min(100, $score), 'flags' => array_values(array_unique($flags))];
    }

    /**
     * Part des créneaux de vote utilisés sur le site, sur les 14 derniers jours d'activité.
     *
     * @param  list<int>  $stamps
     */
    public function fillRate(array $stamps, int $expected): ?float
    {
        $count = count($stamps);

        if ($count < self::FILL_MIN_VOTES || $expected <= 0 || $expected > self::FILL_MAX_COOLDOWN) {
            return null;
        }

        sort($stamps);
        $end = $stamps[$count - 1];
        $start = max($stamps[0], $end - self::FILL_WINDOW);
        $span = $end - $start;

        if ($span < self::FILL_MIN_SPAN) {
            return null;
        }

        $inWindow = 0;

        foreach ($stamps as $stamp) {
            if ($stamp >= $start) {
                $inWindow++;
            }
        }

        if ($inWindow < self::FILL_MIN_VOTES) {
            return null;
        }

        return min(1.0, $inWindow / (intdiv($span, $expected) + 1));
    }

    /**
     * Cadence sur les 36 dernières heures d'activité. Un bot qui vient de démarrer
     * a un remplissage 14 jours bas, mais sature déjà les créneaux récents.
     *
     * @param  list<int>  $stamps
     */
    public function recentFillRate(array $stamps, int $expected): ?float
    {
        if (count($stamps) < 16 || $expected <= 0 || $expected > self::FILL_MAX_COOLDOWN) {
            return null;
        }

        sort($stamps);
        $end = $stamps[count($stamps) - 1];
        $recent = [];

        foreach ($stamps as $stamp) {
            if ($stamp >= $end - 36 * 3600) {
                $recent[] = $stamp;
            }
        }

        if (count($recent) < 16) {
            return null;
        }

        $span = $recent[count($recent) - 1] - $recent[0];

        if ($span < 20 * 3600) {
            return null;
        }

        return min(1.0, count($recent) / (intdiv($span, $expected) + 1));
    }

    /**
     * Quasi certain : script sans page, horloge pile cooldown, remplissage max avec votes à 3h-6h,
     * ou second compte qui vote dans la même minute sur la même ligne.
     *
     * @param  array<string, mixed>  $pattern
     * @param  array<string, mixed>  $claims
     */
    public function blockRecommended(array $pattern, array $claims): bool
    {
        if ((int) ($claims['honeypot'] ?? 0) > 0) {
            return true;
        }

        $ratios = $claims['ratios'] ?? [];
        $n = (int) ($claims['n'] ?? 0);

        if ($n >= 10 && (float) ($ratios['no_session'] ?? 0) >= 0.85 && (float) ($ratios['ua_spoof'] ?? 0) >= 0.85) {
            return true;
        }

        if ($n >= 10 && (float) ($ratios['not_browser'] ?? 0) >= 0.85) {
            return true;
        }

        $awake = (int) ($pattern['awake'] ?? 0);
        $snipers = (int) ($pattern['snipers'] ?? 0);
        $tight = (int) ($pattern['tight'] ?? 0);
        $fill = isset($pattern['fill']) ? (int) $pattern['fill'] : null;
        $deep = (int) ($pattern['deep_night'] ?? 0);

        if ($awake >= 60 && $snipers >= 40 && $snipers / $awake >= 0.55) {
            return true;
        }

        if ($fill !== null && $fill >= 88 && $awake >= 16 && $deep >= 4 && ($snipers + $tight) / max(1, $awake) >= 0.45) {
            return true;
        }

        return ! empty($claims['synced'])
            && $awake >= 12
            && $snipers / max(1, $awake) >= 0.35;
    }

    /**
     * @return array<string, mixed>
     */
    public function claimAnalysis(int $userId): array
    {
        $out = [
            'n' => 0,
            'flags' => [],
            'floor' => 0,
            'bonus' => 0,
            'ratios' => [],
            'ips' => 0,
            'farm' => 0,
            'honeypot' => 0,
            'synced' => false,
        ];

        try {
            $rows = Claim::query()
                ->where('user_id', $userId)
                ->where('created_at', '>=', now()->subDays(self::CLAIM_DAYS))
                ->get(['outcome', 'flags', 'ip']);
        } catch (Throwable) {
            return $out;
        }

        $has = fn (Claim $claim, string $flag) => in_array($flag, $claim->flags ?? [], true);
        $rewarded = $rows->filter(fn (Claim $claim) => in_array($claim->outcome, Claim::REWARDED, true))->values();
        $withSession = $rewarded->reject(fn (Claim $claim) => $has($claim, 'no_session'));
        $n = $rewarded->count();
        $ratio = fn (int $count, int $of) => $of > 0 ? round($count / $of, 2) : 0.0;
        $ips = $rewarded->pluck('ip')->filter()->unique()->values();

        $out['n'] = $n;
        $out['ips'] = $ips->count();
        $out['honeypot'] = $rows->filter(fn (Claim $claim) => $has($claim, 'honeypot'))->count();
        $out['ratios'] = [
            'no_session' => $ratio($rewarded->filter(fn ($c) => $has($c, 'no_session'))->count(), $n),
            'no_click' => $ratio($withSession->filter(fn ($c) => $has($c, 'no_click'))->count(), $withSession->count()),
            'not_browser' => $ratio($rewarded->filter(fn ($c) => $has($c, 'no_sec_fetch') || $has($c, 'bot_ua'))->count(), $n),
            'ua_spoof' => $ratio($rewarded->filter(fn ($c) => $has($c, 'ua_spoof'))->count(), $n),
            'guest' => $ratio($rewarded->filter(fn ($c) => $has($c, 'guest'))->count(), $n),
            'hosting' => $ratio($rewarded->filter(fn ($c) => $has($c, 'datacenter'))->count(), $n),
        ];

        $r = $out['ratios'];

        if ($out['honeypot'] > 0) {
            $out['flags'][] = 'honeypot';
            $out['floor'] = 100;
        }

        if ($n >= self::CLAIM_MIN) {
            if ($r['not_browser'] >= self::CLAIM_RATIO && $this->signalHealthy('no_sec_fetch')) {
                $out['flags'][] = 'not_browser';
                $out['floor'] = max($out['floor'], $n >= 10 ? 85 : 60);
            }

            if ($r['ua_spoof'] >= self::CLAIM_RATIO && $this->signalHealthy('ua_spoof')) {
                $out['flags'][] = 'ua_spoof';
                $out['bonus'] += 20;
            }

            if ($r['no_session'] >= self::CLAIM_RATIO && $this->signalHealthy('no_session')) {
                $out['flags'][] = 'no_session';
                $out['bonus'] += 30;
            } elseif ($withSession->count() >= self::CLAIM_MIN && $r['no_click'] >= self::CLAIM_RATIO && $this->signalHealthy('no_click')) {
                $out['flags'][] = 'no_click';
                $out['bonus'] += 10;
            }

            if ($r['hosting'] >= self::CLAIM_RATIO) {
                $out['flags'][] = 'datacenter_ip';
                $out['bonus'] += 15;
            }

            if ($n >= 10 && $r['ua_spoof'] >= 0.85 && $r['no_session'] >= 0.85) {
                $out['floor'] = max($out['floor'], 90);
            }
        }

        if ($ips->isNotEmpty()) {
            $partners = $this->linePartners($userId, $ips->take(50)->all());
            $out['farm'] = count($partners);

            if ($out['farm'] + 1 >= $this->settings->ipFarmUsers()) {
                $out['flags'][] = 'ip_farm';
                $out['bonus'] += 20;
            }

            if ($this->votesInLockstep($userId, $partners)) {
                $out['synced'] = true;
                $out['flags'][] = 'synced_account';
                $out['bonus'] += 25;
            }
        }

        return $out;
    }

    /**
     * @return array<string, float|int>
     */
    public function globalRatios(): array
    {
        try {
            return Cache::remember('voteguard.claims.global', now()->addHour(), function () {
                $rows = Claim::query()
                    ->where('created_at', '>=', now()->subDay())
                    ->whereIn('outcome', Claim::REWARDED)
                    ->latest('id')
                    ->limit(5000)
                    ->pluck('flags');

                $n = $rows->count();
                $out = ['n' => $n];

                foreach (['no_session', 'no_click', 'no_sec_fetch', 'ua_spoof'] as $flag) {
                    $hits = $rows->filter(fn ($flags) => in_array($flag, is_array($flags) ? $flags : [], true))->count();
                    $out[$flag] = $n > 0 ? round($hits / $n, 2) : 0.0;
                }

                return $out;
            });
        } catch (Throwable) {
            return ['n' => 0];
        }
    }

    public function classifyGap(int $gap, int $expected): string
    {
        return $this->gapKind($gap, $expected);
    }

    public function siteDelay(?object $site): int
    {
        return $this->cooldowns->for($site);
    }

    /**
     * @param  list<int>  $stamps
     * @return array{suspicions: list<int>, snipers: int, tight: int, nears: int, classic: int, sleeps: int, total: int, offsets: list<int>}
     */
    private function classifyStamps(array $stamps, int $expected): array
    {
        $out = [
            'suspicions' => [],
            'snipers' => 0,
            'tight' => 0,
            'nears' => 0,
            'classic' => 0,
            'sleeps' => 0,
            'total' => 0,
            'offsets' => [],
        ];

        for ($i = 1, $len = count($stamps); $i < $len; $i++) {
            $gap = abs($stamps[$i] - $stamps[$i - 1]);
            $out['total']++;
            $kind = $this->gapKind($gap, $expected);

            if ($kind === 'early') {
                continue;
            }

            if ($kind === 'sleep') {
                $out['sleeps']++;

                continue;
            }

            [$points, $bucket] = match ($kind) {
                'sniper' => [self::SNIPER_POINTS, 'snipers'],
                'tight' => [self::TIGHT_POINTS, 'tight'],
                'near' => [self::NEAR_POINTS, 'nears'],
                default => [0, 'classic'],
            };

            $out['suspicions'][] = $points;
            $out[$bucket]++;
            $out['offsets'][] = $gap - $expected;
        }

        return $out;
    }

    private function gapKind(int $gap, int $expected): string
    {
        if ($gap < $expected) {
            return 'early';
        }

        if ($gap <= $expected + $this->settings->sniperSeconds()) {
            return 'sniper';
        }

        if ($gap <= $expected + max(480, $this->settings->sniperSeconds() * 2)) {
            return 'tight';
        }

        if ($gap <= $expected + self::NEAR_SECONDS) {
            return 'near';
        }

        if ($gap >= max((int) ($expected * 2.5), 6 * 3600)) {
            return 'sleep';
        }

        return 'classic';
    }

    /**
     * Horloge + jitter (bots type Voxa : cooldown + délai aléatoire borné).
     *
     * @param  list<int>  $offsets
     */
    private function isJitterClock(array $offsets, int $min): bool
    {
        if (count($offsets) < $min) {
            return false;
        }

        $stddev = $this->stddev($offsets);
        $median = $this->median($offsets);

        return $stddev !== null
            && $stddev <= $this->settings->maxStddev()
            && $median !== null
            && $median >= 0
            && $median <= self::NEAR_SECONDS;
    }

    /**
     * @param  list<int>  $values
     */
    private function stddev(array $values): ?float
    {
        $n = count($values);

        if ($n < 2) {
            return null;
        }

        $mean = array_sum($values) / $n;
        $var = 0.0;

        foreach ($values as $value) {
            $var += ($value - $mean) ** 2;
        }

        return sqrt($var / $n);
    }

    /**
     * @param  list<int>  $values
     */
    private function median(array $values): ?float
    {
        $n = count($values);

        if ($n === 0) {
            return null;
        }

        sort($values);
        $mid = intdiv($n, 2);

        if ($n % 2 === 1) {
            return (float) $values[$mid];
        }

        return ($values[$mid - 1] + $values[$mid]) / 2;
    }

    /**
     * @return list<string>
     */
    private function liveFlags(): array
    {
        if (! $this->context->fromVoteDone) {
            return [];
        }

        $flags = [];

        if (BrowserCheck::botUserAgent($this->context->userAgent)) {
            $flags[] = 'bot_ua';
        }

        if ($this->context->webdriver()) {
            $flags[] = 'webdriver';
        }

        return $flags;
    }

    /**
     * @param  list<string>  $ips
     * @return list<int>
     */
    private function linePartners(int $userId, array $ips): array
    {
        $partners = [];

        foreach ($this->claimNetworks() as $prefix => $users) {
            $hit = false;

            foreach ($ips as $ip) {
                if (Networks::prefix($ip) === $prefix) {
                    $hit = true;
                    break;
                }
            }

            if (! $hit) {
                continue;
            }

            foreach (array_keys($users) as $other) {
                if ((int) $other !== $userId) {
                    $partners[(int) $other] = true;
                }
            }
        }

        return array_keys($partners);
    }

    /**
     * @param  list<int>  $partners
     */
    private function votesInLockstep(int $userId, array $partners): bool
    {
        if ($partners === [] || count($partners) > 6 || ! class_exists(Vote::class)) {
            return false;
        }

        try {
            $since = now()->subDays(self::CLAIM_DAYS);
            $mine = Vote::query()
                ->where('user_id', $userId)
                ->where('created_at', '>=', $since)
                ->orderBy('created_at')
                ->pluck('created_at');
            $other = Vote::query()
                ->whereIn('user_id', $partners)
                ->where('created_at', '>=', $since)
                ->orderBy('created_at')
                ->pluck('created_at');
        } catch (Throwable) {
            return false;
        }

        $others = [];

        foreach ($other as $at) {
            $others[] = Carbon::parse($at)->getTimestamp();
        }

        $hits = 0;
        $total = 0;
        $index = 0;
        $count = count($others);

        foreach ($mine as $at) {
            $stamp = Carbon::parse($at)->getTimestamp();
            $total++;

            while ($index < $count && $stamp - $others[$index] > 90) {
                $index++;
            }

            for ($cursor = $index; $cursor < $count && $others[$cursor] - $stamp <= 90; $cursor++) {
                if (abs($others[$cursor] - $stamp) <= 90) {
                    $hits++;
                    break;
                }
            }
        }

        return $total > 0 && $hits >= 15 && $hits / $total >= 0.55;
    }

    /**
     * @return array<string, array<int, true>>
     */
    private function claimNetworks(): array
    {
        try {
            return Cache::remember('voteguard.claim.networks', now()->addMinutes(10), function () {
                $map = [];
                $rows = Claim::query()
                    ->where('created_at', '>=', now()->subDays(7))
                    ->whereIn('outcome', Claim::REWARDED)
                    ->whereNotNull('user_id')
                    ->whereNotNull('ip')
                    ->get(['user_id', 'ip']);

                foreach ($rows as $row) {
                    $prefix = Networks::prefix($row->ip);

                    if ($prefix === null) {
                        continue;
                    }

                    $map[$prefix][(int) $row->user_id] = true;
                }

                return $map;
            });
        } catch (Throwable) {
            return [];
        }
    }

    private function signalHealthy(string $flag): bool
    {
        $global = $this->globalRatios();

        if ((int) ($global['n'] ?? 0) < 50) {
            return true;
        }

        return (float) ($global[$flag] ?? 0.0) <= self::GLOBAL_BREAKER;
    }

    /**
     * @return \Illuminate\Support\Collection<int, int>
     */
    private function trapUserIds()
    {
        try {
            return Claim::query()
                ->where('created_at', '>=', now()->subDays(60))
                ->where('outcome', 'not_found')
                ->whereNotNull('user_id')
                ->get(['user_id', 'flags'])
                ->filter(fn (Claim $claim) => in_array('honeypot', $claim->flags ?? [], true))
                ->pluck('user_id')
                ->unique()
                ->values();
        } catch (Throwable) {
            return collect();
        }
    }

    /**
     * @param  list<string>  $flags
     */
    private function weight(array $flags): int
    {
        $total = 0;

        foreach (array_unique($flags) as $flag) {
            $total += self::LIVE_WEIGHTS[$flag] ?? 0;
        }

        return min(100, $total);
    }

    /**
     * @param  list<string>  $flags
     */
    private function upsertSuspect(User $user, int $score, array $flags, int $extraVotes): void
    {
        $suspect = Suspect::query()->firstOrNew(['user_id' => $user->id]);

        $suspect->score = $score;
        $suspect->max_score = max((int) $suspect->max_score, $score);
        $suspect->votes_analyzed = (int) $suspect->votes_analyzed + $extraVotes;
        $suspect->last_flags = array_values(array_unique($flags));

        if (! $suspect->isLocked()) {
            $suspect->status = $this->statusFromScore($score);
        }

        $suspect->save();
    }

    /**
     * @param  list<string>  $flags
     */
    private function downgradeExisting(User $user, int $score, array $flags): void
    {
        $suspect = Suspect::query()->firstWhere('user_id', $user->id);

        if ($suspect === null || $suspect->isLocked()) {
            return;
        }

        $suspect->score = $score;
        $suspect->last_flags = array_values(array_unique($flags));
        $suspect->status = $this->statusFromScore($score);
        $suspect->save();
    }

    private function statusFromScore(int $score): string
    {
        if ($score >= $this->settings->likelyScore()) {
            return 'likely';
        }

        if ($score >= $this->settings->suspectScore()) {
            return 'suspect';
        }

        if ($score >= $this->settings->watchScore()) {
            return 'watch';
        }

        return 'clear';
    }

    /**
     * Appelé après l'envoi de la réponse au joueur (voir VoteObserver) : l'appel HTTP ne le ralentit pas.
     *
     * @param  list<string>  $flags
     */
    private function notify(User $user, int $score, array $flags): void
    {
        $webhook = $this->settings->webhook();

        if ($webhook === null || $score < $this->settings->suspectScore()) {
            return;
        }

        $cacheKey = 'voteguard.notify.'.$user->id;

        if (Cache::has($cacheKey)) {
            return;
        }

        Cache::put($cacheKey, true, now()->addHours(6));

        try {
            $fields = [
                ['name' => 'Joueur', 'value' => $user->name, 'inline' => true],
                ['name' => 'Score', 'value' => (string) $score, 'inline' => true],
                ['name' => 'Signaux', 'value' => $flags !== [] ? implode(', ', $flags) : '—'],
            ];

            if (in_array('recommend_block', $flags, true)) {
                $fields[] = ['name' => 'Action', 'value' => 'Bloquer les récompenses vote'];
            }

            Http::timeout(3)->post($webhook, [
                'embeds' => [[
                    'title' => 'VoteGuard — '.$this->statusFromScore($score),
                    'color' => $score >= $this->settings->likelyScore() ? 15158332 : 15105570,
                    'fields' => $fields,
                    'timestamp' => now()->toIso8601String(),
                ]],
            ]);
        } catch (Throwable) {
            //
        }
    }

    /**
     * @return array{0: Carbon, 1: Carbon}
     */
    private function period(?Carbon $from, ?Carbon $to): array
    {
        $from ??= now()->subDays(60);
        $to ??= now();

        return [$from->copy()->startOfDay(), $to->copy()->endOfDay()];
    }

    private function formatDuration(int $seconds): string
    {
        $seconds = max(0, $seconds);

        return sprintf('%02d:%02d:%02d', intdiv($seconds, 3600), intdiv($seconds % 3600, 60), $seconds % 60);
    }

    private function truncate(?string $value, int $max): ?string
    {
        if ($value === null) {
            return null;
        }

        return mb_substr($value, 0, $max);
    }
}
