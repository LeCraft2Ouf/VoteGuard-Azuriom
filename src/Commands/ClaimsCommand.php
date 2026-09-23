<?php

namespace Azuriom\Plugin\VoteGuard\Commands;

use Azuriom\Models\User;
use Azuriom\Plugin\VoteGuard\Detector;
use Azuriom\Plugin\VoteGuard\Models\Claim;
use Azuriom\Plugin\VoteGuard\Networks;
use Illuminate\Console\Command;

class ClaimsCommand extends Command
{
    protected $signature = 'voteguard:claims
                            {--hours=24 : Fenêtre en heures}
                            {--top=20 : Nombre de joueurs affichés}';

    protected $description = 'Résumé du journal des réclamations (n’écrit rien)';

    private const SIGNALS = ['no_session', 'no_click', 'too_fast', 'no_sec_fetch', 'ua_spoof', 'bot_ua', 'webdriver', 'datacenter', 'honeypot'];

    public function handle(Detector $detector): int
    {
        $hours = max(1, (int) $this->option('hours'));
        $top = max(1, (int) $this->option('top'));
        $since = now()->subHours($hours);

        $claims = Claim::query()->where('created_at', '>=', $since)->orderBy('id')->get();

        $this->info("fenetre={$hours}h depuis ".$since->format('d/m H:i').' reclamations='.$claims->count());

        if ($claims->isEmpty()) {
            return self::SUCCESS;
        }

        $names = User::query()
            ->whereIn('id', $claims->pluck('user_id')->filter()->unique()->values()->all())
            ->pluck('name', 'id');
        $name = fn ($id) => $id ? ($names[$id] ?? '#'.$id) : '?';
        $signals = fn (Claim $claim) => array_values(array_intersect(self::SIGNALS, $claim->flags ?? []));
        $summary = fn ($group) => $group
            ->flatMap(fn (Claim $claim) => $claim->flags ?? [])
            ->countBy()
            ->map(fn ($count, $flag) => $flag.':'.$count)
            ->implode(' ');

        $this->table(['resultat', 'n'], $claims->countBy('outcome')
            ->sortDesc()
            ->map(fn ($count, $outcome) => [$outcome, $count])
            ->values()
            ->all());

        $rewarded = $claims->filter(fn (Claim $claim) => in_array($claim->outcome, Claim::REWARDED, true))->values();
        $total = max(1, $rewarded->count());

        $this->line('attente moyenne avant succes (pending x5s)='.round((float) $rewarded->avg('pendings'), 1)
            .' | succes sans attente='.round($rewarded->where('pendings', 0)->count() / $total * 100).'%');

        $flagRows = [];

        foreach (array_merge(self::SIGNALS, ['guest']) as $flag) {
            $count = $rewarded->filter(fn (Claim $claim) => in_array($flag, $claim->flags ?? [], true))->count();
            $flagRows[] = [$flag, $count, round($count / $total * 100, 1).'%'];
        }

        $this->table(['signal (recompensees)', 'n', '%'], $flagRows);
        $this->line('disjoncteur 24h='.json_encode($detector->globalRatios()));

        $this->table(['asn', 'n', 'hebergeur'], $claims
            ->groupBy(fn (Claim $claim) => (string) ($claim->asn ?? '—'))
            ->map(fn ($group, $asn) => [$asn, $group->count(), Networks::isHosting(is_numeric($asn) ? (int) $asn : null) ? 'OUI' : ''])
            ->sortByDesc(fn ($row) => $row[1])
            ->take(12)
            ->values()
            ->all());

        $this->table(['pays', 'n'], $claims
            ->countBy(fn (Claim $claim) => $claim->country ?? '—')
            ->sortDesc()
            ->take(8)
            ->map(fn ($count, $country) => [$country, $count])
            ->values()
            ->all());

        $traps = $claims->filter(fn (Claim $claim) => in_array('honeypot', $claim->flags ?? [], true));

        if ($traps->isNotEmpty()) {
            $this->warn('LIEN PIEGE RECLAME');
            $this->table(['date', 'joueur', 'ip', 'ua'], $traps->map(fn (Claim $claim) => [
                $claim->created_at?->format('d/m H:i:s'),
                $name($claim->user_id),
                $claim->ip,
                mb_substr((string) $claim->user_agent, 0, 60),
            ])->values()->all());
        }

        $this->info('joueurs avec signaux navigateur / reseau');
        $this->table(['joueur', 'avec_signal', 'total', 'signaux', 'ips', 'ua'], $rewarded
            ->filter(fn (Claim $claim) => $signals($claim) !== [])
            ->groupBy('user_id')
            ->map(fn ($group, $userId) => [
                $name($userId),
                $group->count(),
                $rewarded->where('user_id', $userId)->count(),
                $group->flatMap($signals)->countBy()->map(fn ($count, $flag) => $flag.':'.$count)->implode(' '),
                $group->pluck('ip')->unique()->count(),
                mb_substr((string) $group->last()->user_agent, 0, 50),
            ])
            ->sortByDesc(fn ($row) => $row[1])
            ->take(30)
            ->values()
            ->all());

        $this->info('IP partagees par plusieurs comptes');
        $this->table(['ip', 'asn', 'comptes', 'noms'], $rewarded
            ->filter(fn (Claim $claim) => $claim->ip !== null)
            ->groupBy('ip')
            ->map(fn ($group, $ip) => [$ip, $group->first()->asn ?? '—', $group->pluck('user_id')->filter()->unique()])
            ->filter(fn ($row) => $row[2]->count() >= 2)
            ->map(fn ($row) => [$row[0], $row[1], $row[2]->count(), $row[2]->map($name)->implode(', ')])
            ->sortByDesc(fn ($row) => $row[2])
            ->take(20)
            ->values()
            ->all());

        $this->info("top {$top} reclamants");
        $this->table(['joueur', 'reclam', 'connecte%', 'attente_moy', 'ips', 'asn', 'signaux'], $rewarded
            ->groupBy('user_id')
            ->map(fn ($group, $userId) => [
                $name($userId),
                $group->count(),
                round($group->filter(fn (Claim $claim) => $claim->authenticated)->count() / $group->count() * 100).'%',
                round((float) $group->avg('pendings'), 1),
                $group->pluck('ip')->unique()->count(),
                $group->pluck('asn')->filter()->unique()->implode(','),
                $summary($group) ?: '—',
            ])
            ->sortByDesc(fn ($row) => $row[1])
            ->take($top)
            ->values()
            ->all());

        return self::SUCCESS;
    }
}
