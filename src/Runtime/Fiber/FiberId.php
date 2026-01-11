<?php

declare(strict_types=1);

namespace EffectPHP\Runtime\Fiber;

/**
 * Unique identifier for a fiber.
 */
final class FiberId
{
    private static int $counter = 0;

    private function __construct(
        public readonly int $id,
        public readonly float $startTime,
    ) {}

    public static function generate(): self
    {
        return new self(++self::$counter, microtime(true));
    }

    public static function none(): self
    {
        return new self(0, 0);
    }

    public function equals(self $other): bool
    {
        return $this->id === $other->id;
    }

    public function __toString(): string
    {
        return "Fiber#{$this->id}";
    }
}
