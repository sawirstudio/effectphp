<?php

declare(strict_types=1);

namespace EffectPHP\Exit;

use Closure;
use EffectPHP\Cause\Cause;
use EffectPHP\Cause\Defect as DefectCause;
use EffectPHP\Cause\Fail as FailCause;

/**
 * Exit represents the result of running an Effect.
 *
 * @template-covariant E Error type
 * @template-covariant A Success type
 */
abstract class Exit_
{
    abstract public function tag(): string;

    abstract public function isSuccess(): bool;

    abstract public function isFailure(): bool;

    /**
     * @template B
     * @param Closure(A): B $onSuccess
     * @param Closure(Cause<E>): B $onFailure
     * @return B
     */
    abstract public function match(Closure $onSuccess, Closure $onFailure): mixed;

    /**
     * @return A
     * @throws \Throwable
     */
    abstract public function getOrThrow(): mixed;

    /**
     * @return Cause<E>|null
     */
    abstract public function causeOption(): ?Cause;

    /**
     * @template B
     * @param Closure(A): B $f
     * @return Exit_<E, B>
     */
    abstract public function map(Closure $f): Exit_;

    /**
     * @template E2
     * @template B
     * @param Closure(A): Exit_<E2, B> $f
     * @return Exit_<E|E2, B>
     */
    abstract public function flatMap(Closure $f): Exit_;

    /**
     * @template E2
     * @param Closure(E): E2 $f
     * @return Exit_<E2, A>
     */
    abstract public function mapError(Closure $f): Exit_;

    /**
     * @template T
     * @param T $value
     * @return Exit_<never, T>
     */
    public static function succeed(mixed $value): Exit_
    {
        return new Success($value);
    }

    /**
     * @template F
     * @param F $error
     * @return Exit_<F, never>
     */
    public static function fail(mixed $error): Exit_
    {
        return new Failure(new FailCause($error));
    }

    /**
     * @return Exit_<never, never>
     */
    public static function defect(\Throwable $defect): Exit_
    {
        return new Failure(new DefectCause($defect));
    }

    /**
     * @template F
     * @param Cause<F> $cause
     * @return Exit_<F, never>
     */
    public static function failCause(Cause $cause): Exit_
    {
        return new Failure($cause);
    }

    /**
     * @return Exit_<never, void>
     */
    public static function unit(): Exit_
    {
        return new Success(null);
    }
}

/**
 * @template-covariant A
 * @extends Exit_<never, A>
 */
final class Success extends Exit_
{
    /**
     * @param A $value
     */
    public function __construct(
        public readonly mixed $value,
    ) {}

    public function tag(): string
    {
        return 'Success';
    }

    public function isSuccess(): bool
    {
        return true;
    }

    public function isFailure(): bool
    {
        return false;
    }

    public function match(Closure $onSuccess, Closure $onFailure): mixed
    {
        return $onSuccess($this->value);
    }

    public function getOrThrow(): mixed
    {
        return $this->value;
    }

    public function causeOption(): ?Cause
    {
        return null;
    }

    public function map(Closure $f): Exit_
    {
        return new Success($f($this->value));
    }

    public function flatMap(Closure $f): Exit_
    {
        return $f($this->value);
    }

    public function mapError(Closure $f): Exit_
    {
        return $this;
    }
}

/**
 * @template-covariant E
 * @extends Exit_<E, never>
 */
final class Failure extends Exit_
{
    /**
     * @param Cause<E> $cause
     */
    public function __construct(
        public readonly Cause $cause,
    ) {}

    public function tag(): string
    {
        return 'Failure';
    }

    public function isSuccess(): bool
    {
        return false;
    }

    public function isFailure(): bool
    {
        return true;
    }

    public function match(Closure $onSuccess, Closure $onFailure): mixed
    {
        return $onFailure($this->cause);
    }

    public function getOrThrow(): never
    {
        throw $this->cause->squash();
    }

    public function causeOption(): Cause
    {
        return $this->cause;
    }

    public function map(Closure $f): Exit_
    {
        return $this;
    }

    public function flatMap(Closure $f): Exit_
    {
        return $this;
    }

    public function mapError(Closure $f): Exit_
    {
        return new Failure($this->cause->map($f));
    }
}
