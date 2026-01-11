<?php

declare(strict_types=1);

namespace EffectPHP\Runtime;

use EffectPHP\Cause\Cause;
use EffectPHP\Cause\Interrupt as InterruptCause;
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

/**
 * Synchronous runtime for traditional PHP execution.
 * Uses a trampoline for stack safety.
 *
 * @template R
 * @implements RuntimeInterface<R>
 */
final class SyncRuntime implements RuntimeInterface
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
        return $this->interpret($effect->getOp(), $this->context);
    }

    /**
     * @template E
     * @template A
     * @template Ctx
     * @param EffectOp<Ctx, E, A> $op
     * @param Context<Ctx> $context
     * @return Exit_<E, A>
     */
    private function interpret(EffectOp $op, Context $context): Exit_
    {
        /** @var array<array{0: string, 1: mixed, 2: Context<mixed>}> $stack */
        $stack = [];
        $current = $op;
        $currentContext = $context;
        $iterations = 0;

        while (true) {
            if (++$iterations > self::MAX_ITERATIONS) {
                return Exit_::defect(new \RuntimeException('Maximum iterations exceeded - possible infinite loop'));
            }

            $result = $this->step($current, $currentContext);

            if ($result instanceof Exit_) {
                // We have a final result, apply continuations from stack
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

            // Result is [op, continuation, newContext]
            [$nextOp, $continuation, $nextContext] = $result;

            if ($continuation !== null) {
                $stack[] = [$continuation[0], $continuation[1], $currentContext];
            }

            $current = $nextOp;
            $currentContext = $nextContext;
        }
    }

    /**
     * Execute a single step.
     *
     * @template Ctx
     * @param EffectOp<Ctx, mixed, mixed> $op
     * @param Context<Ctx> $context
     * @return Exit_<mixed, mixed>|array{0: EffectOp<mixed, mixed, mixed>, 1: array{0: string, 1: mixed}|null, 2: Context<mixed>}
     */
    private function step(EffectOp $op, Context $context): Exit_|array
    {
        return match ($op->tag()) {
            'Succeed' => Exit_::succeed($op->value),
            'Fail' => Exit_::fail($op->error),
            'Defect' => Exit_::defect($op->throwable),
            'Sync' => $this->runSyncOp($op),
            'TrySync' => $this->runTrySyncOp($op),
            'Suspend' => [($op->thunk)()->getOp(), null, $context],
            'Map' => [$op->effect, ['map', $op->f], $context],
            'FlatMap' => [$op->effect, ['flatMap', $op->f], $context],
            'Fold' => [$op->effect, ['fold', [$op->onSuccess, $op->onFailure]], $context],
            'Async' => Exit_::defect(
                new \RuntimeException('Async effects not supported in SyncRuntime. Use FiberRuntime.'),
            ),
            'Never' => Exit_::defect(new \RuntimeException('Effect.never() cannot complete in SyncRuntime')),
            'Interrupt' => Exit_::failCause(Cause::interrupt('sync')),
            'Access' => $this->runAccessOp($op, $context),
            'Provide' => [$op->effect, null, $context->merge($op->context)],
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
     * @template S
     * @template B
     * @param Access<S, B> $op
     * @param Context<S> $context
     * @return Exit_<never, B>
     */
    private function runAccessOp(Access $op, Context $context): Exit_
    {
        try {
            $service = $context->get($op->serviceTag);
            return Exit_::succeed(($op->f)($service));
        } catch (\Throwable $e) {
            return Exit_::defect($e);
        }
    }

    /**
     * Apply a continuation to an Exit value.
     *
     * @template Ctx
     * @param string $type
     * @param mixed $args
     * @param Exit_<mixed, mixed> $exit
     * @param Context<Ctx> $context
     * @return Exit_<mixed, mixed>|EffectOp<mixed, mixed, mixed>
     */
    private function applyContinuation(string $type, mixed $args, Exit_ $exit, Context $context): Exit_|EffectOp
    {
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
