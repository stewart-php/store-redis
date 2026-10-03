<?php

declare(strict_types=1);

namespace Stewart\Store\Redis\Tests\Persistence;

use PHPUnit\Framework\Attributes\CoversClass;
use Stewart\Contracts\Exception\StoreError;
use Stewart\Contracts\Exception\StoreException;
use Stewart\Contracts\Time\Duration;
use Stewart\Store\Exception\StoreSetupError;
use Stewart\Store\Redis\RedisStoreBackend;
use Stewart\Store\Redis\RedisStoreBackendFactory;
use Stewart\Store\StoreBackend;
use Stewart\Store\StoreDsn;
use Stewart\Store\StoreTiming;
use Stewart\Support\Time\RevoltTimers;
use Stewart\Testing\Exception\AssertsReason;
use Stewart\Testing\Store\StoreBackendContract;

use function Amp\delay;
use function Amp\Redis\createRedisClient;

#[CoversClass(RedisStoreBackend::class)]
#[CoversClass(RedisStoreBackendFactory::class)]
final class RedisStoreBackendTest extends StoreBackendContract
{
    use AssertsReason;

    private const string DEAD_URL = 'redis://127.0.0.1:6399';

    private const float TIMEOUT_SECONDS = 2.0;

    private string $namespace;

    protected function tearDown(): void
    {
        $this->backendUnderTest->removeByPrefix($this->namespace);
    }

    public function testEachRunKeepsToItsOwnCornerOfTheKeyspace(): void
    {
        self::assertTrue(str_starts_with($this->getPrefix(), $this->namespace));
        self::assertTrue(str_starts_with($this->getOtherPrefix(), $this->namespace));
    }

    public function testEngineThatIsNotThereFailsWithinTheTimeout(): void
    {
        $backend = $this->createBackendAt(self::DEAD_URL);
        $started = microtime(true);

        try {
            $backend->read('stewart:global:mode');
            self::fail('Nothing is listening on that port.');
        } catch (StoreException $e) {
            self::assertContains($e->reason, [StoreError::Unreachable, StoreError::TimedOut], 'The guard pauses on either reason.');
            self::assertStringContainsString('127.0.0.1:6399', $e->getMessage());
        }

        self::assertLessThan(self::TIMEOUT_SECONDS + 1.0, microtime(true) - $started);
    }

    public function testPrefixAcrossManyScanPagesIsListedAndRemoved(): void
    {
        $keys = array_map(fn(int $n): string => $this->getPrefix() . 'bulk:' . $n, range(1, 1200));

        foreach ($keys as $key) {
            $this->backendUnderTest->write($key, '1', null);
        }

        $listed = $this->backendUnderTest->keysWithPrefix($this->getPrefix());
        sort($listed);
        sort($keys);
        self::assertSame($keys, $listed);

        $this->backendUnderTest->removeByPrefix($this->getPrefix());

        self::assertSame([], $this->backendUnderTest->keysWithPrefix($this->getPrefix()));
    }

    public function testRefusedQueryKeepsBackendAvailable(): void
    {
        $key = $this->getPrefix() . 'queue';
        createRedisClient($this->getRedisUrl())->getList($key)->pushTail('job');

        try {
            $this->backendUnderTest->read($key);
            self::fail('A list cannot be read as a string.');
        } catch (StoreException $e) {
            self::assertSame(StoreError::Refused, $e->reason);
            self::assertStringContainsString('wrong kind of value', $e->getMessage());
        }

        self::assertNull($this->backendUnderTest->read($this->getPrefix() . 'absent'));
    }

    public function testOnlyRedisUrlsAreAccepted(): void
    {
        $this->assertThrowsReason(StoreSetupError::SchemeUnsupported, fn() => new RedisStoreBackendFactory(new RevoltTimers())->createBackend(StoreDsn::parse('sqlite://var/store.db'), new StoreTiming(Duration::seconds(1), Duration::seconds(1))));
    }

    public function testRedisUrlWithoutHostIsRefused(): void
    {
        $this->assertThrowsReason(StoreSetupError::DsnInvalid, fn() => new RedisStoreBackendFactory(new RevoltTimers())->createBackend(StoreDsn::parse('redis:///0'), new StoreTiming(Duration::seconds(1), Duration::seconds(1))));
    }

    protected function createBackend(): StoreBackend
    {
        $this->namespace = 'stewart-test:' . bin2hex(random_bytes(6)) . ':';

        return $this->createBackendAt($this->getRedisUrl());
    }

    protected function advanceTime(Duration $span): void
    {
        delay($span->toSeconds());
    }

    protected function getPrefix(): string
    {
        return $this->namespace . 'app:heating:';
    }

    protected function getOtherPrefix(): string
    {
        return $this->namespace . 'global:';
    }

    private function createBackendAt(string $url): StoreBackend
    {
        return new RedisStoreBackendFactory(new RevoltTimers())->createBackend(
            StoreDsn::parse($url),
            new StoreTiming(Duration::seconds(self::TIMEOUT_SECONDS), Duration::seconds(self::TIMEOUT_SECONDS)),
        );
    }

    private function getRedisUrl(): string
    {
        $url = getenv('TEST_STORE_URL');

        return $url === false || $url === '' ? 'redis://valkey:6379/15' : $url;
    }
}
