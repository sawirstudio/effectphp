<?php

declare(strict_types=1);

namespace EffectPHP\Tests\Unit;

use EffectPHP\Context\Context;
use EffectPHP\Context\Tag;
use EffectPHP\Effect\Effect;
use EffectPHP\Runtime\FiberRuntime;
use EffectPHP\Runtime\SyncRuntime;
use PHPUnit\Framework\TestCase;

use function EffectPHP\all;
use function EffectPHP\fail;
use function EffectPHP\gen;
use function EffectPHP\runSync;
use function EffectPHP\succeed;
use function EffectPHP\sync;
use function EffectPHP\trySync;

final class EffectTest extends TestCase
{
    public function testSucceed(): void
    {
        $effect = Effect::succeed(42);
        $result = (new SyncRuntime())->runSync($effect);

        $this->assertSame(42, $result);
    }

    public function testFail(): void
    {
        $effect = Effect::fail('error');
        $exit = (new SyncRuntime())->runSyncExit($effect);

        $this->assertTrue($exit->isFailure());
    }

    public function testMap(): void
    {
        $effect = Effect::succeed(5)->map(fn($n) => $n * 2);

        $result = runSync($effect);
        $this->assertSame(10, $result);
    }

    public function testFlatMap(): void
    {
        $effect = Effect::succeed(5)->flatMap(fn($n) => Effect::succeed($n + 3));

        $result = runSync($effect);
        $this->assertSame(8, $result);
    }

    public function testCatchAll(): void
    {
        $effect = Effect::fail('original error')->catchAll(fn($e) => Effect::succeed('recovered'));

        $result = runSync($effect);
        $this->assertSame('recovered', $result);
    }

    public function testSync(): void
    {
        $called = false;
        $effect = Effect::sync(function () use (&$called) {
            $called = true;
            return 'done';
        });

        $this->assertFalse($called);
        $result = runSync($effect);
        $this->assertTrue($called);
        $this->assertSame('done', $result);
    }

    public function testTrySync(): void
    {
        $effect = Effect::trySync(fn() => throw new \RuntimeException('boom'), fn(\Throwable $e) => $e->getMessage());

        $exit = (new SyncRuntime())->runSyncExit($effect);
        $this->assertTrue($exit->isFailure());
    }

    public function testZip(): void
    {
        $effect = Effect::succeed(1)->zip(Effect::succeed(2));

        $result = runSync($effect);
        $this->assertSame([1, 2], $result);
    }

    public function testHelperFunctions(): void
    {
        $effect = succeed(10)->map(fn($n) => $n * 3);
        $this->assertSame(30, runSync($effect));
    }

    public function testAll(): void
    {
        $effect = all([
            succeed(1),
            succeed(2),
            succeed(3),
        ]);

        $this->assertSame([1, 2, 3], runSync($effect));
    }

    public function testGen(): void
    {
        $effect = gen(function () {
            $a = yield succeed(1);
            $b = yield succeed(2);
            $c = yield succeed($a + $b);
            return $c * 2;
        });

        $this->assertSame(6, runSync($effect));
    }

    public function testService(): void
    {
        $configTag = Tag::of(\stdClass::class);

        $config = new \stdClass();
        $config->value = 'test-value';

        $effect = Effect::getService($configTag)->map(fn($cfg) => $cfg->value);

        $runtime = (new SyncRuntime())->withContext(Context::empty()->add($configTag, $config));

        $result = $runtime->runSync($effect);
        $this->assertSame('test-value', $result);
    }

    public function testOrElse(): void
    {
        $effect = Effect::fail('error')->orElse(Effect::succeed('fallback'));

        $this->assertSame('fallback', runSync($effect));
    }

    public function testFiberRuntime(): void
    {
        $effect = Effect::succeed(42)->map(fn($n) => $n * 2);

        $result = (new FiberRuntime())->runSync($effect);
        $this->assertSame(84, $result);
    }

    public function testChaining(): void
    {
        $effect = succeed(1)
            ->map(fn($n) => $n + 1)
            ->flatMap(fn($n) => succeed($n * 2))
            ->tap(fn($n) => null)
            ->as('done');

        $this->assertSame('done', runSync($effect));
    }
}
