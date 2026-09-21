<?php

namespace Azuriom\Plugin\VoteGuard\Controllers\Admin;

use Azuriom\Http\Controllers\Controller;
use Azuriom\Models\ActionLog;
use Azuriom\Models\Setting;
use Azuriom\Models\User;
use Azuriom\Plugin\VoteGuard\Blocklist;
use Azuriom\Plugin\VoteGuard\Models\Suspect;
use Azuriom\Plugin\VoteGuard\Settings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SettingController extends Controller
{
    public function index(Settings $settings): View
    {
        return view('voteguard::admin.settings', [
            'enabled' => $settings->enabled(),
            'watch' => $settings->watchScore(),
            'suspect' => $settings->suspectScore(),
            'likely' => $settings->likelyScore(),
            'minVotes' => $settings->minVotes(),
            'stddev' => $settings->maxStddev(),
            'sniper' => $settings->sniperSeconds(),
            'ipFarm' => $settings->ipFarmUsers(),
            'webhook' => $settings->webhook() ?? '',
            'whitelist' => implode("\n", $settings->whitelist()),
            'blocklist' => implode("\n", $settings->blocklist()),
        ]);
    }

    public function save(Request $request, Blocklist $blocklist): RedirectResponse
    {
        $validated = $this->validate($request, [
            'watch' => ['required', 'integer', 'min:1', 'max:100'],
            'suspect' => ['required', 'integer', 'min:1', 'max:100'],
            'likely' => ['required', 'integer', 'min:1', 'max:100'],
            'min_votes' => ['required', 'integer', 'min:4', 'max:50'],
            'stddev' => ['required', 'integer', 'min:5', 'max:600'],
            'sniper' => ['required', 'integer', 'min:5', 'max:300'],
            'ip_farm' => ['required', 'integer', 'min:2', 'max:50'],
            'webhook' => ['nullable', 'url', 'max:255'],
            'whitelist' => ['nullable', 'string', 'max:4000'],
            'blocklist' => ['nullable', 'string', 'max:4000'],
        ]);

        $whitelist = $this->parseNames($request->input('whitelist', ''));
        $blockedNames = $this->parseNames($request->input('blocklist', ''));

        Setting::updateSettings([
            'voteguard.enabled' => $request->boolean('enabled'),
            'voteguard.watch' => $validated['watch'],
            'voteguard.suspect' => $validated['suspect'],
            'voteguard.likely' => $validated['likely'],
            'voteguard.min_votes' => $validated['min_votes'],
            'voteguard.stddev' => $validated['stddev'],
            'voteguard.sniper' => $validated['sniper'],
            'voteguard.ip_farm' => $validated['ip_farm'],
            'voteguard.webhook' => $validated['webhook'] ?? '',
            'voteguard.whitelist' => json_encode($whitelist),
            'voteguard.blocklist' => json_encode($blockedNames),
        ]);

        foreach ($blockedNames as $name) {
            $user = User::query()->whereRaw('LOWER(name) = ?', [$name])->first();

            if ($user === null) {
                continue;
            }

            $suspect = Suspect::query()->firstOrNew(['user_id' => $user->id]);
            $suspect->blocked = true;
            $suspect->status = 'confirmed';
            $suspect->score = (int) $suspect->score;
            $suspect->max_score = max((int) $suspect->max_score, (int) $suspect->score);
            $suspect->save();
        }

        $blocklist->flush();

        ActionLog::log('voteguard.settings');

        return to_route('voteguard.admin.settings')
            ->with('success', trans('messages.status.success'));
    }

    /**
     * @return list<string>
     */
    private function parseNames(mixed $raw): array
    {
        return collect(preg_split('/\r\n|\r|\n/', (string) $raw))
            ->map(fn (string $name) => strtolower(trim($name)))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }
}
