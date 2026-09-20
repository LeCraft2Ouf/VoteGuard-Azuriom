<?php

namespace Azuriom\Plugin\VoteGuard\Controllers\Admin;

use Azuriom\Http\Controllers\Controller;
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
            'intervals' => $detector->intervalRows($suspect->user_id),
        ]);
    }

    public function update(Request $request, Suspect $suspect): RedirectResponse
    {
        $validated = $this->validate($request, [
            'status' => ['required', Rule::in(['watch', 'suspect', 'likely', 'confirmed', 'false_positive'])],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);

        $suspect->update([
            'status' => $validated['status'],
            'note' => $validated['note'] ?? null,
            'reviewed_by' => $request->user()->id,
            'reviewed_at' => now(),
        ]);

        return to_route('voteguard.admin.show', $suspect)
            ->with('success', trans('messages.status.success'));
    }
}
