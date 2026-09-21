<?php

namespace Azuriom\Plugin\VoteGuard;

class Settings
{
    public function enabled(): bool
    {
        return $this->bool('voteguard.enabled', true);
    }

    public function watchScore(): int
    {
        return $this->int('voteguard.watch', 30);
    }

    public function suspectScore(): int
    {
        return $this->int('voteguard.suspect', 50);
    }

    public function likelyScore(): int
    {
        return $this->int('voteguard.likely', 80);
    }

    public function minVotes(): int
    {
        return max(4, $this->int('voteguard.min_votes', 6));
    }

    public function maxStddev(): int
    {
        return max(5, $this->int('voteguard.stddev', 300));
    }

    public function sniperSeconds(): int
    {
        return max(5, $this->int('voteguard.sniper', 180));
    }

    public function ipFarmUsers(): int
    {
        return max(2, $this->int('voteguard.ip_farm', 4));
    }

    public function webhook(): ?string
    {
        $url = trim((string) setting('voteguard.webhook', ''));

        return $url !== '' ? $url : null;
    }

    public function isWhitelisted(string $name): bool
    {
        return in_array(strtolower($name), $this->whitelist(), true);
    }

    /**
     * @return array<int, string>
     */
    public function blocklist(): array
    {
        return $this->nameList('voteguard.blocklist');
    }

    public function isBlocklisted(string $name): bool
    {
        return in_array(strtolower($name), $this->blocklist(), true);
    }

    /**
     * @return array<int, string>
     */
    public function whitelist(): array
    {
        return $this->nameList('voteguard.whitelist');
    }

    /**
     * @return array<int, string>
     */
    private function nameList(string $key): array
    {
        $raw = setting($key, '[]');

        if (is_array($raw)) {
            return array_values(array_filter(array_map(static fn ($name) => strtolower(trim((string) $name)), $raw)));
        }

        $decoded = json_decode((string) $raw, true);

        if (! is_array($decoded)) {
            return [];
        }

        return array_values(array_filter(array_map(static fn ($name) => strtolower(trim((string) $name)), $decoded)));
    }

    private function bool(string $key, bool $default): bool
    {
        $value = setting($key, $default);

        if (is_bool($value)) {
            return $value;
        }

        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    private function int(string $key, int $default): int
    {
        return (int) setting($key, $default);
    }
}
