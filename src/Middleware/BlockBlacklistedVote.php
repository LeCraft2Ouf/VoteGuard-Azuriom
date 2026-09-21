<?php

namespace Azuriom\Plugin\VoteGuard\Middleware;

use Azuriom\Models\User;
use Azuriom\Plugin\Vote\Models\Site;
use Azuriom\Plugin\VoteGuard\Blocklist;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class BlockBlacklistedVote
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->isVoteDone($request)) {
            return $next($request);
        }

        try {
            $user = $request->user();

            if ($user === null) {
                $name = $request->input('user');
                $user = is_string($name) && $name !== ''
                    ? User::firstWhere('name', $name)
                    : null;
            }

            $blocked = $user !== null && app(Blocklist::class)->isBlocked($user);
        } catch (Throwable) {
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

        $path = trim($request->path(), '/');

        return (bool) preg_match('#(?:^|/)vote/site/[^/]+/done$#', $path);
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
