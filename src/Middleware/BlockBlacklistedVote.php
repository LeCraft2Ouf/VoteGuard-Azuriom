<?php

namespace Azuriom\Plugin\VoteGuard\Middleware;

use Azuriom\Models\User;
use Azuriom\Plugin\VoteGuard\RewardGuard;
use Closure;
use Illuminate\Http\Request;
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

            app(RewardGuard::class)->denyIfBlocked($user);
        } catch (Throwable) {
            return $next($request);
        }

        return app(RewardGuard::class)->rewriteResponse($next($request));
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
}
