<?php

namespace Azuriom\Plugin\VoteGuard\Controllers\Admin;

use Azuriom\Http\Controllers\Controller;
use Azuriom\Models\ActionLog;
use Azuriom\Models\User;
use Azuriom\Plugin\Vote\Models\Vote;
use Azuriom\Plugin\VoteGuard\Detector;
use Azuriom\Plugin\VoteGuard\Models\Suspect;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Throwable;

class DashboardController extends Controller
{
    public function index(Request $request): View
    {
        $search = $request->input('search');
        $status = $request->input('status');
        [$periodFrom, $periodTo] = $this->listPeriod($request);

        $suspects = $this->votedInPeriod(Suspect::query(), $periodFrom, $periodTo)
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
            ->orderByDesc('updated_at')
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
            'filterFrom' => $periodFrom?->toDateString() ?? '',
            'filterTo' => $periodTo?->toDateString() ?? '',
            'scanFrom' => $request->input('from', now()->subDays(60)->toDateString()),
            'scanTo' => $request->input('to', now()->toDateString()),
            'suspects' => $suspects,
            'countLikely' => $this->votedInPeriod(Suspect::query(), $periodFrom, $periodTo)->where('status', 'likely')->count(),
            'countSuspect' => $this->votedInPeriod(Suspect::query(), $periodFrom, $periodTo)->where('status', 'suspect')->count(),
            'countWatch' => $this->votedInPeriod(Suspect::query(), $periodFrom, $periodTo)->where('status', 'watch')->count(),
            'countClear' => $this->votedInPeriod(Suspect::query(), $periodFrom, $periodTo)->where('status', 'clear')->count(),
            'countBlocked' => $this->votedInPeriod(Suspect::query(), $periodFrom, $periodTo)->where('blocked', true)->count(),
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

    /**
     * @return array{0: ?Carbon, 1: ?Carbon}
     */
    private function listPeriod(Request $request): array
    {
        if (! $request->filled('from') || ! $request->filled('to')) {
            return [null, null];
        }

        try {
            $from = Carbon::parse($request->input('from'))->startOfDay();
            $to = Carbon::parse($request->input('to'))->endOfDay();
        } catch (Throwable) {
            return [null, null];
        }

        if ($from->greaterThan($to)) {
            [$from, $to] = [$to->copy()->startOfDay(), $from->copy()->endOfDay()];
        }

        return [$from, $to];
    }

    private function votedInPeriod(Builder $query, ?Carbon $from, ?Carbon $to): Builder
    {
        if ($from === null || $to === null || ! class_exists(Vote::class)) {
            return $query;
        }

        $votes = (new Vote())->getTable();
        $suspects = $query->getModel()->getTable();

        return $query->whereExists(function ($sub) use ($votes, $suspects, $from, $to) {
            $sub->selectRaw('1')
                ->from($votes)
                ->whereColumn($votes.'.user_id', $suspects.'.user_id')
                ->whereBetween($votes.'.created_at', [$from, $to]);
        });
    }
}
