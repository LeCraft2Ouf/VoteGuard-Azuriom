<?php

namespace Azuriom\Plugin\VoteGuard\Controllers\Admin;

use Azuriom\Http\Controllers\Controller;
use Azuriom\Models\ActionLog;
use Azuriom\Plugin\VoteGuard\Detector;
use Azuriom\Plugin\VoteGuard\Models\Detection;
use Azuriom\Plugin\VoteGuard\Models\Suspect;
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
            ->when($status, fn ($query) => $query->where('status', $status))
            ->where('status', '!=', 'clear')
            ->orderByDesc('score')
            ->paginate();

        return view('voteguard::admin.index', [
            'search' => $search,
            'status' => $status,
            'suspects' => $suspects,
            'countLikely' => Suspect::query()->where('status', 'likely')->count(),
            'countSuspect' => Suspect::query()->where('status', 'suspect')->count(),
            'countWatch' => Suspect::query()->where('status', 'watch')->count(),
            'detectionsToday' => Detection::query()->where('created_at', '>=', today())->count(),
        ]);
    }

    public function scan(Detector $detector): RedirectResponse
    {
        $result = $detector->scanRecent(30, 200);

        ActionLog::log('voteguard.scan');

        return to_route('voteguard.admin.index')
            ->with('success', trans('voteguard::admin.scan.done', [
                'scanned' => $result['scanned'],
                'flagged' => $result['flagged'],
            ]));
    }
}
