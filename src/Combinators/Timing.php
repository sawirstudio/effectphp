<?php

declare(strict_types=1);

namespace EffectPHP\Combinators;

use EffectPHP\Effect\Effect;
use EffectPHP\Exit\Exit_;

/**
 * Error thrown when a timeout occurs.
 */
final class TimeoutError extends \Exception
{
    public function __construct(int $milliseconds)
    {
        parent::__construct("Operation timed out after {$milliseconds}ms");
    }
}

/**
 * Timing-related effect combinators.
 */
final class Timing
{
    /**
     * Delay execution by the specified milliseconds.
     *
     * @param int $milliseconds
     * @return Effect<never, never, null>
     */
    public static function delay(int $milliseconds): Effect
    {
        if ($milliseconds <= 0) {
            return Effect::unit();
        }

        return Effect::sync(function () use ($milliseconds) {
            usleep($milliseconds * 1000);
            return null;
        });
    }

    /**
     * Sleep for the specified seconds.
     *
     * @param float $seconds
     * @return Effect<never, never, null>
     */
    public static function sleep(float $seconds): Effect
    {
        return self::delay((int) ($seconds * 1000));
    }

    /**
     * Time the execution of an effect.
     *
     * @template R
     * @template E
     * @template A
     * @param Effect<R, E, A> $effect
     * @return Effect<R, E, array{value: A, durationMs: float}>
     */
    public static function timed(Effect $effect): Effect
    {
        return Effect::sync(fn() => microtime(true))->flatMap(fn($start) => $effect->map(fn($value) => [
            'value' => $value,
            'durationMs' => (microtime(true) - $start) * 1000,
        ]));
    }

    /**
     * Add a timeout to an effect (sync version using deadline check).
     * Note: This won't actually interrupt long-running sync operations,
     * but will check the deadline between effect steps.
     *
     * @template R
     * @template E
     * @template A
     * @param Effect<R, E, A> $effect
     * @param int $milliseconds
     * @return Effect<R, E|TimeoutError, A>
     */
    public static function timeout(Effect $effect, int $milliseconds): Effect
    {
        return Effect::sync(fn() => microtime(true) + ($milliseconds / 1000))->flatMap(function ($deadline) use (
            $effect,
        ) {
            return $effect->flatMap(function ($value) use ($deadline) {
                if (microtime(true) > $deadline) {
                    return Effect::fail(
                        new TimeoutError((int) (($deadline - microtime(true) + ($deadline - microtime(true))) * 1000)),
                    );
                }
                return Effect::succeed($value);
            });
        });
    }

    /**
     * Repeat an effect a specific number of times.
     *
     * @template R
     * @template E
     * @template A
     * @param Effect<R, E, A> $effect
     * @param int $times
     * @return Effect<R, E, array<int, A>>
     */
    public static function repeatN(Effect $effect, int $times): Effect
    {
        if ($times <= 0) {
            return Effect::succeed([]);
        }

        $result = Effect::succeed([]);

        for ($i = 0; $i < $times; $i++) {
            $result = $result->flatMap(fn(array $results) => $effect->map(fn($value) => [...$results, $value]));
        }

        return $result;
    }

    /**
     * Repeat an effect forever (until interrupted or fails).
     *
     * @template R
     * @template E
     * @template A
     * @param Effect<R, E, A> $effect
     * @return Effect<R, E, never>
     */
    public static function forever(Effect $effect): Effect
    {
        return $effect->flatMap(fn() => self::forever($effect));
    }

    /**
     * Repeat with delay between iterations.
     *
     * @template R
     * @template E
     * @template A
     * @param Effect<R, E, A> $effect
     * @param int $times
     * @param int $delayMs
     * @return Effect<R, E, array<int, A>>
     */
    public static function repeatWithDelay(Effect $effect, int $times, int $delayMs): Effect
    {
        if ($times <= 0) {
            return Effect::succeed([]);
        }

        $result = $effect->map(fn($v) => [$v]);

        for ($i = 1; $i < $times; $i++) {
            $result = $result->flatMap(fn(array $results) => self::delay($delayMs)
                ->flatMap(fn() => $effect)
                ->map(fn($value) => [...$results, $value]));
        }

        return $result;
    }
}
