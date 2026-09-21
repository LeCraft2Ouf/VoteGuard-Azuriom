<?php

namespace Azuriom\Plugin\VoteGuard\Observers;

use Azuriom\Models\User;
use Azuriom\Plugin\Vote\Models\Vote;
use Azuriom\Plugin\VoteGuard\Blocklist;
use Azuriom\Plugin\VoteGuard\Detector;
use Throwable;

class VoteObserver
{
    public function creating(Vote $vote): void
    {
        try {
            $user = $vote->user_id ? User::find($vote->user_id) : null;

            if (! app(Blocklist::class)->isBlocked($user)) {
                return;
            }
        } catch (Throwable) {
            return;
        }

        abort(403, trans('voteguard::messages.blocked'));
    }

    public function created(Vote $vote): void
    {
        try {
            app(Detector::class)->handleLive($vote);
        } catch (Throwable) {
            // Ne jamais casser la récompense vote si l’analyse échoue.
        }
    }
}
