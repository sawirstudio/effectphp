<?php

declare(strict_types=1);

namespace EffectPHP\Runtime\Fiber;

use Closure;
use EffectPHP\Exit\Exit_;

/**
 * Promise-like deferred result.
 *
 * @template E
 * @template A
 */
final class Deferred
{
    /** @var Exit_<E, A>|null */
    private ?Exit_ $result = null;

    /** @var array<int, Closure(Exit_<E, A>): void> */
    private array $callbacks = [];

    public function isComplete(): bool
    {
        return $this->result !== null;
    }

    /**
     * @param Exit_<E, A> $exit
     */
    public function complete(Exit_ $exit): void
    {
        if ($this->result !== null) {
            return;
        }

        $this->result = $exit;

        foreach ($this->callbacks as $callback) {
            try {
                $callback($exit);
            } catch (\Throwable) {
                // Callbacks should not throw
            }
        }
        $this->callbacks = [];
    }

    /**
     * @return Exit_<E, A>
     * @throws \RuntimeException If not complete
     */
    public function await(): Exit_
    {
        if ($this->result === null) {
            throw new \RuntimeException('Deferred not yet complete');
        }
        return $this->result;
    }

    /**
     * @return Exit_<E, A>|null
     */
    public function poll(): ?Exit_
    {
        return $this->result;
    }

    /**
     * @param Closure(Exit_<E, A>): void $callback
     */
    public function onComplete(Closure $callback): void
    {
        if ($this->result !== null) {
            $callback($this->result);
        } else {
            $this->callbacks[] = $callback;
        }
    }
}
