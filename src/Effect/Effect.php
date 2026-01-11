<?php

declare(strict_types=1);

namespace EffectPHP\Effect;

use Closure;
use EffectPHP\Cause\Cause;
use EffectPHP\Context\Context;
use EffectPHP\Context\Tag;
use EffectPHP\Exit\Exit_;

/**
 * Core Effect type representing a lazy, composable computation.
 *
 * Effect<R, E, A> represents a computation that:
 * - Requires an environment of type R (context/dependencies)
 * - May fail with an error of type E (expected failures)
 * - May succeed with a value of type A
 *
 * @template-covariant R Requirements/context type
 * @template-covariant E Error type (expected failures)
 * @template-covariant A Success value type
 *
 * @immutable
 */
final class Effect
{
    /**
     * @param EffectOp<R, E, A> $op
     */
    private function __construct(
        private readonly EffectOp $op,
    ) {}

    /**
     * @return EffectOp<R, E, A>
     * @internal
     */
    public function getOp(): EffectOp
    {
        return $this->op;
    }

    // =========================================================================
    // CONSTRUCTORS
    // =========================================================================

    /**
     * Create an Effect that succeeds with the given value.
     *
     * @template T
     * @param T $value
     * @return Effect<never, never, T>
     */
    public static function succeed(mixed $value): self
    {
        return new self(new Succeed($value));
    }

    /**
     * Create an Effect that succeeds with void/null.
     *
     * @return Effect<never, never, null>
     */
    public static function unit(): self
    {
        return self::succeed(null);
    }

    /**
     * Create an Effect that fails with the given error.
     *
     * @template F
     * @param F $error
     * @return Effect<never, F, never>
     */
    public static function fail(mixed $error): self
    {
        return new self(new Fail($error));
    }

    /**
     * Create an Effect that fails with the given Cause.
     *
     * @template F
     * @param Cause<F> $cause
     * @return Effect<never, F, never>
     */
    public static function failCause(Cause $cause): self
    {
        if ($cause->isEmpty()) {
            return self::defect(new \RuntimeException('Empty cause'));
        }
        if ($cause->isDie()) {
            $defect = $cause->defectOption();
            if ($defect !== null) {
                return self::defect($defect);
            }
        }
        $failure = $cause->failureOption();
        if ($failure !== null) {
            return self::fail($failure);
        }
        return self::defect($cause->squash());
    }

    /**
     * Create an Effect that dies with the given defect (unexpected error).
     *
     * @return Effect<never, never, never>
     */
    public static function defect(\Throwable $defect): self
    {
        return new self(new Defect($defect));
    }

    /**
     * Create an Effect from a synchronous computation.
     *
     * @template T
     * @param Closure(): T $thunk
     * @return Effect<never, never, T>
     */
    public static function sync(Closure $thunk): self
    {
        return new self(new Sync($thunk));
    }

    /**
     * Create an Effect from a synchronous computation that may throw.
     *
     * @template T
     * @template F
     * @param Closure(): T $thunk
     * @param Closure(\Throwable): F|null $catch
     * @return Effect<never, F|\Throwable, T>
     */
    public static function trySync(Closure $thunk, ?Closure $catch = null): self
    {
        return new self(new TrySync($thunk, $catch));
    }

    /**
     * Create an Effect from an async callback-based operation.
     *
     * @template F
     * @template T
     * @param Closure(Closure(Exit_<F, T>): void): void $register
     * @return Effect<never, F, T>
     */
    public static function async(Closure $register): self
    {
        return new self(new Async($register));
    }

    /**
     * Defer Effect creation until execution.
     *
     * @template Req
     * @template Err
     * @template Val
     * @param Closure(): Effect<Req, Err, Val> $thunk
     * @return Effect<Req, Err, Val>
     */
    public static function suspend(Closure $thunk): self
    {
        return new self(new Suspend($thunk));
    }

    /**
     * Create an Effect that never completes.
     *
     * @return Effect<never, never, never>
     */
    public static function never(): self
    {
        return new self(new NeverOp());
    }

    /**
     * Create an Effect representing interruption.
     *
     * @return Effect<never, never, never>
     */
    public static function interrupt(): self
    {
        return new self(new Interrupt());
    }

    // =========================================================================
    // TRANSFORMATIONS
    // =========================================================================

