<?php

declare(strict_types=1);

namespace EffectPHP\Resource;

use Closure;
use EffectPHP\Effect\Effect;

/**
 * Resource management with acquire-release pattern.
 */
final class AcquireRelease
{
    /**
     * Safely acquire and release a resource.
     *
     * The release function is guaranteed to run regardless of
     * whether the use function succeeds, fails, or throws.
     *
     * @template R
     * @template E
     * @template A The resource type
     * @template B The result type
     * @param Effect<R, E, A> $acquire Effect that acquires the resource
     * @param Closure(A): Effect<never, never, mixed> $release Effect that releases the resource
     * @param Closure(A): Effect<R, E, B> $use Effect that uses the resource
     * @return Effect<R, E, B>
     */
    public static function acquireRelease(Effect $acquire, Closure $release, Closure $use): Effect
    {
        return $acquire->flatMap(function ($resource) use ($release, $use) {
            return $use($resource)->ensuring($release($resource));
        });
    }

    /**
     * Alias for acquireRelease (bracket pattern).
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
    public static function bracket(Effect $acquire, Closure $release, Closure $use): Effect
    {
        return self::acquireRelease($acquire, $release, $use);
    }

    /**
     * Ensure a finalizer runs regardless of outcome.
     *
     * @template R
     * @template E
     * @template A
     * @param Effect<R, E, A> $effect
     * @param Effect<never, never, mixed> $finalizer
     * @return Effect<R, E, A>
     */
    public static function ensuring(Effect $effect, Effect $finalizer): Effect
    {
        return $effect->ensuring($finalizer);
    }

    /**
     * Use multiple resources with proper cleanup.
     *
     * @template R
     * @template E
     * @template A
     * @template B
     * @template C
     * @param Effect<R, E, A> $acquire1
     * @param Closure(A): Effect<never, never, mixed> $release1
     * @param Effect<R, E, B> $acquire2
     * @param Closure(B): Effect<never, never, mixed> $release2
     * @param Closure(A, B): Effect<R, E, C> $use
     * @return Effect<R, E, C>
     */
    public static function bracket2(
        Effect $acquire1,
        Closure $release1,
        Effect $acquire2,
        Closure $release2,
        Closure $use,
    ): Effect {
        return self::bracket($acquire1, $release1, fn($r1) => self::bracket($acquire2, $release2, fn($r2) => $use(
            $r1,
            $r2,
        )));
    }
}
