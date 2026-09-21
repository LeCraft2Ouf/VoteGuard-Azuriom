<?php

namespace Azuriom\Plugin\VoteGuard;

use Azuriom\Models\User;
use Azuriom\Plugin\Vote\Models\Site;
use Azuriom\Plugin\Vote\Models\Vote;
use Azuriom\Plugin\VoteGuard\Models\Detection;
use Azuriom\Plugin\VoteGuard\Models\Suspect;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Throwable;

class Detector
{
    private const BOT_UA = '/headlesschrome|puppeteer|playwright|selenium|webdriver|phantomjs|python-requests|python-urllib|aiohttp\/|httpx\/|curl\/|wget\/|go-http-client|okhttp|apache-httpclient|java\/|libwww-perl|scrapy|httpie|node-fetch|undici|axios\/\d|postmanruntime|insomnia|guzzlehttp/i';

    /**
     * @var array<string, int>
     */
    private const WEIGHTS = [
        'no_session' => 25,
        'no_click' => 20,
        'no_pointer' => 10,
        'too_fast' => 15,
        'webdriver' => 35,
        'bot_ua' => 40,
        'thin_headers' => 15,
        'regular_interval' => 35,
        'cooldown_sniper' => 30,
        'always_on' => 30,
        'ip_farm' => 20,
    ];

    private const SNIPER_POINTS = 100;

    private const TIGHT_POINTS = 40;

    private const CONFIDENCE_AWAKE = 24;

    private const MIN_FLAG_AWAKE = 12;

    public function __construct(
        private Settings $settings,
        private VoteContext $context,
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

        $pattern = $this->patternAnalysis($user->id);
        $requestFlags = array_merge(
            $this->requestFlags($vote),
            $this->ipFarmFlags($user->id),
        );
        $flags = array_values(array_unique(array_merge($pattern['flags'], $requestFlags)));
        $score = min(100, $pattern['score'] + $this->score($requestFlags));

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

        if ($user === null || $this->settings->isWhitelisted($user->name)) {
            return 0;
        }

        [$from, $to] = $this->period($from, $to);

        $pattern = $this->patternAnalysis($userId, $from, $to);
        $score = $pattern['score'];
        $flags = $pattern['flags'];

        if ($score < $this->settings->watchScore()) {
            $this->downgradeExisting($user, $score, $flags);

            return $score;
        }

        $this->upsertSuspect($user, $score, $flags, 0);

        return $score;
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
     * @return list<array{site: string, at: string, gap: ?int, expected: int, sniper: bool}>
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
            $expected = $this->expectedDelay($vote->site);
            $gap = null;
            $sniper = false;

            $kind = 'first';

            if (isset($lastBySite[$siteId])) {
                $gap = (int) $vote->created_at->diffInSeconds($lastBySite[$siteId], true);
                $kind = $this->gapKind($gap, $expected);
                $sniper = $kind === 'sniper';
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
                'sniper' => $sniper,
                'kind' => $kind,
            ];

            $lastBySite[$siteId] = $vote->created_at;
        }

