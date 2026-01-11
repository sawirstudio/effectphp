<?php

declare(strict_types=1);

namespace EffectPHP\Cause;

use Closure;

/**
 * Cause represents the full story of why an effect failed.
 * It forms a semiring data structure for lossless error composition.
 *
 * @template-covariant E The expected error type
 */
abstract class Cause
{
    abstract public function tag(): string;

    abstract public function isEmpty(): bool;

    abstract public function isFailure(): bool;

    abstract public function isDie(): bool;

    abstract public function isInterrupt(): bool;

    /**
     * @return array<int, E>
     */
    abstract public function failures(): array;

    /**
     * @return array<int, \Throwable>
     */
    abstract public function defects(): array;

    /**
     * @template E2
     * @param Closure(E): E2 $f
     * @return Cause<E2>
     */
    abstract public function map(Closure $f): Cause;

    /**
     * @template E2
     * @param Closure(E): Cause<E2> $f
     * @return Cause<E2>
     */
    abstract public function flatMap(Closure $f): Cause;

    /**
     * Sequential composition: this Cause followed by another.
     *
     * @template E2
     * @param Cause<E2> $that
     * @return Cause<E|E2>
     */
    public function then(Cause $that): Cause
    {
        if ($this->isEmpty()) {
            return $that;
        }
        if ($that->isEmpty()) {
            return $this;
        }
        return new Sequential($this, $that);
    }

    /**
     * Parallel composition: this Cause and another occurred simultaneously.
     *
     * @template E2
     * @param Cause<E2> $that
     * @return Cause<E|E2>
     */
    public function both(Cause $that): Cause
    {
        if ($this->isEmpty()) {
            return $that;
        }
        if ($that->isEmpty()) {
            return $this;
        }
        return new Parallel($this, $that);
    }

    /**
     * @return E|null
     */
    public function failureOption(): mixed
    {
        $failures = $this->failures();
        return $failures[0] ?? null;
    }

    public function defectOption(): ?\Throwable
    {
        $defects = $this->defects();
        return $defects[0] ?? null;
    }

    /**
     * Squash this Cause into a single Throwable.
     */
    public function squash(): \Throwable
    {
        $defect = $this->defectOption();
        if ($defect !== null) {
            return $defect;
        }

        $failure = $this->failureOption();
        if ($failure instanceof \Throwable) {
            return $failure;
        }

        if ($failure !== null) {
            return new CauseException($this, (string) $failure);
        }

        if ($this->isInterrupt()) {
            return new InterruptedException();
        }

        return new CauseException($this, 'Unknown cause');
    }

    /**
     * @return Cause<never>
     */
    public static function empty(): Cause
    {
        return EmptyCause::instance();
    }

    /**
     * @template F
     * @param F $error
     * @return Cause<F>
     */
    public static function fail(mixed $error): Cause
    {
        return new Fail($error);
    }

    /**
     * @return Cause<never>
     */
    public static function defect(\Throwable $defect): Cause
    {
        return new Defect($defect);
    }

    /**
     * @return Cause<never>
     */
    public static function interrupt(string $fiberId): Cause
    {
        return new Interrupt($fiberId);
    }
}

/**
 * @extends Cause<never>
 */
final class EmptyCause extends Cause
{
    private static ?self $instance = null;

    private function __construct() {}

    public static function instance(): self
    {
        return self::$instance ??= new self();
    }

    public function tag(): string
    {
        return 'Empty';
    }

    public function isEmpty(): bool
    {
        return true;
    }

    public function isFailure(): bool
    {
        return false;
    }

    public function isDie(): bool
    {
        return false;
    }

    public function isInterrupt(): bool
    {
        return false;
    }

    public function failures(): array
    {
        return [];
    }

    public function defects(): array
    {
        return [];
    }

    public function map(Closure $f): Cause
    {
        return $this;
    }

    public function flatMap(Closure $f): Cause
    {
        return $this;
    }
}

/**
 * An expected, recoverable error occurred.
 *
 * @template-covariant E
 * @extends Cause<E>
 */
final class Fail extends Cause
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

    public function isEmpty(): bool
    {
        return false;
    }

    public function isFailure(): bool
    {
        return true;
    }

    public function isDie(): bool
    {
        return false;
    }

    public function isInterrupt(): bool
    {
        return false;
    }

    public function failures(): array
    {
        return [$this->error];
    }

    public function defects(): array
    {
        return [];
    }

    public function map(Closure $f): Cause
    {
        return new Fail($f($this->error));
    }

    public function flatMap(Closure $f): Cause
    {
        return $f($this->error);
    }
}

