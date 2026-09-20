<?php

namespace Azuriom\Plugin\VoteGuard\View\Composers;

use Illuminate\Support\Facades\View as ViewFacade;
use Illuminate\View\View;

class VotePageComposer
{
    public function compose(View $view): void
    {
        ViewFacade::startPush('scripts', view('voteguard::partials.script')->render());
        ViewFacade::stopPush();
    }
}
