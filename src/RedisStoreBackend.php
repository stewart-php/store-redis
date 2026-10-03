<?php

declare(strict_types=1);

namespace Stewart\Store\Redis;

use Amp\CancelledException;
use Amp\Redis\Command\Option\SetOptions;
use Amp\Redis\Protocol\QueryException;
use Amp\Redis\RedisClient;
use Amp\Redis\RedisException;
use Closure;
use Generator;
use Stewart\Contracts\Exception\StoreException;
use Stewart\Contracts\Time\Duration;
use Stewart\Store\StoreBackend;
use Stewart\Store\StoreDsn;
use Stewart\Store\StoreTiming;
use Stewart\Support\Time\Deadlines;

use function Amp\async;

final class RedisStoreBackend implements StoreBackend
{
    private const int BATCH = 500;

    private const string NOT_A_NUMBER = '/not an integer|overflow|WRONGTYPE/i';

    public function __construct(
        private readonly RedisClient $client,
        private readonly StoreDsn $dsn,
        private readonly StoreTiming $timing,
        private readonly Deadlines $deadlines,
    ) {}

    public function read(string $key): ?string
    {
        return $this->runWithTimeout(fn(): ?string => $this->client->get($key));
    }

    public function write(string $key, string $value, ?Duration $ttl): void
    {
        $options = $ttl === null ? null : new SetOptions()->withTtlInMillis($ttl->toMilliseconds());

        $this->runWithTimeout(fn(): bool => $this->client->set($key, $value, $options));
    }

    public function remove(string $key): void
    {
        $this->runWithTimeout(fn(): int => $this->client->delete($key));
    }

    public function exists(string $key): bool
    {
        return $this->runWithTimeout(fn(): bool => $this->client->has($key));
    }

    public function increment(string $key, int $by): int
    {
        return $this->runWithTimeout(function () use ($key, $by): int {
            try {
                return $this->client->increment($key, $by);
            } catch (QueryException $e) {
                if (preg_match(self::NOT_A_NUMBER, $e->getMessage()) !== 1) {
                    throw $e;
                }

                throw StoreException::valueNotIncrementable(self::withoutErrorCode($e->getMessage()), $e);
            }
        });
    }

    public function keysWithPrefix(string $prefix): array
    {
        $found = [];

        foreach ($this->scanKeyPages($prefix) as $page) {
            foreach ($page->keys as $key) {
                // SCAN can return a key more than once across pages.
                $found[$key] = true;
            }
        }

        return array_keys($found);
    }

    public function removeByPrefix(string $prefix): void
    {
        foreach ($this->scanKeyPages($prefix) as $page) {
            if ($page->keys !== []) {
                $this->runWithTimeout(fn(): mixed => $this->client->execute('UNLINK', ...$page->keys));
            }
        }
    }

    public function probe(): void
    {
        $this->runWithTimeout(function (): null {
            $this->client->ping();

            return null;
        });
    }

    /**
     * @template T
     *
     * @param Closure(): T $operation
     * @return T
     * @throws StoreException
     */
    private function runWithTimeout(Closure $operation): mixed
    {
        $target = (string) $this->dsn;
        $pending = async($operation);

        try {
            return $pending->await($this->deadlines->timeout($this->timing->timeout));
        } catch (CancelledException $e) {
            // The command still runs; its reply is discarded when it arrives.
            $pending->ignore();

            throw StoreException::timedOut($target, $this->timing->timeout, $e);
        } catch (QueryException $e) {
            throw StoreException::refused($target, self::withoutErrorCode($e->getMessage()), $e);
        } catch (RedisException $e) {
            throw StoreException::unreachable($target, $e->getMessage(), $e);
        }
    }

    /**
     * @return Generator<int, ScanPage>
     * @throws StoreException
     */
    private function scanKeyPages(string $prefix): Generator
    {
        $pattern = self::escapeGlobPattern($prefix) . '*';
        $cursor = '0';

        do {
            // Timeout per page: a large keyspace is slow, not unhealthy.
            $page = $this->runWithTimeout(fn(): ScanPage => ScanPage::fromReply(
                $this->client->execute('SCAN', $cursor, 'MATCH', $pattern, 'COUNT', self::BATCH),
            ));
            $cursor = $page->cursor;

            yield $page;
        } while (!$page->isLast());
    }

    // SCAN MATCH reads the prefix as a glob, so its glob characters are escaped.
    private static function escapeGlobPattern(string $literal): string
    {
        return str_replace(['\\', '*', '?', '[', ']'], ['\\\\', '\*', '\?', '\[', '\]'], $literal);
    }

    private static function withoutErrorCode(string $message): string
    {
        return preg_replace('/^(ERR|WRONGTYPE) /', '', $message) ?? $message;
    }
}
