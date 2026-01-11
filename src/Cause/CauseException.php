<?php

declare(strict_types=1);

namespace EffectPHP\Cause;

/**
 * Exception wrapper for a Cause.
 *
 * @template E
 */
final class CauseException extends \Exception
{
    /**
     * @param Cause<E> $cause
     */
    public function __construct(
        public readonly Cause $cause,
        string $message = '',
    ) {
        parent::__construct($message);
    }
}

/**
 * Exception thrown when a fiber is interrupted.
 */
final class InterruptedException extends \Exception
{
    public function __construct()
    {
        parent::__construct('Fiber was interrupted');
    }
}
