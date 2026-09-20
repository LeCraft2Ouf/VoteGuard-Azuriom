<?php

namespace Azuriom\Plugin\VoteGuard\Commands;

use Azuriom\Plugin\VoteGuard\Detector;
use Illuminate\Console\Command;

class ScanVotesCommand extends Command
{
    protected $signature = 'voteguard:scan
                            {--days=60 : Nombre de jours d\'historique}
                            {--limit=0 : Limite de joueurs (0 = tous)}';

    protected $description = 'Analyse l\'historique des votes pour détecter les bots auto-vote';

    public function handle(Detector $detector): int
    {
        $days = max(1, (int) $this->option('days'));
        $limit = max(0, (int) $this->option('limit'));

        $this->info("Analyse des votes sur {$days} jours...");

        $result = $detector->scanRecent($days, $limit);

        $this->info("Joueurs analysés : {$result['scanned']}");
        $this->info("Signalés : {$result['flagged']}");

        return self::SUCCESS;
    }
}
