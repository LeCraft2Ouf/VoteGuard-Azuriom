<?php

namespace Azuriom\Plugin\VoteGuard\View\Composers;

use Illuminate\View\View;
use Throwable;

class VotePageComposer
{
    public function compose(View $view): void
    {
        try {
            $view->getFactory()->startPush(
                'scripts',
                view('voteguard::partials.script')->render()
            );
        } catch (Throwable) {
            // Ne jamais casser la page vote.
        }
    }
}
