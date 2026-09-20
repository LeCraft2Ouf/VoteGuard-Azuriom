<?php

namespace Azuriom\Plugin\VoteGuard\Providers;

use Azuriom\Extensions\Plugin\BasePluginServiceProvider;
use Azuriom\Models\ActionLog;
use Azuriom\Models\Permission;
use Azuriom\Plugin\Vote\Models\Vote;
use Azuriom\Plugin\VoteGuard\Commands\ScanVotesCommand;
use Azuriom\Plugin\VoteGuard\Detector;
use Azuriom\Plugin\VoteGuard\Middleware\CaptureVoteRequest;
use Azuriom\Plugin\VoteGuard\Observers\VoteObserver;
use Azuriom\Plugin\VoteGuard\Settings;
use Azuriom\Plugin\VoteGuard\View\Composers\VotePageComposer;
use Azuriom\Plugin\VoteGuard\VoteContext;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\View;

class VoteGuardServiceProvider extends BasePluginServiceProvider
{
    /**
     * The plugin's global HTTP middleware stack.
     *
     * @var array<int, class-string>
     */
    protected array $middleware = [
        CaptureVoteRequest::class,
    ];

    /**
     * Register any plugin services.
     */
    public function register(): void
    {
        $this->registerMiddlewares();

        $this->app->singleton(VoteContext::class);
        $this->app->singleton(Settings::class);
        $this->app->singleton(Detector::class);
    }

    /**
     * Bootstrap any plugin services.
     */
    public function boot(): void
    {
        $this->loadViews();

        $this->loadTranslations();

        $this->loadMigrations();

        $this->registerAdminNavigation();

        $this->commands([
            ScanVotesCommand::class,
        ]);

        Permission::registerPermissions([
            'voteguard.manage' => 'voteguard::admin.permissions.manage',
        ]);

        ActionLog::registerLogs([
            'voteguard.settings' => [
                'icon' => 'shield-check',
                'color' => 'info',
                'message' => 'voteguard::admin.logs.settings',
            ],
            'voteguard.scan' => [
                'icon' => 'search',
                'color' => 'warning',
                'message' => 'voteguard::admin.logs.scan',
            ],
        ]);

        if (class_exists(Vote::class)) {
            Vote::observe(VoteObserver::class);

            View::composer('vote::index', VotePageComposer::class);
        }

        if (method_exists($this, 'registerSchedule')) {
            $this->registerSchedule();
        }
    }

    protected function schedule(Schedule $schedule): void
    {
        $schedule->command('voteguard:scan --days=14 --limit=150')->dailyAt('04:20');
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    protected function adminNavigation(): array
    {
        return [
            'voteguard' => [
                'name' => trans('voteguard::admin.nav.title'),
                'type' => 'dropdown',
                'icon' => 'bi bi-shield-exclamation',
                'route' => 'voteguard.admin.*',
                'permission' => 'voteguard.manage',
                'items' => [
                    'voteguard.admin.index' => trans('voteguard::admin.nav.suspects'),
                    'voteguard.admin.settings' => trans('voteguard::admin.nav.settings'),
                ],
            ],
        ];
    }
}