    /**
     * Transform the success value.
     *
     * @template B
     * @param Closure(A): B $f
     * @return Effect<R, E, B>
     */
    public function map(Closure $f): self
    {
        return new self(new Map($this->op, $f));
    }

    /**
     * Replace success value with a constant.
     *
     * @template B
     * @param B $value
     * @return Effect<R, E, B>
     */
    public function as(mixed $value): self
    {
        return $this->map(fn($_) => $value);
    }

    /**
     * Replace success value with void/null.
     *
     * @return Effect<R, E, null>
     */
    public function asUnit(): self
    {
        return $this->as(null);
    }

    /**
     * Chain Effects sequentially (flatMap/bind).
     *
     * @template Req2
     * @template Err2
     * @template B
     * @param Closure(A): Effect<Req2, Err2, B> $f
     * @return Effect<R|Req2, E|Err2, B>
     */
    public function flatMap(Closure $f): self
    {
        return new self(new FlatMap($this->op, $f));
    }

    /**
     * Alias for flatMap.
     *
     * @template Req2
     * @template Err2
     * @template B
     * @param Closure(A): Effect<Req2, Err2, B> $f
     * @return Effect<R|Req2, E|Err2, B>
     */
    public function andThen(Closure $f): self
    {
        return $this->flatMap($f);
    }

    /**
     * Execute a side effect without changing the value.
     *
     * @param Closure(A): void $f
     * @return Effect<R, E, A>
     */
    public function tap(Closure $f): self
    {
        return $this->map(function ($a) use ($f) {
            $f($a);
            return $a;
        });
    }

    /**
     * Execute an Effect for side effects, keeping original value.
     *
     * @template Req2
     * @template Err2
     * @param Closure(A): Effect<Req2, Err2, mixed> $f
     * @return Effect<R|Req2, E|Err2, A>
     */
    public function tapEffect(Closure $f): self
    {
        return $this->flatMap(fn($a) => $f($a)->as($a));
    }

    // =========================================================================
    // ZIPPING / COMBINING
    // =========================================================================

    /**
     * Combine with another Effect, keeping both values as tuple.
     *
     * @template Req2
     * @template Err2
     * @template B
     * @param Effect<Req2, Err2, B> $that
     * @return Effect<R|Req2, E|Err2, array{0: A, 1: B}>
     */
    public function zip(Effect $that): self
    {
        return $this->flatMap(fn($a) => $that->map(fn($b) => [$a, $b]));
    }

    /**
     * Combine with another Effect using a combining function.
     *
     * @template Req2
     * @template Err2
     * @template B
     * @template C
     * @param Effect<Req2, Err2, B> $that
     * @param Closure(A, B): C $f
     * @return Effect<R|Req2, E|Err2, C>
     */
    public function zipWith(Effect $that, Closure $f): self
    {
        return $this->flatMap(fn($a) => $that->map(fn($b) => $f($a, $b)));
    }

    /**
     * Run this Effect, then another, keeping second value.
     *
     * @template Req2
     * @template Err2
     * @template B
     * @param Effect<Req2, Err2, B> $that
     * @return Effect<R|Req2, E|Err2, B>
     */
    public function zipRight(Effect $that): self
    {
        return $this->flatMap(fn($_) => $that);
    }

    /**
     * Run this Effect, then another, keeping first value.
     *
     * @template Req2
     * @template Err2
     * @template B
     * @param Effect<Req2, Err2, B> $that
     * @return Effect<R|Req2, E|Err2, A>
     */
    public function zipLeft(Effect $that): self
    {
        return $this->flatMap(fn($a) => $that->as($a));
    }

    // =========================================================================
    // ERROR HANDLING
    // =========================================================================

    /**
     * Recover from all expected errors.
     *
     * @template Req2
     * @template Err2
     * @template B
     * @param Closure(E): Effect<Req2, Err2, B> $handler
     * @return Effect<R|Req2, Err2, A|B>
     */
    public function catchAll(Closure $handler): self
    {
        return new self(
            new Fold(
                $this->op,
                fn($a) => self::succeed($a),
                fn(Cause $cause) => $cause->isFailure() ? $handler($cause->failureOption()) : self::failCause($cause),
            ),
        );
    }

