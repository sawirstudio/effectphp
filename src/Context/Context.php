<?php

declare(strict_types=1);

namespace EffectPHP\Context;

/**
 * Runtime context containing service instances.
 *
 * @template-covariant R Service types available
 */
final class Context
{
    /**
     * @param array<string, mixed> $services
     */
    public function __construct(
        private readonly array $services = [],
    ) {}

    /**
     * Add a service to the context.
     *
     * @template S
     * @param Tag<S> $tag
     * @param S $service
     * @return Context<R|S>
     */
    public function add(Tag $tag, mixed $service): self
    {
        return new self([...$this->services, $tag->key => $service]);
    }

    /**
     * Get a service from the context.
     *
     * @template S
     * @param Tag<S> $tag
     * @return S
     * @throws \RuntimeException If service not found
     */
    public function get(Tag $tag): mixed
    {
        if (!isset($this->services[$tag->key])) {
            throw new \RuntimeException("Service not found: {$tag->key}");
        }
        return $this->services[$tag->key];
    }

    /**
     * Check if a service exists.
     *
     * @param Tag<mixed> $tag
     */
    public function has(Tag $tag): bool
    {
        return isset($this->services[$tag->key]);
    }

    /**
     * Merge another context.
     *
     * @template R2
     * @param Context<R2> $other
     * @return Context<R|R2>
     */
    public function merge(self $other): self
    {
        return new self([...$this->services, ...$other->services]);
    }

    /**
     * Create empty context.
     *
     * @return Context<never>
     */
    public static function empty(): self
    {
        return new self([]);
    }
}
