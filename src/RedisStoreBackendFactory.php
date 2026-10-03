<?php

declare(strict_types=1);

namespace Stewart\Store\Redis;

use Amp\Redis\RedisConfig;
use Amp\Redis\RedisException;
use Stewart\Store\Exception\StoreSetupException;
use Stewart\Store\StoreBackend;
use Stewart\Store\StoreBackendFactory;
use Stewart\Store\StoreDsn;
use Stewart\Store\StoreTiming;
use Stewart\Support\Time\Deadlines;

use function Amp\Redis\createRedisClient;

final readonly class RedisStoreBackendFactory implements StoreBackendFactory
{
    private const string TCP_SCHEME = 'redis';

    private const string SOCKET_SCHEME = 'unix';

    public function __construct(private Deadlines $deadlines) {}

    public function listSchemes(): array
    {
        return [self::TCP_SCHEME, self::SOCKET_SCHEME];
    }

    public function createBackend(StoreDsn $dsn, StoreTiming $timing): StoreBackend
    {
        if (!\in_array($dsn->scheme, $this->listSchemes(), true)) {
            throw StoreSetupException::schemeUnsupported($dsn->scheme, $this->listSchemes());
        }

        if ($dsn->scheme === self::TCP_SCHEME && !\is_string(parse_url($dsn->reveal(), \PHP_URL_HOST))) {
            throw StoreSetupException::dsnInvalid($dsn->reveal());
        }

        try {
            $config = RedisConfig::fromUri($dsn->reveal(), $timing->timeout->toSeconds());
        } catch (RedisException) {
            // Not chained: amphp's message contains the URI and its password.
            throw StoreSetupException::dsnInvalid($dsn->reveal());
        }

        return new RedisStoreBackend(createRedisClient($config), $dsn, $timing, $this->deadlines);
    }
}