    /**
     * Recover from a specific tagged error class.
     *
     * @template ErrorClass of object
     * @template Req2
     * @template Err2
     * @template B
     * @param class-string<ErrorClass> $errorClass
     * @param Closure(ErrorClass): Effect<Req2, Err2, B> $handler
     * @return Effect<R|Req2, E|Err2, A|B>
     */
    public function catchTag(string $errorClass, Closure $handler): self
    {
        return $this->catchAll(
            fn($error) => $error instanceof $errorClass ? $handler($error) : self::fail($error)
        );
    }

    /**
     * Transform the error type.
     *
     * @template E2
     * @param Closure(E): E2 $f
     * @return Effect<R, E2, A>
     */
    public function mapError(Closure $f): self
    {
        return new self(
            new Fold($this->op, fn($a) => self::succeed($a), fn(Cause $cause) => self::failCause($cause->map($f))),
        );
    }

    /**
     * Try this Effect, or fall back to another.
     *
     * @template Req2
     * @template Err2
     * @template B
     * @param Effect<Req2, Err2, B>|Closure(): Effect<Req2, Err2, B> $that
     * @return Effect<R|Req2, Err2, A|B>
     */
    public function orElse(Effect|Closure $that): self
    {
        return $this->catchAll(fn($_) => $that instanceof Effect ? $that : $that());
    }

    /**
     * Provide a fallback value on error.
     *
     * @template B
     * @param B $value
     * @return Effect<R, never, A|B>
     */
    public function orElseSucceed(mixed $value): self
    {
        return $this->catchAll(fn($_) => self::succeed($value));
    }

    /**
     * Recover from all causes (including defects).
     *
     * @template Req2
     * @template Err2
     * @template B
     * @param Closure(Cause<E>): Effect<Req2, Err2, B> $handler
     * @return Effect<R|Req2, Err2, A|B>
     */
    public function catchAllCause(Closure $handler): self
    {
        return new self(new Fold($this->op, fn($a) => self::succeed($a), $handler));
    }

    /**
     * Convert expected errors into defects.
     *
     * @return Effect<R, never, A>
     */
    public function orDie(): self
    {
        return $this->catchAll(
            fn($error) => self::defect(
                $error instanceof \Throwable ? $error : new \RuntimeException((string) $error)
            )
        );
    }

    /**
     * Keep only errors matching predicate, convert rest to defects.
     *
     * @param Closure(E): bool $predicate
     * @return Effect<R, E, A>
     */
    public function refineOrDie(Closure $predicate): self
    {
        return $this->catchAll(function ($error) use ($predicate) {
            if ($predicate($error)) {
                return self::fail($error);
            }
            return self::defect(
                $error instanceof \Throwable ? $error : new \RuntimeException((string) $error)
            );
        });
    }

    // =========================================================================
    // CONTEXT / DEPENDENCY INJECTION
    // =========================================================================

    /**
     * Access a service from the context.
     *
     * @template S
     * @template B
     * @param Tag<S> $tag
     * @param Closure(S): B $f
     * @return Effect<S, never, B>
     */
    public static function service(Tag $tag, Closure $f): self
    {
        return new self(new Access($tag, $f));
    }

    /**
     * Get a service from the context.
     *
     * @template S
     * @param Tag<S> $tag
     * @return Effect<S, never, S>
     */
    public static function getService(Tag $tag): self
    {
        return self::service($tag, fn($s) => $s);
    }

    /**
     * Provide context to an effect.
     *
     * @template R2
     * @param Context<R2> $context
     * @return Effect<mixed, E, A>
     */
    public function provide(Context $context): self
    {
        return new self(new Provide($this->op, $context));
    }

    /**
     * Provide a single service.
     *
     * @template S
     * @param Tag<S> $tag
     * @param S $service
     * @return Effect<mixed, E, A>
     */
    public function provideService(Tag $tag, mixed $service): self
    {
        return $this->provide(Context::empty()->add($tag, $service));
    }

    // =========================================================================
    // FINALIZATION
    // =========================================================================

    /**
     * Ensure a finalizer runs regardless of outcome.
     *
     * @param Effect<never, never, mixed> $finalizer
     * @return Effect<R, E, A>
     */
    public function ensuring(Effect $finalizer): self
    {
        return $this->catchAllCause(
            fn(Cause $cause) => $finalizer->flatMap(fn() => self::failCause($cause))
        )->flatMap(
            fn($a) => $finalizer->as($a)
        );
    }
}
