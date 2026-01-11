<?php

declare(strict_types=1);

namespace EffectPHP\Runtime;

use Closure;
use EffectPHP\Cause\Cause;
use EffectPHP\Context\Context;
use EffectPHP\Effect\Access;
use EffectPHP\Effect\Async;
use EffectPHP\Effect\Defect as DefectOp;
use EffectPHP\Effect\Effect;
use EffectPHP\Effect\EffectOp;
use EffectPHP\Effect\Fail;
use EffectPHP\Effect\FlatMap;
use EffectPHP\Effect\Fold;
use EffectPHP\Effect\Interrupt;
use EffectPHP\Effect\Map;
use EffectPHP\Effect\NeverOp;
use EffectPHP\Effect\Provide;
use EffectPHP\Effect\Succeed;
use EffectPHP\Effect\Suspend;
use EffectPHP\Effect\Sync;
use EffectPHP\Effect\TrySync;
use EffectPHP\Exit\Exit_;
use EffectPHP\Runtime\Fiber\Deferred;
use EffectPHP\Runtime\Fiber\FiberContext;
use EffectPHP\Runtime\Fiber\FiberId;
use Fiber;

/**
 * Fiber-based runtime for async effect execution.
 * Uses PHP 8.1 Fibers for cooperative multitasking.
 *
 * @template R
 * @implements RuntimeInterface<R>
 */
final class FiberRuntime implements RuntimeInterface
{
    private const MAX_ITERATIONS = 100000;

    /**
     * @param Context<R> $context
     */
    public function __construct(
        private readonly Context $context = new Context(),
    ) {}

    public function runSync(Effect $effect): mixed
    {
        return $this->runSyncExit($effect)->getOrThrow();
    }

    public function runSyncExit(Effect $effect): Exit_
    {
        $fiberId = FiberId::generate();
        $fiberContext = new FiberContext($fiberId, $this->context);

        return $this->interpret($effect->getOp(), $fiberContext);
    }

    /**
     * Run an Effect with a callback for the result.
     *
     * @template E
     * @template A
     * @param Effect<R, E, A> $effect
     * @param Closure(Exit_<E, A>): void $callback
     * @return FiberId
     */
    public function runCallback(Effect $effect, Closure $callback): FiberId
    {
        $fiberId = FiberId::generate();
        $fiberContext = new FiberContext($fiberId, $this->context);

        $fiber = new Fiber(function () use ($effect, $callback, $fiberContext) {
            $exit = $this->interpret($effect->getOp(), $fiberContext);
            $fiberContext->runFinalizers();
            $callback($exit);
            return $exit;
        });

        $fiber->start();

        // Run to completion if not suspended
        while (!$fiber->isTerminated()) {
            if ($fiber->isSuspended()) {
                $fiber->resume();
            }
        }

        return $fiberId;
    }

    /**
     * Run an Effect returning a Deferred result.
     *
     * @template E
     * @template A
     * @param Effect<R, E, A> $effect
     * @return Deferred<E, A>
     */
    public function runDeferred(Effect $effect): Deferred
    {
        /** @var Deferred<E, A> $deferred */
        $deferred = new Deferred();

        $this->runCallback($effect, function (Exit_ $exit) use ($deferred) {
            $deferred->complete($exit);
        });

        return $deferred;
    }

    /**
     * @template E
     * @template A
     * @template Ctx
     * @param EffectOp<Ctx, E, A> $op
     * @param FiberContext<Ctx> $fiberContext
     * @return Exit_<E, A>
     */
    private function interpret(EffectOp $op, FiberContext $fiberContext): Exit_
    {
        /** @var array<array{0: string, 1: mixed, 2: FiberContext<mixed>}> $stack */
        $stack = [];
        $current = $op;
        $currentContext = $fiberContext;
        $iterations = 0;

        while (true) {
            if (++$iterations > self::MAX_ITERATIONS) {
                return Exit_::defect(new \RuntimeException('Maximum iterations exceeded'));
            }

            // Check for interruption
            if ($currentContext->isInterrupted()) {
                $result = Exit_::failCause(Cause::interrupt((string) $currentContext->fiberId));
            } else {
                $result = $this->step($current, $currentContext);
            }

            if ($result instanceof Exit_) {
                while (!empty($stack)) {
                    $frame = array_pop($stack);
                    $continuation = $frame[0];
                    $args = $frame[1];
                    $frameContext = $frame[2];

                    $next = $this->applyContinuation($continuation, $args, $result, $frameContext);

                    if ($next instanceof EffectOp) {
                        $current = $next;
                        $currentContext = $frameContext;
                        continue 2;
                    }

                    $result = $next;
                }

                return $result;
            }

            [$nextOp, $continuation, $nextContext] = $result;

            if ($continuation !== null) {
                $stack[] = [$continuation[0], $continuation[1], $currentContext];
            }

            $current = $nextOp;
            $currentContext = $nextContext;
        }
    }

