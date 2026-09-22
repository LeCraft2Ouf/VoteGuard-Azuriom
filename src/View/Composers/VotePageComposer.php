<?php

namespace Azuriom\Plugin\VoteGuard\View\Composers;

use Azuriom\Plugin\VoteGuard\Honeypot;
use Illuminate\View\View;
use Throwable;

class VotePageComposer
{
    public function compose(View $view): void
    {
        try {
            $script = @file_get_contents(plugin_path('voteguard/assets/js/guard.js'));
            $honeypot = app(Honeypot::class);

            $view->getFactory()->startPush(
                'scripts',
                view('voteguard::partials.script', [
                    'script' => is_string($script) ? $script : '',
                    'trapId' => $honeypot->siteId(),
                    'trapUrl' => $honeypot->url(),
                ])->render()
            );
        } catch (Throwable) {
            // Ne jamais casser la page vote.
        }
    }
}
