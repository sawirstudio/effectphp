<?php

declare(strict_types=1);

namespace EffectPHP\Context;

/**
 * Service tag for dependency injection.
 *
 * @template T The service type
 */
final class Tag
{
    private static int $counter = 0;

    /**
     * @param string $key
     * @param class-string<T>|null $type
     */
    public function __construct(
        public readonly string $key,
        public readonly ?string $type = null,
    ) {}

    /**
     * Create a tag for a service type.
     *
     * @template S of object
     * @param class-string<S> $type
     * @return Tag<S>
     */
    public static function of(string $type): self
    {
        return new self($type, $type);
    }

    /**
     * Create a unique tag with a name.
     *
     * @template S
     * @param string $name
     * @return Tag<S>
     */
    public static function make(string $name): self
    {
        return new self($name . '_' . ++self::$counter);
    }
}
