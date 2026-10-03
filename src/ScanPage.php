<?php

declare(strict_types=1);

namespace Stewart\Store\Redis;

final readonly class ScanPage
{
    /** @param list<string> $keys */
    public function __construct(
        public string $cursor,
        public array $keys,
    ) {}

    public function isLast(): bool
    {
        return $this->cursor === '0';
    }

    public static function fromReply(mixed $reply): self
    {
        /** @var array{string, list<string>} $reply */
        return new self($reply[0], $reply[1]);
    }
}