    /**
     * @template Ctx
     * @param EffectOp<Ctx, mixed, mixed> $op
     * @param FiberContext<Ctx> $fiberContext
     * @return Exit_<mixed, mixed>|array{0: EffectOp<mixed, mixed, mixed>, 1: array{0: string, 1: mixed}|null, 2: FiberContext<mixed>}
     */
    private function step(EffectOp $op, FiberContext $fiberContext): Exit_|array
    {
        return match ($op->tag()) {
            'Succeed' => Exit_::succeed($op->value),
            'Fail' => Exit_::fail($op->error),
            'Defect' => Exit_::defect($op->throwable),
            'Sync' => $this->runSyncOp($op),
            'TrySync' => $this->runTrySyncOp($op),
            'Suspend' => [($op->thunk)()->getOp(), null, $fiberContext],
            'Map' => [$op->effect, ['map', $op->f], $fiberContext],
            'FlatMap' => [$op->effect, ['flatMap', $op->f], $fiberContext],
            'Fold' => [$op->effect, ['fold', [$op->onSuccess, $op->onFailure]], $fiberContext],
            'Async' => $this->runAsyncOp($op, $fiberContext),
            'Never' => $this->runNever($fiberContext),
            'Interrupt' => Exit_::failCause(Cause::interrupt((string) $fiberContext->fiberId)),
            'Access' => $this->runAccessOp($op, $fiberContext),
            'Provide' => [$op->effect, null, $fiberContext->withContext($op->context)],
            default => Exit_::defect(new \RuntimeException("Unknown op: {$op->tag()}")),
        };
    }

    /**
     * @param Sync<mixed> $op
     * @return Exit_<never, mixed>
     */
    private function runSyncOp(Sync $op): Exit_
    {
        try {
            return Exit_::succeed(($op->thunk)());
        } catch (\Throwable $e) {
            return Exit_::defect($e);
        }
    }

    /**
     * @param TrySync<mixed, mixed> $op
     * @return Exit_<mixed, mixed>
     */
    private function runTrySyncOp(TrySync $op): Exit_
    {
        try {
            return Exit_::succeed(($op->thunk)());
        } catch (\Throwable $e) {
            $error = $op->catch !== null ? ($op->catch)($e) : $e;
            return Exit_::fail($error);
        }
    }

    /**
     * @template E
     * @template A
     * @param Async<E, A> $op
     * @param FiberContext<mixed> $fiberContext
     * @return Exit_<E, A>
     */
    private function runAsyncOp(Async $op, FiberContext $fiberContext): Exit_
    {
        /** @var Exit_<E, A>|null $result */
        $result = null;

        $callback = function (Exit_ $exit) use (&$result) {
            $result = $exit;
        };

        // Register the async operation
        ($op->register)($callback);

        // If callback was called synchronously, return immediately
        if ($result !== null) {
            return $result;
        }

        // Otherwise, suspend the fiber and wait
        if (Fiber::getCurrent() !== null) {
            while ($result === null && !$fiberContext->isInterrupted()) {
                Fiber::suspend();
            }
        }

        if ($result === null) {
            if ($fiberContext->isInterrupted()) {
                return Exit_::failCause(Cause::interrupt((string) $fiberContext->fiberId));
            }
            return Exit_::defect(new \RuntimeException('Async callback was never invoked'));
        }

        return $result;
    }

    /**
     * @param FiberContext<mixed> $fiberContext
     * @return Exit_<never, never>
     */
    private function runNever(FiberContext $fiberContext): Exit_
    {
        // Suspend indefinitely until interrupted
        if (Fiber::getCurrent() !== null) {
            while (!$fiberContext->isInterrupted()) {
                Fiber::suspend();
            }
        }

        return Exit_::failCause(Cause::interrupt((string) $fiberContext->fiberId));
    }

    /**
     * @template S
     * @template B
     * @param Access<S, B> $op
     * @param FiberContext<S> $fiberContext
     * @return Exit_<never, B>
     */
    private function runAccessOp(Access $op, FiberContext $fiberContext): Exit_
    {
        try {
            $service = $fiberContext->context->get($op->serviceTag);
            return Exit_::succeed(($op->f)($service));
        } catch (\Throwable $e) {
            return Exit_::defect($e);
        }
    }

    /**
     * @param string $type
     * @param mixed $args
     * @param Exit_<mixed, mixed> $exit
     * @param FiberContext<mixed> $fiberContext
     * @return Exit_<mixed, mixed>|EffectOp<mixed, mixed, mixed>
     */
    private function applyContinuation(
        string $type,
        mixed $args,
        Exit_ $exit,
        FiberContext $fiberContext,
    ): Exit_|EffectOp {
        return match ($type) {
            'map' => $exit->isSuccess() ? Exit_::succeed($args($exit->value)) : $exit,
            'flatMap' => $exit->isSuccess() ? $args($exit->value)->getOp() : $exit,
            'fold' => $exit->isSuccess()
                ? $args[0]($exit->value)->getOp()
                : $args[1]($exit->causeOption() ?? Cause::empty())->getOp(),
            default => $exit,
        };
    }

    public function context(): Context
    {
        return $this->context;
    }

    public function withContext(Context $context): RuntimeInterface
    {
        return new self($this->context->merge($context));
    }
}
