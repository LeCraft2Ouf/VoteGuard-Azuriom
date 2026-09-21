<?php

namespace Azuriom\Plugin\VoteGuard;

use Azuriom\Models\User;
use Azuriom\Plugin\Vote\Models\Reward;
use Azuriom\Plugin\Vote\Models\Site;
use Azuriom\Support\SettingsRepository;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class RewardGuard
{
    private mixed $savedCommands = false;

    private mixed $savedGoalCommands = false;

    public function __construct(
        private VoteContext $context,
        private Blocklist $blocklist,
    ) {}

    public function denyIfBlocked(?User $user): bool
    {
        try {
            if ($user === null || ! $this->blocklist->isBlocked($user)) {
                return false;
            }
        } catch (Throwable) {
            return false;
        }

        $this->deny();

        return true;
    }

    public function deny(): void
    {
        if (! $this->context->skipReward) {
            try {
                $this->savedCommands = setting('vote.commands');
                $this->savedGoalCommands = setting('vote.goal.commands');
            } catch (Throwable) {
                $this->savedCommands = false;
                $this->savedGoalCommands = false;
            }
        }

        $this->context->skipReward = true;

        try {
            $settings = app(SettingsRepository::class);
            $settings->set('vote.commands', null);
            $settings->set('vote.goal.commands', null);
        } catch (Throwable) {
            //
        }

        $this->stripBoundRewards();
    }

    public function stripReward(Reward $reward): void
    {
        if (! $this->context->skipReward) {
            return;
        }

        $reward->money = 0;
        $reward->commands = [];
        $reward->monthly_rewards = [];
    }

    public function rewriteResponse(Response $response): Response
    {
        try {
            if (! $this->context->skipReward || $response->getStatusCode() !== 200) {
                return $response;
            }

            $payload = $this->jsonPayload($response);

            if ($payload === null) {
                return $response;
            }

            $status = $payload['status'] ?? null;

            if ($status === 'pending' || $status === 'select_server') {
                return $response;
            }

            if (! isset($payload['message'])) {
                return $response;
            }

            $payload['message'] = trans('voteguard::messages.no_reward');

            if ($response instanceof JsonResponse) {
                $response->setData($payload);
            } else {
                $response->setContent(json_encode($payload));
            }
        } catch (Throwable) {
            //
        } finally {
            $this->restoreSettings();
        }

        return $response;
    }

    private function restoreSettings(): void
    {
        if ($this->savedCommands === false && $this->savedGoalCommands === false) {
            return;
        }

        try {
            $settings = app(SettingsRepository::class);

            if ($this->savedCommands !== false) {
                $settings->set('vote.commands', $this->savedCommands);
            }

            if ($this->savedGoalCommands !== false) {
                $settings->set('vote.goal.commands', $this->savedGoalCommands);
            }
        } catch (Throwable) {
            //
        }
    }

    private function stripBoundRewards(): void
    {
        try {
            $site = request()->route('site');
        } catch (Throwable) {
            return;
        }

        if (! $site instanceof Site || ! $site->relationLoaded('rewards')) {
            return;
        }

        foreach ($site->rewards as $reward) {
            if ($reward instanceof Reward) {
                $this->stripReward($reward);
            }
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function jsonPayload(Response $response): ?array
    {
        if ($response instanceof JsonResponse) {
            $data = $response->getData(true);

            return is_array($data) ? $data : null;
        }

        $content = $response->getContent();

        if (! is_string($content) || $content === '') {
            return null;
        }

        $data = json_decode($content, true);

        return is_array($data) ? $data : null;
    }
}
