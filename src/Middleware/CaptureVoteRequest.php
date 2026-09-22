<?php

namespace Azuriom\Plugin\VoteGuard\Middleware;

use Azuriom\Plugin\VoteGuard\ClaimRecorder;
use Azuriom\Plugin\VoteGuard\VoteContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class CaptureVoteRequest
{
    public function __construct(
        private VoteContext $context,
    ) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if ($this->isVoteDone($request)) {
            $this->capture($request);
        }

        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        if (! $this->context->fromVoteDone) {
            return;
        }

        try {
            app(ClaimRecorder::class)->record($request, $response);
        } catch (Throwable) {
            //
        }
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

    private function capture(Request $request): void
    {
        $this->context->fromVoteDone = true;
        $this->context->ip = $request->ip();
        $this->context->userAgent = $request->userAgent();
        $this->context->acceptLanguage = $request->header('Accept-Language');
        $this->context->accept = $request->header('Accept');
        $this->context->token = $request->header('X-Request-Ref')
            ?: $request->header('X-VoteGuard-Token')
            ?: $request->input('_ref')
            ?: $request->input('voteguard_token');

        if (is_string($this->context->token) && preg_match('/^[a-f0-9]{32}$/', $this->context->token) === 1) {
            $session = Cache::get('voteguard.'.$this->context->token);

            if (is_array($session)) {
                $this->context->session = $session;
            }
        }
    }
}
