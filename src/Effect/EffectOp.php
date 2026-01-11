<?php

declare(strict_types=1);

namespace EffectPHP\Effect;

use Closure;
use EffectPHP\Context\Context;
use EffectPHP\Context\Tag;

/**
 * Internal representation of Effect operations.
 *
 * @template-covariant R
 * @template-covariant E
 * @template-covariant A
 *
 * @internal
 */
abstract class EffectOp
{
    abstract public function tag(): string;
}

/**
 * @template-covariant A
 * @extends EffectOp<never, never, A>
 */
final class Succeed extends EffectOp
{
    /**
     * @param A $value
     */
    public function __construct(
        public readonly mixed $value,
    ) {}

    public function tag(): string
    {
        return 'Succeed';
    }
}

/**
 * @template-covariant E
 * @extends EffectOp<never, E, never>
 */
final class Fail extends EffectOp
{
    /**
     * @param E $error
     */
    public function __construct(
        public readonly mixed $error,
    ) {}

    public function tag(): string
    {
        return 'Fail';
    }
}

/**
 * @extends EffectOp<never, never, never>
 */
final class Defect extends EffectOp
{
    public function __construct(
        public readonly \Throwable $throwable,
    ) {}

    public function tag(): string
    {
        return 'Defect';
    }
}

/**
 * @template-covariant A
 * @extends EffectOp<never, never, A>
 */
final class Sync extends EffectOp
{
    /**
     * @param Closure(): A $thunk
     */
    public function __construct(
        public readonly Closure $thunk,
    ) {}

    public function tag(): string
    {
        return 'Sync';
    }
}

/**
 * @template-covariant E
 * @template-covariant A
 * @extends EffectOp<never, E, A>
 */
final class TrySync extends EffectOp
{
    /**
     * @param Closure(): A $thunk
     * @param Closure(\Throwable): E|null $catch
     */
    public function __construct(
        public readonly Closure $thunk,
        public readonly ?Closure $catch = null,
    ) {}

    public function tag(): string
    {
        return 'TrySync';
    }
}

/**
 * @template-covariant E
 * @template-covariant A
 * @extends EffectOp<never, E, A>
 */
final class Async extends EffectOp
{
    /**
     * @param Closure(Closure(\EffectPHP\Exit\Exit_<E, A>): void): void $register
     */
    public function __construct(
        public readonly Closure $register,
    ) {}

    public function tag(): string
    {
        return 'Async';
    }
}

/**
 * @template-covariant R
 * @template-covariant E
 * @template-covariant A
 * @extends EffectOp<R, E, A>
 */
final class Suspend extends EffectOp
{
    /**
     * @param Closure(): Effect<R, E, A> $thunk
     */
    public function __construct(
        public readonly Closure $thunk,
    ) {}

    public function tag(): string
    {
        return 'Suspend';
    }
}

/**
 * @extends EffectOp<never, never, never>
 */
final class NeverOp extends EffectOp
{
    public function tag(): string
    {
        return 'Never';
    }
}

/**
 * @extends EffectOp<never, never, never>
 */
final class Interrupt extends EffectOp
{
    public function tag(): string
    {
        return 'Interrupt';
    }
}

/**
 * @template R
 * @template E
 * @template A
 * @template-covariant B
 * @extends EffectOp<R, E, B>
 */
final class Map extends EffectOp
{
    /**
     * @param EffectOp<R, E, A> $effect
     * @param Closure(A): B $f
     */
    public function __construct(
        public readonly EffectOp $effect,
        public readonly Closure $f,
    ) {}

    public function tag(): string
    {
        return 'Map';
    }
}

/**
 * @template R1
 * @template R2
 * @template E1
 * @template E2
 * @template A
 * @template-covariant B
 * @extends EffectOp<R1|R2, E1|E2, B>
 */
final class FlatMap extends EffectOp
{
    /**
     * @param EffectOp<R1, E1, A> $effect
     * @param Closure(A): Effect<R2, E2, B> $f
     */
    public function __construct(
        public readonly EffectOp $effect,
        public readonly Closure $f,
    ) {}

    public function tag(): string
    {
        return 'FlatMap';
    }
}

/**
 * @template R
 * @template E1
 * @template E2
 * @template A
 * @template-covariant B
 * @extends EffectOp<R, E2, A|B>
 */
final class Fold extends EffectOp
{
    /**
     * @param EffectOp<R, E1, A> $effect
     * @param Closure(A): Effect<never, never, A> $onSuccess
     * @param Closure(\EffectPHP\Cause\Cause<E1>): Effect<never, E2, B> $onFailure
     */
    public function __construct(
        public readonly EffectOp $effect,
        public readonly Closure $onSuccess,
        public readonly Closure $onFailure,
    ) {}

    public function tag(): string
    {
        return 'Fold';
    }
}

/**
 * @template S
 * @template-covariant A
 * @extends EffectOp<S, never, A>
 */
final class Access extends EffectOp
{
    /**
     * @param Tag<S> $serviceTag
     * @param Closure(S): A $f
     */
    public function __construct(
        public readonly Tag $serviceTag,
        public readonly Closure $f,
    ) {}

    public function tag(): string
    {
        return 'Access';
    }
}

/**
 * @template R
 * @template R2
 * @template E
 * @template A
 * @extends EffectOp<R, E, A>
 */
final class Provide extends EffectOp
{
    /**
     * @param EffectOp<R|R2, E, A> $effect
     * @param Context<R2> $context
     */
    public function __construct(
        public readonly EffectOp $effect,
        public readonly Context $context,
    ) {}

    public function tag(): string
    {
        return 'Provide';
    }
}
