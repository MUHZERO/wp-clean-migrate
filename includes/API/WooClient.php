<?php

declare(strict_types=1);

namespace MuhCleanMigrator\API;

use MuhCleanMigrator\Core\Logger;

/**
 * Client for the old WooCommerce REST API.
 */
final class WooClient
{
    private const API_VERSION = 'wc/v3';

    /**
     * @var array{old_url:string,old_key:string,old_secret:string}
     */
    private array $config;

    private Logger $logger;

    /**
     * @param array{old_url:string,old_key:string,old_secret:string} $config Config values.
     */
    public function __construct(array $config, Logger $logger)
    {
        $this->config = $config;
        $this->logger = $logger;
    }

    /**
     * Fetches a collection from the old WooCommerce API.
     *
     * @param array<string, scalar> $query Query args.
     * @return array<int, array<string, mixed>>
     */
    public function get(string $endpoint, array $query = []): array
    {
        $url = trailingslashit($this->config['old_url']) . 'wp-json/' . self::API_VERSION . '/' . ltrim($endpoint, '/');
        $url = add_query_arg($query, $url);

        $response = wp_remote_get($url, [
            'timeout' => 90,
            'headers' => [
                'Authorization' => 'Basic ' . base64_encode($this->config['old_key'] . ':' . $this->config['old_secret']),
                'Accept'        => 'application/json',
            ],
        ]);

        if (is_wp_error($response)) {
            $this->logger->error('API request failed: ' . $response->get_error_message());
            throw new \RuntimeException('WooCommerce API request failed.');
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if ($code < 200 || $code >= 300) {
            $this->logger->error("API request returned HTTP {$code}: {$body}");
            throw new \RuntimeException("WooCommerce API returned HTTP {$code}.");
        }

        if (!is_array($data)) {
            $this->logger->error('Invalid API JSON response.');
            throw new \RuntimeException('WooCommerce API returned invalid JSON.');
        }

        return $data;
    }
}
