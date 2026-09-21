<?php

namespace Azuriom\Plugin\VoteGuard\Observers;

use Azuriom\Plugin\Vote\Models\Vote;
use Azuriom\Plugin\VoteGuard\Detector;
use Throwable;

class VoteObserver
{
    public function created(Vote $vote): void
    {
        try {
            app(Detector::class)->handleLive($vote);
        } catch (Throwable) {
            // Ne jamais casser la récompense vote si l’analyse échoue.
        }
    }
}
