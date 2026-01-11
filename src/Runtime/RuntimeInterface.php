<?php

declare(strict_types=1);

namespace EffectPHP\Runtime;

use EffectPHP\Context\Context;
use EffectPHP\Effect\Effect;
use EffectPHP\Exit\Exit_;

/**
 * Runtime executes Effects and manages their lifecycle.
 *
 * @template R Default context type
 */
interface RuntimeInterface
{
    /**
     * Run an Effect synchronously, throwing on failure.
     *
     * @template E
     * @template A
     * @param Effect<R, E, A> $effect
     * @return A
     * @throws \Throwable
     */
    public function runSync(Effect $effect): mixed;

    /**
     * Run an Effect and return the Exit value.
     *
     * @template E
     * @template A
     * @param Effect<R, E, A> $effect
     * @return Exit_<E, A>
     */
    public function runSyncExit(Effect $effect): Exit_;

    /**
     * Get the current context.
     *
     * @return Context<R>
     */
    public function context(): Context;

    /**
     * Create a runtime with additional context.
     *
     * @template R2
     * @param Context<R2> $context
     * @return RuntimeInterface<R|R2>
     */
    public function withContext(Context $context): RuntimeInterface;
}
