<?php

declare(strict_types=1);

namespace EffectPHP\Runtime\Fiber;

use Closure;
use EffectPHP\Context\Context;

/**
 * Fiber execution context.
 *
 * @template R
 */
final class FiberContext
{
    private bool $interrupted = false;

    /** @var array<int, Closure(): void> */
    private array $finalizers = [];

    /**
     * @param FiberId $fiberId
     * @param Context<R> $context
     */
    public function __construct(
        public readonly FiberId $fiberId,
        public readonly Context $context,
    ) {}

    public function isInterrupted(): bool
    {
        return $this->interrupted;
    }

    public function interrupt(): void
    {
        $this->interrupted = true;
    }

    /**
     * @param Closure(): void $finalizer
     */
    public function addFinalizer(Closure $finalizer): void
    {
        $this->finalizers[] = $finalizer;
    }

    public function runFinalizers(): void
    {
        foreach (array_reverse($this->finalizers) as $finalizer) {
            try {
                $finalizer();
            } catch (\Throwable) {
                // Finalizers should not throw
            }
        }
        $this->finalizers = [];
    }

    /**
     * @template R2
     * @param Context<R2> $context
     * @return FiberContext<R|R2>
     */
    public function withContext(Context $context): self
    {
        $new = new self($this->fiberId, $this->context->merge($context));
        $new->interrupted = $this->interrupted;
        $new->finalizers = $this->finalizers;
        return $new;
    }
}
