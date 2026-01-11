<?php

declare(strict_types=1);

namespace EffectPHP\Combinators;

use EffectPHP\Effect\Effect;

/**
 * Combine multiple effects.
 */
final class All
{
    /**
     * Run effects sequentially, collecting results.
     *
     * @template R
     * @template E
     * @template A
     * @param array<int, Effect<R, E, A>> $effects
     * @return Effect<R, E, array<int, A>>
     */
    public static function seq(array $effects): Effect
    {
        if (empty($effects)) {
            return Effect::succeed([]);
        }

        $result = Effect::succeed([]);

        foreach ($effects as $effect) {
            $result = $result->flatMap(fn(array $results) => $effect->map(fn($value) => [...$results, $value]));
        }

        return $result;
    }

    /**
     * Run effects sequentially, failing fast on first error.
     *
     * @template R
     * @template E
     * @template A
     * @param iterable<Effect<R, E, A>> $effects
     * @return Effect<R, E, array<int, A>>
     */
    public static function all(iterable $effects): Effect
    {
        return self::seq(is_array($effects) ? $effects : iterator_to_array($effects));
    }

    /**
     * Run two effects and combine results.
     *
     * @template R1
     * @template R2
     * @template E1
     * @template E2
     * @template A
     * @template B
     * @param Effect<R1, E1, A> $first
     * @param Effect<R2, E2, B> $second
     * @return Effect<R1|R2, E1|E2, array{0: A, 1: B}>
     */
    public static function tuple(Effect $first, Effect $second): Effect
    {
        return $first->zip($second);
    }

    /**
     * Run three effects and combine results.
     *
     * @template R1
     * @template R2
     * @template R3
     * @template E1
     * @template E2
     * @template E3
     * @template A
     * @template B
     * @template C
     * @param Effect<R1, E1, A> $first
     * @param Effect<R2, E2, B> $second
     * @param Effect<R3, E3, C> $third
     * @return Effect<R1|R2|R3, E1|E2|E3, array{0: A, 1: B, 2: C}>
     */
    public static function tuple3(Effect $first, Effect $second, Effect $third): Effect
    {
        return $first->flatMap(fn($a) => $second->flatMap(fn($b) => $third->map(fn($c) => [$a, $b, $c])));
    }

    /**
     * Run the first successful effect.
     *
     * @template R
     * @template E
     * @template A
     * @param array<int, Effect<R, E, A>> $effects
     * @return Effect<R, E, A>
     */
    public static function firstSuccess(array $effects): Effect
    {
        if (empty($effects)) {
            return Effect::defect(new \InvalidArgumentException('Cannot run firstSuccess on empty array'));
        }

        $result = $effects[0];

        for ($i = 1; $i < count($effects); $i++) {
            $effect = $effects[$i];
            $result = $result->orElse($effect);
        }

        return $result;
    }
}
