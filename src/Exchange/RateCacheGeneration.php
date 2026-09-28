<?php

declare(strict_types=1);

namespace RoundlyConsulting\Money\Exchange;

use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Database\Connection;
use Illuminate\Support\Str;

/**
 * The stamp every cached rate lookup is keyed under. A lookup depends on more rows than its
 * own pair (inverse rows, pivot legs, every later date), so a write cannot name the keys it
 * affects — instead every write replaces the stamp and all cached lookups miss once. The
 * orphaned entries expire by TTL.
 *
 * @internal
 */
final readonly class RateCacheGeneration
{
    public function __construct(private Repository $cache, private string $prefix) {}

    /** The generation the exchange manager's cached drivers use; null when caching is off. */
    public static function configured(CacheFactory $caches): ?self
    {
        if (config('money.exchange.cache.enabled') === false) {
            return null;
        }

        $store = config('money.exchange.cache.store');

        return new self(
            $caches->store(is_string($store) && $store !== '' ? $store : null),
            (string) config('money.exchange.cache.prefix', 'money:exchange'),
        );
    }

    public function current(): string
    {
        $current = $this->cache->get($this->key());

        if (is_string($current) && $current !== '') {
            return $current;
        }

        // Two processes starting a generation at once only cost each other one miss.
        $current = self::token();
        $this->cache->forever($this->key(), $current);

        return $current;
    }

    public function bump(): void
    {
        $this->cache->forever($this->key(), self::token());
    }

    /**
     * Bump now — so the writer's own transaction reads its rows — and again after the
     * surrounding transaction commits, since another process may meanwhile have cached the
     * still-committed rows under the first bump.
     */
    public function bumpAfterWrite(Connection $connection): void
    {
        $this->bump();

        if ($connection->transactionLevel() > 0) {
            $connection->afterCommit(fn () => $this->bump());
        }
    }

    private function key(): string
    {
        return $this->prefix.':generation';
    }

    private static function token(): string
    {
        return Str::random(16);
    }
}
