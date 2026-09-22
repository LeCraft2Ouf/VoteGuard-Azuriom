<?php

namespace Azuriom\Plugin\VoteGuard\Observers;

use Azuriom\Models\User;
use Azuriom\Plugin\Vote\Models\Vote;
use Azuriom\Plugin\VoteGuard\Detector;
use Azuriom\Plugin\VoteGuard\RewardGuard;
use Azuriom\Plugin\VoteGuard\VoteContext;
use Throwable;

class VoteObserver
{
    public function creating(Vote $vote): bool
    {
        try {
            $user = $vote->user_id ? User::find($vote->user_id) : null;

            if (app(RewardGuard::class)->denyIfBlocked($user)) {
                return false;
            }
        } catch (Throwable) {
            return true;
        }

        return true;
    }

    public function created(Vote $vote): void
    {
        try {
            app(VoteContext::class)->voteId = (int) $vote->id;
        } catch (Throwable) {
            //
        }

        app()->terminating(function () use ($vote) {
            try {
                app(Detector::class)->handleLive($vote);
            } catch (Throwable) {
                // Ne jamais casser le vote si l'analyse échoue.
            }
        });
    }
}
