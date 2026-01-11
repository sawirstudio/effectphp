<?php

declare(strict_types=1);

namespace EffectPHP;

use Closure;
use EffectPHP\Cause\Cause;
use EffectPHP\Combinators\All;
use EffectPHP\Combinators\Retry;
use EffectPHP\Combinators\RetryPolicy;
use EffectPHP\Combinators\Timing;
use EffectPHP\Context\Context;
use EffectPHP\Context\Tag;
use EffectPHP\Effect\Effect;
use EffectPHP\Exit\Exit_;
use EffectPHP\Resource\AcquireRelease;
use EffectPHP\Runtime\FiberRuntime;
use EffectPHP\Runtime\SyncRuntime;
use Generator;

// =============================================================================
// Effect Constructors
// =============================================================================

/**
 * Create an Effect that succeeds with the given value.
 *
 * @template A
 * @param A $value
 * @return Effect<never, never, A>
 */
function succeed(mixed $value): Effect
{
    return Effect::succeed($value);
}

/**
 * Create an Effect that succeeds with void/null.
 *
 * @return Effect<never, never, null>
 */
function unit(): Effect
{
    return Effect::unit();
}

/**
 * Create an Effect that fails with the given error.
 *
 * @template E
 * @param E $error
 * @return Effect<never, E, never>
 */
function fail(mixed $error): Effect
{
    return Effect::fail($error);
}

/**
 * Create an Effect that dies with a defect (unexpected error).
 *
 * @return Effect<never, never, never>
 */
function defect(\Throwable $throwable): Effect
{
    return Effect::defect($throwable);
}

/**
 * Create an Effect from a synchronous computation.
 *
 * @template A
 * @param Closure(): A $thunk
 * @return Effect<never, never, A>
 */
function sync(Closure $thunk): Effect
{
    return Effect::sync($thunk);
}

/**
 * Create an Effect from a fallible synchronous computation.
 *
 * @template A
 * @template E
 * @param Closure(): A $thunk
 * @param Closure(\Throwable): E|null $catch
 * @return Effect<never, E|\Throwable, A>
 */
function trySync(Closure $thunk, ?Closure $catch = null): Effect
{
    return Effect::trySync($thunk, $catch);
}

/**
 * Defer Effect creation until execution.
 *
 * @template R
 * @template E
 * @template A
 * @param Closure(): Effect<R, E, A> $thunk
 * @return Effect<R, E, A>
 */
function suspend(Closure $thunk): Effect
{
    return Effect::suspend($thunk);
}

/**
 * Create an Effect from an async callback.
 *
 * @template E
 * @template A
 * @param Closure(Closure(Exit_<E, A>): void): void $register
 * @return Effect<never, E, A>
 */
function async(Closure $register): Effect
{
    return Effect::async($register);
}

// =============================================================================
// Service Access
// =============================================================================

/**
 * Get a service from context.
 *
 * @template S
 * @param Tag<S> $tag
 * @return Effect<S, never, S>
 */
function service(Tag $tag): Effect
{
    return Effect::getService($tag);
}

// =============================================================================
// Combinators
// =============================================================================

/**
 * Run effects sequentially, collecting results.
 *
 * @template R
 * @template E
 * @template A
 * @param array<int, Effect<R, E, A>> $effects
 * @return Effect<R, E, array<int, A>>
 */
function all(array $effects): Effect
{
    return All::all($effects);
}

/**
 * Apply an effectful function to each item.
 *
 * @template R
 * @template E
 * @template A
 * @template B
 * @param iterable<A> $items
 * @param Closure(A): Effect<R, E, B> $f
 * @return Effect<R, E, array<int, B>>
 */
function traverse(iterable $items, Closure $f): Effect
{
    $effects = [];
    foreach ($items as $item) {
        $effects[] = $f($item);
    }
    return All::seq($effects);
}

/**
 * Delay execution.
 *
 * @param int $milliseconds
 * @return Effect<never, never, null>
 */
