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

            if (app(Blocklist::class)->isBlocked($user)) {
                throw new \RuntimeException('VoteGuard: vote blocked');
            }
        } catch (\RuntimeException $e) {
            throw $e;
        } catch (Throwable) {
            // Ne jamais bloquer un vote légitime si le check plante.
        }
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
