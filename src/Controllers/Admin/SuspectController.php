<?php

namespace Azuriom\Plugin\VoteGuard\Controllers\Admin;

use Azuriom\Http\Controllers\Controller;
use Azuriom\Models\ActionLog;
use Azuriom\Plugin\VoteGuard\Blocklist;
use Azuriom\Plugin\VoteGuard\Detector;
use Azuriom\Plugin\VoteGuard\Models\Suspect;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class SuspectController extends Controller
{
    public function show(Suspect $suspect, Detector $detector): View
    {
        $suspect->load(['user', 'reviewer']);

        $detections = $suspect->detections()
            ->latest()
            ->limit(50)
            ->get();

        return view('voteguard::admin.show', [
            'suspect' => $suspect,
            'detections' => $detections,
            'intervals' => $detector->intervalRows($suspect->user_id, 50),
            'mix' => $detector->patternAnalysis($suspect->user_id),
        ]);
    }

    public function update(Request $request, Suspect $suspect, Blocklist $blocklist): RedirectResponse
    {
        $validated = $this->validate($request, [
            'status' => ['required', Rule::in(['watch', 'suspect', 'likely', 'confirmed', 'false_positive'])],
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
