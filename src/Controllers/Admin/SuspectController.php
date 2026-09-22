<?php

namespace Azuriom\Plugin\VoteGuard\Controllers\Admin;

use Azuriom\Http\Controllers\Controller;
use Azuriom\Models\ActionLog;
use Azuriom\Models\User;
use Azuriom\Plugin\Vote\Models\Site;
use Azuriom\Plugin\VoteGuard\Blocklist;
use Azuriom\Plugin\VoteGuard\Detector;
use Azuriom\Plugin\VoteGuard\Models\Claim;
use Azuriom\Plugin\VoteGuard\Models\Suspect;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Throwable;

class SuspectController extends Controller
{
    public function show(Suspect $suspect, Detector $detector): View
    {
        $suspect->load(['user', 'reviewer']);

        $detections = $suspect->detections()
            ->latest()
            ->limit(50)
            ->get();

        try {
            $claims = Claim::query()
                ->where('user_id', $suspect->user_id)
                ->where('created_at', '>=', now()->subDays(14))
                ->latest('id')
                ->limit(30)
                ->get();
        } catch (Throwable) {
            $claims = collect();
        }

        return view('voteguard::admin.show', [
            'suspect' => $suspect,
            'detections' => $detections,
            'intervals' => $detector->intervalRows($suspect->user_id, 50),
            'mix' => $detector->patternAnalysis($suspect->user_id),
            'claims' => $claims,
            'claimStats' => $detector->claimAnalysis($suspect->user_id),
            'siteNames' => class_exists(Site::class) ? Site::query()->pluck('name', 'id') : collect(),
        ]);
    }

    public function open(Request $request): RedirectResponse
    {
        $validated = $this->validate($request, [
            'name' => ['required', 'string', 'max:50'],
        ]);

        $user = User::query()
            ->whereRaw('LOWER(name) = ?', [strtolower(trim($validated['name']))])
            ->first();

        if ($user === null) {
            return to_route('voteguard.admin.index', ['search' => $validated['name']])
                ->with('error', trans('voteguard::admin.player_not_found'));
        }

        $suspect = Suspect::query()->firstOrCreate(
            ['user_id' => $user->id],
            [
                'score' => 0,
                'max_score' => 0,
                'status' => 'clear',
                'blocked' => false,
            ]
        );

        return to_route('voteguard.admin.show', $suspect);
    }

    public function update(Request $request, Suspect $suspect, Blocklist $blocklist): RedirectResponse
    {
        $validated = $this->validate($request, [
            'status' => ['required', Rule::in(['clear', 'watch', 'suspect', 'likely', 'confirmed', 'false_positive'])],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);

        $blocked = $request->boolean('blocked');

        if ($blocked) {
            $validated['status'] = 'confirmed';
        }

        $wasBlocked = (bool) $suspect->blocked;

        $suspect->update([
            'status' => $validated['status'],
            'blocked' => $blocked,
            'note' => $validated['note'] ?? null,
            'reviewed_by' => $request->user()->id,
            'reviewed_at' => now(),
        ]);

        $blocklist->flush();

        if ($wasBlocked !== $blocked && $suspect->user) {
            ActionLog::log('voteguard.block', $suspect->user);
        }

        return to_route('voteguard.admin.show', $suspect)
            ->with('success', trans('messages.status.success'));
    }
}
