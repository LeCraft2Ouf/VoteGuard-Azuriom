<?php

namespace Azuriom\Plugin\VoteGuard\View\Composers;

use Azuriom\Plugin\VoteGuard\Blocklist;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Throwable;

class VotePageComposer
{
    public function compose(View $view): void
    {
        try {
            $user = Auth::user();
            $blocked = $user !== null && app(Blocklist::class)->isBlocked($user);

            $view->getFactory()->startPush(
                'scripts',
                view('voteguard::partials.script', [
                    'blocked' => $blocked,
                    'blockedMessage' => trans('voteguard::messages.blocked'),
                ])->render()
            );
        } catch (Throwable) {
            // Ne jamais casser la page vote.
        }
    }
}
