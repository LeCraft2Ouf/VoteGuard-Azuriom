<?php

namespace Azuriom\Plugin\VoteGuard\Controllers\Admin;

use Azuriom\Http\Controllers\Controller;
use Azuriom\Models\ActionLog;
use Azuriom\Models\User;
use Azuriom\Plugin\VoteGuard\Detector;
use Azuriom\Plugin\VoteGuard\Models\Suspect;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function index(Request $request): View
    {
        $search = $request->input('search');
        $status = $request->input('status');

        $suspects = Suspect::query()
            ->with('user')
            ->when($search, function ($query, string $search) {
                $query->whereHas('user', function ($userQuery) use ($search) {
                    $userQuery->where('name', 'like', '%'.$search.'%');
                });
            })
            ->when($status === 'blocked', fn ($query) => $query->where('blocked', true))
            ->when($status && $status !== 'blocked', fn ($query) => $query->where('status', $status))
            ->when(! filled($search) && ! filled($status), fn ($query) => $query->where('status', '!=', 'clear'))
            ->orderByDesc('blocked')
            ->orderByDesc('score')
            ->paginate();

        $unlisted = collect();

        if (filled($search) && $suspects->total() === 0) {
            $unlisted = User::query()
                ->where('name', 'like', '%'.$search.'%')
                ->orderBy('name')
                ->limit(15)
                ->get();
        }

        return view('voteguard::admin.index', [
            'search' => $search,
            'status' => $status,
            'scanFrom' => $request->input('from', now()->subDays(60)->toDateString()),
            'scanTo' => $request->input('to', now()->toDateString()),
            'suspects' => $suspects,
            'countLikely' => Suspect::query()->where('status', 'likely')->count(),
            'countSuspect' => Suspect::query()->where('status', 'suspect')->count(),
            'countWatch' => Suspect::query()->where('status', 'watch')->count(),
            'countClear' => Suspect::query()->where('status', 'clear')->count(),
            'countBlocked' => Suspect::query()->where('blocked', true)->count(),
            'unlisted' => $unlisted,
        ]);
    }

    public function scan(Request $request, Detector $detector): RedirectResponse|JsonResponse
    {
        $validated = $this->validate($request, [
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'offset' => ['nullable', 'integer', 'min:0'],
        ]);

        $from = Carbon::parse($validated['from'] ?? now()->subDays(60)->toDateString())->startOfDay();
        $to = Carbon::parse($validated['to'] ?? now()->toDateString())->endOfDay();

        if ($from->greaterThan($to)) {
            [$from, $to] = [$to->copy()->startOfDay(), $from->copy()->endOfDay()];
        }

        if ($from->diffInDays($to, true) > 366) {
            $from = $to->copy()->subDays(366)->startOfDay();
        }

        $offset = max(0, (int) ($validated['offset'] ?? 0));
        $ajax = $request->expectsJson() || $request->ajax();
        $chunk = $ajax ? 20 : null;

        $result = $detector->scanRecent(60, 0, $offset, $chunk, $from, $to);

        if ($offset === 0) {
            ActionLog::log('voteguard.scan');
        }

        if ($result['done']) {
            $result['likely'] = Suspect::query()->where('status', 'likely')->count();
            $result['suspect'] = Suspect::query()->where('status', 'suspect')->count();
            $result['watch'] = Suspect::query()->where('status', 'watch')->count();
        }

        if ($ajax) {
            return response()->json($result);
        }

        return to_route('voteguard.admin.index', [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
        ])->with('success', trans('voteguard::admin.scan.done', [
            'scanned' => $result['total'],
            'likely' => $result['likely'] ?? 0,
            'suspect' => $result['suspect'] ?? 0,
            'watch' => $result['watch'] ?? 0,
        ]));
    }
}