        return array_reverse($rows);
    }

    /**
     * @return list<string>
     */
    private function requestFlags(Vote $vote): array
    {
        if (! $this->context->fromVoteDone) {
            return [];
        }

        $flags = [];

        if (! $this->context->hasSession()) {
            $flags[] = 'no_session';
        } else {
            if (! $this->context->hasClick($vote->site_id)) {
                $flags[] = 'no_click';
            }

            if ($this->context->pointerCount() < 3 && $this->context->pageMs() < 1500) {
                $flags[] = 'no_pointer';
            }

            $age = $this->context->clickAgeSeconds($vote->site_id);

            if ($age !== null && $age < 4) {
                $flags[] = 'too_fast';
            }

            if ($this->context->webdriver()) {
                $flags[] = 'webdriver';
            }
        }

        $ua = (string) $this->context->userAgent;

        if ($ua === '' || preg_match(self::BOT_UA, $ua) === 1) {
            $flags[] = 'bot_ua';
        }

        $lang = trim((string) $this->context->acceptLanguage);
        $accept = strtolower((string) $this->context->accept);

        if ($lang === '' && ($accept === '' || $accept === '*/*')) {
            $flags[] = 'thin_headers';
        }

        return $flags;
    }

    /**
     * Score = moyenne des votes éveillés, pondérée par le volume.
     * Sniper = 100, limite (+3–8 min) = 40, classique = 0.
     *
     * @return array{score: int, flags: list<string>, snipers: int, tight: int, classic: int, sleeps: int, awake: int, total: int}
     */
    public function patternAnalysis(int $userId, ?Carbon $from = null, ?Carbon $to = null): array
    {
        $zero = [
            'score' => 0,
            'flags' => [],
            'snipers' => 0,
            'tight' => 0,
            'classic' => 0,
            'sleeps' => 0,
            'awake' => 0,
            'total' => 0,
        ];

        if (! class_exists(Vote::class)) {
            return $zero;
        }

        [$from, $to] = $this->period($from, $to);

        $siteIds = Vote::query()
            ->where('user_id', $userId)
            ->whereBetween('created_at', [$from, $to])
            ->distinct()
            ->pluck('site_id');

        $merged = $zero;
        $suspicions = [];

        foreach ($siteIds as $siteId) {
            $votes = Vote::query()
                ->where('user_id', $userId)
                ->where('site_id', (int) $siteId)
                ->whereBetween('created_at', [$from, $to])
                ->orderBy('created_at')
                ->pluck('created_at')
                ->values();

            $site = class_exists(Site::class) ? Site::find($siteId) : null;
            $part = $this->classifyTimestamps($votes, $this->expectedDelay($site));

            $suspicions = array_merge($suspicions, $part['suspicions']);
            $merged['snipers'] += $part['snipers'];
            $merged['tight'] += $part['tight'];
            $merged['classic'] += $part['classic'];
            $merged['sleeps'] += $part['sleeps'];
            $merged['total'] += $part['total'];
        }

        $awake = count($suspicions);
        $merged['awake'] = $awake;
        $scored = $this->scoreMix($merged + ['sum' => array_sum($suspicions)]);
        $merged['score'] = $scored['score'];
        $merged['flags'] = $scored['flags'];

        return $merged;
    }

    /**
     * @param  array{snipers: int, tight: int, classic?: int, sleeps: int, total: int, awake: int, sum?: int}  $mix
     * @return array{score: int, flags: list<string>}
     */
    public function scoreMix(array $mix): array
    {
        $awake = (int) ($mix['awake'] ?? 0);
        $min = $this->settings->minVotes();

        if ($awake < $min) {
            return ['score' => 0, 'flags' => []];
        }

        $snipers = (int) ($mix['snipers'] ?? 0);
        $tight = (int) ($mix['tight'] ?? 0);
        $sleeps = (int) ($mix['sleeps'] ?? 0);
        $total = max(1, (int) ($mix['total'] ?? 0));
        $sum = (int) ($mix['sum'] ?? ($snipers * self::SNIPER_POINTS + $tight * self::TIGHT_POINTS));
        $avg = $sum / $awake;
        $score = (int) round($avg * min(1.0, $awake / self::CONFIDENCE_AWAKE));
        $sniperRatio = $snipers / $awake;
        $botRatio = ($snipers + $tight) / $awake;
        $sleepRatio = $sleeps / $total;
        $flags = [];

        if ($awake >= self::MIN_FLAG_AWAKE && $snipers >= $min && $sniperRatio >= 0.40) {
            $flags[] = 'cooldown_sniper';
        }

        if ($awake >= self::MIN_FLAG_AWAKE && $sniperRatio >= 0.35 && $botRatio >= 0.50) {
            $flags[] = 'regular_interval';
        }

        if ($total >= 20 && $sleepRatio <= 0.15 && $sniperRatio >= 0.40) {
            $flags[] = 'always_on';
            $score = min(100, $score + 10);
        }

        return ['score' => $score, 'flags' => $flags];
    }

    /**
     * @param  \Illuminate\Support\Collection<int, mixed>  $timestamps
     * @return array{suspicions: list<int>, snipers: int, tight: int, classic: int, sleeps: int, total: int}
     */
    private function classifyTimestamps($timestamps, int $expected): array
    {
        $out = [
            'suspicions' => [],
            'snipers' => 0,
            'tight' => 0,
            'classic' => 0,
            'sleeps' => 0,
            'total' => 0,
        ];

        if ($timestamps->count() < 2) {
            return $out;
        }

        for ($i = 1, $len = $timestamps->count(); $i < $len; $i++) {
            $gap = (int) Carbon::parse($timestamps[$i])->diffInSeconds(Carbon::parse($timestamps[$i - 1]), true);
            $out['total']++;
            $kind = $this->gapKind($gap, $expected);

            if ($kind === 'early') {
                continue;
            }

            if ($kind === 'sleep') {
                $out['sleeps']++;

                continue;
            }

            if ($kind === 'sniper') {
                $out['suspicions'][] = self::SNIPER_POINTS;
                $out['snipers']++;

                continue;
            }

            if ($kind === 'tight') {
                $out['suspicions'][] = self::TIGHT_POINTS;
                $out['tight']++;

                continue;
            }

            $out['suspicions'][] = 0;
            $out['classic']++;
        }

        return $out;
    }

    public function classifyGap(int $gap, int $expected): string
    {
        return $this->gapKind($gap, $expected);
    }

    public function siteDelay(?object $site): int
    {
        return $this->expectedDelay($site);
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

        if ($gap >= max((int) ($expected * 2.5), 6 * 3600)) {
            return 'sleep';
        }

        return 'classic';
    }

    /**
     * @return list<string>
     */
    private function ipFarmFlags(int $userId): array
    {
        if (! $this->context->fromVoteDone || $this->context->ip === null) {
            return [];
        }

        $users = Detection::query()
            ->where('ip', $this->context->ip)
            ->where('created_at', '>=', now()->subDay())
            ->pluck('user_id')
            ->push($userId)
            ->unique();

        if ($users->count() >= $this->settings->ipFarmUsers()) {
            return ['ip_farm'];
        }

        return [];
    }

    /**
     * @param  list<string>  $flags
     */
    private function score(array $flags): int
    {
        $total = 0;

        foreach (array_unique($flags) as $flag) {
            $total += self::WEIGHTS[$flag] ?? 10;
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

        $payload = [
            'embeds' => [[
                'title' => 'VoteGuard — '.$this->statusFromScore($score),
                'color' => $score >= $this->settings->likelyScore() ? 15158332 : 15105570,
                'fields' => [
                    ['name' => 'Joueur', 'value' => $user->name, 'inline' => true],
                    ['name' => 'Score', 'value' => (string) $score, 'inline' => true],
                    ['name' => 'Signaux', 'value' => $flags !== [] ? implode(', ', $flags) : '—'],
                ],
                'timestamp' => now()->toIso8601String(),
            ]],
        ];

        dispatch(function () use ($webhook, $payload) {
            try {
                Http::timeout(3)->post($webhook, $payload);
            } catch (Throwable) {
                //
            }
        })->afterResponse();
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

    private function expectedDelay(?object $site): int
    {
        $minutes = (int) ($site?->vote_delay ?? 90);

        if ($site !== null && filled($site->vote_reset_at) && $minutes <= 0) {
            return 86400;
        }

        return max(60, $minutes * 60);
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
