<?php

namespace Azuriom\Plugin\VoteGuard\Observers;

use Azuriom\Models\User;
use Azuriom\Plugin\Vote\Models\Vote;
use Azuriom\Plugin\VoteGuard\Detector;
use Azuriom\Plugin\VoteGuard\RewardGuard;
use Throwable;

class VoteObserver
{
    public function created(Vote $vote): void
    {
        try {
            $user = $vote->user_id ? User::find($vote->user_id) : null;
            app(RewardGuard::class)->denyIfBlocked($user);
        } catch (Throwable) {
            //
        }

        try {
            app(Detector::class)->handleLive($vote);
        } catch (Throwable) {
            // Ne jamais casser le vote si l’analyse échoue.
        }
    }
}
