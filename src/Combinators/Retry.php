<?php

declare(strict_types=1);

namespace EffectPHP\Combinators;

use Closure;
use EffectPHP\Effect\Effect;

/**
 * Retry policy configuration.
 */
final class RetryPolicy
{
    /**
     * @param int $maxRetries Maximum number of retry attempts
     * @param int $baseDelayMs Initial delay between retries in milliseconds
     * @param float $backoffMultiplier Multiplier for exponential backoff
     * @param int $maxDelayMs Maximum delay cap in milliseconds
     * @param Closure(mixed, int): bool|null $shouldRetry Custom predicate for retry decision
     */
    public function __construct(
        public readonly int $maxRetries = 3,
        public readonly int $baseDelayMs = 100,
        public readonly float $backoffMultiplier = 2.0,
        public readonly int $maxDelayMs = 10000,
        public readonly ?Closure $shouldRetry = null,
    ) {}

    /**
     * Create a policy with fixed delay.
     */
    public static function fixed(int $retries, int $delayMs): self
    {
        return new self($retries, $delayMs, 1.0, $delayMs);
    }

    /**
     * Create a policy with exponential backoff.
     */
    public static function exponential(int $retries, int $baseDelayMs = 100): self
    {
        return new self($retries, $baseDelayMs, 2.0);
    }

    /**
     * Create a policy that retries immediately.
     */
    public static function immediate(int $retries): self
    {
        return new self($retries, 0, 1.0, 0);
    }

    /**
     * Calculate delay for a given attempt.
     */
    public function delayForAttempt(int $attempt): int
    {
        $delay = (int) ($this->baseDelayMs * ($this->backoffMultiplier ** $attempt));
        return min($delay, $this->maxDelayMs);
    }
}

/**
 * Retry effects with configurable policies.
 */
final class Retry
{
    /**
     * Retry an effect according to a policy.
     *
     * @template R
     * @template E
     * @template A
     * @param Effect<R, E, A> $effect
     * @param RetryPolicy $policy
     * @return Effect<R, E, A>
     */
    public static function retry(Effect $effect, RetryPolicy $policy): Effect
    {
        return self::retryLoop($effect, $policy, 0);
    }

    /**
     * @template R
     * @template E
     * @template A
     * @param Effect<R, E, A> $effect
     * @param RetryPolicy $policy
     * @param int $attempt
     * @return Effect<R, E, A>
     */
    private static function retryLoop(Effect $effect, RetryPolicy $policy, int $attempt): Effect
    {
        return $effect->catchAll(function ($error) use ($effect, $policy, $attempt) {
            $shouldRetry = $policy->shouldRetry;
            $canRetry = $attempt < $policy->maxRetries
                && ($shouldRetry === null || $shouldRetry($error, $attempt));

            if (!$canRetry) {
                return Effect::fail($error);
            }

            $nextAttempt = self::retryLoop($effect, $policy, $attempt + 1);
            $delay = $policy->delayForAttempt($attempt);

            return $delay > 0
                ? Timing::delay($delay)->flatMap(fn() => $nextAttempt)
                : $nextAttempt;
        });
    }

    /**
     * Retry a specific number of times with no delay.
     *
     * @template R
     * @template E
     * @template A
     * @param Effect<R, E, A> $effect
     * @param int $times
     * @return Effect<R, E, A>
     */
    public static function retryN(Effect $effect, int $times): Effect
    {
        return self::retry($effect, RetryPolicy::immediate($times));
    }

    /**
     * Retry until predicate returns true.
     *
     * @template R
     * @template E
     * @template A
     * @param Effect<R, E, A> $effect
     * @param Closure(A): bool $predicate
     * @param int $maxAttempts
     * @return Effect<R, E, A>
     */
    public static function retryUntil(Effect $effect, Closure $predicate, int $maxAttempts = 10): Effect
    {
        return self::retryUntilLoop($effect, $predicate, 0, $maxAttempts);
    }

    /**
     * @template R
     * @template E
     * @template A
     */
    private static function retryUntilLoop(Effect $effect, Closure $predicate, int $attempt, int $maxAttempts): Effect
    {
        return $effect->flatMap(function ($value) use ($effect, $predicate, $attempt, $maxAttempts) {
            if ($predicate($value)) {
                return Effect::succeed($value);
            }

            if ($attempt >= $maxAttempts) {
                return Effect::succeed($value);
            }

            return self::retryUntilLoop($effect, $predicate, $attempt + 1, $maxAttempts);
        });
    }
}
