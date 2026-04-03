<?php

declare(strict_types=1);

namespace MuhCleanMigrator\Core;

/**
 * Reads and validates plugin configuration from constants.
 */
final class Config
{
    private Logger $logger;

    /**
     * @var array<string, string>
     */
    private array $overrides;

    /**
     * @param array<string, string> $overrides Optional config overrides for tests.
     */
    public function __construct(Logger $logger, array $overrides = [])
    {
        $this->logger    = $logger;
        $this->overrides = $overrides;
    }

    /**
     * Returns validated remote store credentials.
     *
     * @return array{old_url:string,old_key:string,old_secret:string}
     */
    public function get(): array
    {
        $config = [
            'old_url'    => $this->overrides['old_url'] ?? (defined('MUH_OLD_STORE_URL') ? (string) MUH_OLD_STORE_URL : ''),
            'old_key'    => $this->overrides['old_key'] ?? (defined('MUH_OLD_CONSUMER_KEY') ? (string) MUH_OLD_CONSUMER_KEY : ''),
            'old_secret' => $this->overrides['old_secret'] ?? (defined('MUH_OLD_CONSUMER_SECRET') ? (string) MUH_OLD_CONSUMER_SECRET : ''),
        ];

        foreach ($config as $key => $value) {
            if ($value === '') {
                $this->logger->error("Missing config: {$key}. Define it in wp-config.php");
                throw new \RuntimeException("Missing config: {$key}");
            }
        }

        return $config;
    }
}