function delay(int $milliseconds): Effect
{
    return Timing::delay($milliseconds);
}

/**
 * Sleep for seconds.
 *
 * @param float $seconds
 * @return Effect<never, never, null>
 */
function sleep(float $seconds): Effect
{
    return Timing::sleep($seconds);
}

/**
 * Retry an effect with a policy.
 *
 * @template R
 * @template E
 * @template A
 * @param Effect<R, E, A> $effect
 * @param RetryPolicy|int $policyOrTimes
 * @return Effect<R, E, A>
 */
function retry(Effect $effect, RetryPolicy|int $policyOrTimes): Effect
{
    if (is_int($policyOrTimes)) {
        return Retry::retryN($effect, $policyOrTimes);
    }
    return Retry::retry($effect, $policyOrTimes);
}

/**
 * Acquire and release a resource safely.
 *
 * @template R
 * @template E
 * @template A
 * @template B
 * @param Effect<R, E, A> $acquire
 * @param Closure(A): Effect<never, never, mixed> $release
 * @param Closure(A): Effect<R, E, B> $use
 * @return Effect<R, E, B>
 */
function bracket(Effect $acquire, Closure $release, Closure $use): Effect
{
    return AcquireRelease::bracket($acquire, $release, $use);
}

// =============================================================================
// Do-Notation (Generator-based)
// =============================================================================

/**
 * Execute a generator as a sequence of flatMaps.
 * Provides do-notation style syntax for Effect composition.
 *
 * Usage:
 * ```php
 * $program = gen(function () {
 *     $x = yield succeed(1);
 *     $y = yield succeed(2);
 *     return $x + $y;
 * });
 * ```
 *
 * @template R
 * @template E
 * @template A
 * @param Closure(): Generator<int, Effect<R, E, mixed>, mixed, A> $gen
 * @return Effect<R, E, A>
 */
function gen(Closure $gen): Effect
{
    return Effect::suspend(function () use ($gen) {
        $generator = $gen();
        return runGenerator($generator);
    });
}

/**
 * @template R
 * @template E
 * @template A
 * @param Generator<int, Effect<R, E, mixed>, mixed, A> $generator
 * @return Effect<R, E, A>
 */
function runGenerator(Generator $generator): Effect
{
    if (!$generator->valid()) {
        return Effect::succeed($generator->getReturn());
    }

    /** @var Effect<R, E, mixed> $effect */
    $effect = $generator->current();

    return $effect->flatMap(function ($value) use ($generator) {
        $generator->send($value);
        return runGenerator($generator);
    });
}

// =============================================================================
// Pipe Helper
// =============================================================================

/**
 * Pipe a value through a series of functions.
 *
 * @template A
 * @param A $value
 * @param callable(mixed): mixed ...$fns
 * @return mixed
 */
function pipe(mixed $value, callable ...$fns): mixed
{
    return array_reduce($fns, fn($acc, $fn) => $fn($acc), $value);
}

// =============================================================================
// Runtime Helpers
// =============================================================================

/**
 * Run an effect synchronously using SyncRuntime.
 *
 * @template E
 * @template A
 * @param Effect<never, E, A> $effect
 * @return A
 * @throws \Throwable
 */
function runSync(Effect $effect): mixed
{
    return (new SyncRuntime())->runSync($effect);
}

/**
 * Run an effect and get the Exit value.
 *
 * @template E
 * @template A
 * @param Effect<never, E, A> $effect
 * @return Exit_<E, A>
 */
function runSyncExit(Effect $effect): Exit_
{
    return (new SyncRuntime())->runSyncExit($effect);
}

/**
 * Run an effect with the FiberRuntime.
 *
 * @template E
 * @template A
 * @param Effect<never, E, A> $effect
 * @return A
 * @throws \Throwable
 */
function runFiber(Effect $effect): mixed
{
    return (new FiberRuntime())->runSync($effect);
}
