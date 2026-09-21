<?php

namespace Azuriom\Plugin\VoteGuard\Middleware;

use Azuriom\Models\User;
use Azuriom\Plugin\Vote\Models\Site;
use Azuriom\Plugin\VoteGuard\Blocklist;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

class BlockBlacklistedVote
{
    public function __construct(
        private Blocklist $blocklist,
    ) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->isVoteDone($request)) {
            return $next($request);
        }

        $user = $request->user();

        if ($user === null && ! setting('vote.auth-required', false)) {
            $name = $request->input('user');
            $user = is_string($name) && $name !== ''
                ? User::firstWhere('name', $name)
                : null;
        }

        if ($user === null) {
            return $next($request);
        }

        try {
            $blocked = $this->blocklist->isBlocked($user);
        } catch (\Throwable) {
            return $next($request);
        }

        if (! $blocked) {
            return $next($request);
        }

        $this->rememberCooldown($request);

        return response()->json([
            'message' => trans('voteguard::messages.blocked'),
        ], 403);
    }

    private function isVoteDone(Request $request): bool
    {
        if (! $request->isMethod('POST')) {
            return false;
        }

        if ($request->routeIs('vote.done')) {
            return true;
        }

        $path = $request->path();

        return str_starts_with($path, 'vote/site/') && str_ends_with($path, '/done');
    }

    private function rememberCooldown(Request $request): void
    {
        $site = $request->route('site');

        if (! $site instanceof Site) {
            return;
        }

        $minutes = max(1, (int) ($site->vote_delay ?? 90));
        $next = now()->addMinutes($minutes);

        Cache::put('votes.site.'.$site->id.'.'.$request->ip(), $next, $next);
    }
}
