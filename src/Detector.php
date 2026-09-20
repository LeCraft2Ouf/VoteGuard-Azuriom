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

        $flags = array_merge(
            $this->requestFlags($vote),
            $this->patternFlags($user->id, $vote->site_id),
            $this->sessionFlags($user->id),
            $this->ipFarmFlags($user->id),
        );

        $score = $this->score($flags);

        if ($score < 15 && $flags === []) {
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

    public function analyzeUser(int $userId): int
    {
        $user = User::find($userId);

        if ($user === null || $this->settings->isWhitelisted($user->name)) {
            return 0;
        }

        $siteIds = Vote::query()
            ->where('user_id', $userId)
            ->where('created_at', '>=', now()->subDays(60))
            ->distinct()
            ->pluck('site_id');

        $flags = $this->sessionFlags($userId);

        foreach ($siteIds as $siteId) {
            $flags = array_merge($flags, $this->patternFlags($userId, (int) $siteId));
        }

        $flags = array_values(array_unique($flags));
        $score = $this->score($flags);

        if ($score < $this->settings->watchScore()) {
            return $score;
        }

        $this->upsertSuspect($user, $score, $flags, 0);

        return $score;
    }

    /**
     * @return array{scanned: int, flagged: int}
     */
    public function scanRecent(int $days = 60, int $limit = 400): array
    {
        if (! class_exists(Vote::class)) {
            return ['scanned' => 0, 'flagged' => 0];
        }

        $query = Vote::query()
            ->select('user_id', DB::raw('COUNT(*) as vote_count'))
            ->where('created_at', '>=', now()->subDays($days))
            ->groupBy('user_id')
            ->havingRaw('COUNT(*) >= ?', [$this->settings->minVotes()])
            ->orderByDesc('vote_count');

        if ($limit > 0) {
            $query->limit($limit);
        }

        $userIds = $query->pluck('user_id');
        $flagged = 0;

        foreach ($userIds as $userId) {
            if ($this->analyzeUser((int) $userId) >= $this->settings->watchScore()) {
                $flagged++;
            }
        }

        return [
            'scanned' => $userIds->count(),
            'flagged' => $flagged,
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

            if (isset($lastBySite[$siteId])) {
                $gap = (int) $vote->created_at->diffInSeconds($lastBySite[$siteId]);
                $sniper = $gap >= $expected && $gap <= $expected + $this->settings->sniperSeconds();
            }

            $rows[] = [
                'site' => $vote->site->name ?? '#'.$siteId,
                'at' => $vote->created_at,
                'gap' => $gap,
                'gap_label' => $gap === null ? '—' : $this->formatDuration($gap),
                'expected' => $expected,
                'expected_label' => $this->formatDuration($expected),
                'delta' => $gap === null ? null : $gap - $expected,
                'sniper' => $sniper,
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
     * @return list<string>
     */
    private function patternFlags(int $userId, ?int $siteId): array
    {
        if ($siteId === null || ! class_exists(Vote::class)) {
            return [];
        }

        $votes = Vote::query()
            ->where('user_id', $userId)
            ->where('site_id', $siteId)
            ->where('created_at', '>=', now()->subDays(60))
            ->latest()
            ->limit(80)
            ->pluck('created_at')
            ->reverse()
            ->values();

        $site = class_exists(Site::class) ? Site::find($siteId) : null;

        return $this->flagsFromTimestamps($votes, $this->expectedDelay($site));
    }

    /**
     * @return list<string>
     */
    private function sessionFlags(int $userId): array
    {
        if (! class_exists(Vote::class)) {
            return [];
        }

        $times = Vote::query()
            ->where('user_id', $userId)
            ->where('created_at', '>=', now()->subDays(60))
            ->orderBy('created_at')
            ->pluck('created_at');

        $sessions = [];
        $lastVote = null;

        foreach ($times as $time) {
            $at = Carbon::parse($time);

            if ($lastVote === null || $at->diffInSeconds($lastVote) > 900) {
                $sessions[] = $at;
            }

            $lastVote = $at;
        }

        $minDelay = 90;

        if (class_exists(Site::class)) {
            $siteIds = Vote::query()
                ->where('user_id', $userId)
                ->where('created_at', '>=', now()->subDays(60))
                ->distinct()
                ->pluck('site_id');

            $fromSites = Site::query()->whereIn('id', $siteIds)->min('vote_delay');

            if ($fromSites) {
                $minDelay = (int) $fromSites;
            }
        }

        return $this->flagsFromTimestamps(collect($sessions), max(60, $minDelay * 60));
    }

    /**
     * @param  \Illuminate\Support\Collection<int, mixed>  $timestamps
     * @return list<string>
     */
    private function flagsFromTimestamps($timestamps, int $expected): array
    {
        $min = $this->settings->minVotes();

        if ($timestamps->count() < $min) {
            return [];
        }

        $intervals = [];

        for ($i = 1, $len = $timestamps->count(); $i < $len; $i++) {
            $intervals[] = (int) Carbon::parse($timestamps[$i])->diffInSeconds(Carbon::parse($timestamps[$i - 1]));
        }

        if ($intervals === []) {
            return [];
        }

        $low = (int) ($expected * 0.92);
        $high = (int) ($expected * 1.25);
        $near = array_values(array_filter($intervals, fn (int $gap) => $gap >= $low && $gap <= $high));
        $flags = [];
        $ratio = count($near) / count($intervals);

        if (count($near) >= $min - 1 && $ratio >= 0.40) {
            $flags[] = 'regular_interval';
        }

        if (count($near) >= $min - 1 && $this->stddev($near) <= $this->settings->maxStddev()) {
            $flags[] = 'regular_interval';
        }

        $sniperLimit = $expected + $this->settings->sniperSeconds();
        $snipers = array_values(array_filter($near, fn (int $gap) => $gap >= $expected && $gap <= $sniperLimit));

        if (count($snipers) >= $min - 1) {
            $flags[] = 'cooldown_sniper';
        }

        $awake = array_values(array_filter($intervals, fn (int $gap) => $gap <= (int) ($expected * 2.2)));

        if (count($awake) >= 10) {
            $span = array_sum(array_slice($awake, -12));

            if ($span >= 10 * 3600 && $span <= 20 * 3600) {
                $flags[] = 'always_on';
            }
        }

        return array_values(array_unique($flags));
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

    private function expectedDelay(?object $site): int
    {
        $minutes = (int) ($site?->vote_delay ?? 90);

        if ($site !== null && filled($site->vote_reset_at) && $minutes <= 0) {
            return 86400;
        }

        return max(60, $minutes * 60);
    }

    /**
     * @param  list<int>  $values
     */
    private function stddev(array $values): float
    {
        $n = count($values);

        if ($n < 2) {
            return 0.0;
        }

        $mean = array_sum($values) / $n;
        $sum = 0.0;

        foreach ($values as $value) {
            $sum += ($value - $mean) ** 2;
        }

        return sqrt($sum / $n);
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
