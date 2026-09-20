<?php

namespace Azuriom\Plugin\VoteGuard\Controllers;

use Azuriom\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class SessionController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $token = bin2hex(random_bytes(16));

        Cache::put('voteguard.'.$token, [
            'ip' => $request->ip(),
            'user_id' => $request->user()?->id,
            'created_at' => now()->timestamp,
            'pointer' => 0,
            'page_ms' => 0,
            'webdriver' => $request->boolean('webdriver'),
            'clicks' => [],
        ], now()->addMinutes(45));

        return response()->json(['token' => $token]);
    }

    public function click(Request $request): JsonResponse
    {
        $data = $this->validate($request, [
            'token' => ['required', 'string', 'regex:/^[a-f0-9]{32}$/'],
            'site' => ['nullable', 'integer'],
            'pointer' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'page_ms' => ['nullable', 'integer', 'min:0', 'max:86400000'],
            'webdriver' => ['nullable', 'boolean'],
        ]);

        $key = 'voteguard.'.$data['token'];
        $session = Cache::get($key);

        if (! is_array($session)) {
            return response()->json(['ok' => false], 404);
        }

        $session['pointer'] = max((int) ($session['pointer'] ?? 0), (int) ($data['pointer'] ?? 0));
        $session['page_ms'] = max((int) ($session['page_ms'] ?? 0), (int) ($data['page_ms'] ?? 0));
        $session['webdriver'] = $session['webdriver'] || ($request->boolean('webdriver'));

        if (! empty($data['site'])) {
            $session['clicks'][(string) $data['site']] = now()->timestamp;
        }

        Cache::put($key, $session, now()->addMinutes(45));

        return response()->json(['ok' => true]);
    }
}