/**
 * An unexpected defect (unchecked error) occurred.
 *
 * @extends Cause<never>
 */
final class Defect extends Cause
{
    public function __construct(
        public readonly \Throwable $throwable,
    ) {}

    public function tag(): string
    {
        return 'Defect';
    }

    public function isEmpty(): bool
    {
        return false;
    }

    public function isFailure(): bool
    {
        return false;
    }

    public function isDie(): bool
    {
        return true;
    }

    public function isInterrupt(): bool
    {
        return false;
    }

    public function failures(): array
    {
        return [];
    }

    public function defects(): array
    {
        return [$this->throwable];
    }

    public function map(Closure $f): Cause
    {
        return $this;
    }

    public function flatMap(Closure $f): Cause
    {
        return $this;
    }
}

/**
 * The fiber was interrupted.
 *
 * @extends Cause<never>
 */
final class Interrupt extends Cause
{
    public function __construct(
        public readonly string $fiberId,
    ) {}

    public function tag(): string
    {
        return 'Interrupt';
    }

    public function isEmpty(): bool
    {
        return false;
    }

    public function isFailure(): bool
    {
        return false;
    }

    public function isDie(): bool
    {
        return false;
    }

    public function isInterrupt(): bool
    {
        return true;
    }

    public function failures(): array
    {
        return [];
    }

    public function defects(): array
    {
        return [];
    }

    public function map(Closure $f): Cause
    {
        return $this;
    }

    public function flatMap(Closure $f): Cause
    {
        return $this;
    }
}

/**
 * Sequential composition of causes.
 *
 * @template-covariant E
 * @extends Cause<E>
 */
final class Sequential extends Cause
{
    /**
     * @param Cause<E> $left
     * @param Cause<E> $right
     */
    public function __construct(
        public readonly Cause $left,
        public readonly Cause $right,
    ) {}

    public function tag(): string
    {
        return 'Sequential';
    }

    public function isEmpty(): bool
    {
        return $this->left->isEmpty() && $this->right->isEmpty();
    }

    public function isFailure(): bool
    {
        return $this->left->isFailure() || $this->right->isFailure();
    }

    public function isDie(): bool
    {
        return $this->left->isDie() || $this->right->isDie();
    }

    public function isInterrupt(): bool
    {
        return $this->left->isInterrupt() || $this->right->isInterrupt();
    }

    public function failures(): array
    {
        return array_merge($this->left->failures(), $this->right->failures());
    }

    public function defects(): array
    {
        return array_merge($this->left->defects(), $this->right->defects());
    }

    public function map(Closure $f): Cause
    {
        return new Sequential($this->left->map($f), $this->right->map($f));
    }

    public function flatMap(Closure $f): Cause
    {
        return new Sequential($this->left->flatMap($f), $this->right->flatMap($f));
    }
}

/**
 * Parallel composition of causes.
 *
 * @template-covariant E
 * @extends Cause<E>
 */
final class Parallel extends Cause
{
    /**
     * @param Cause<E> $left
     * @param Cause<E> $right
     */
    public function __construct(
        public readonly Cause $left,
        public readonly Cause $right,
    ) {}

    public function tag(): string
    {
        return 'Parallel';
    }

    public function isEmpty(): bool
    {
        return $this->left->isEmpty() && $this->right->isEmpty();
    }

    public function isFailure(): bool
    {
        return $this->left->isFailure() || $this->right->isFailure();
    }

    public function isDie(): bool
    {
        return $this->left->isDie() || $this->right->isDie();
    }

    public function isInterrupt(): bool
    {
        return $this->left->isInterrupt() || $this->right->isInterrupt();
    }

    public function failures(): array
    {
        return array_merge($this->left->failures(), $this->right->failures());
    }

    public function defects(): array
    {
        return array_merge($this->left->defects(), $this->right->defects());
    }

    public function map(Closure $f): Cause
    {
        return new Parallel($this->left->map($f), $this->right->map($f));
    }

    public function flatMap(Closure $f): Cause
    {
        return new Parallel($this->left->flatMap($f), $this->right->flatMap($f));
    }
}
