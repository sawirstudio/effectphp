# Effect PHP

A functional effects library for PHP 8.1+ inspired by [Effect-TS](https://effect.website/). Provides typed error handling, composable computations, and resource safety.

## Installation

```bash
composer require effectphp/effect
```

Requires PHP 8.1 or higher.

## Quick Start

```php
use EffectPHP\Effect\Effect;
use EffectPHP\Runtime\SyncRuntime;
use function EffectPHP\{succeed, fail, trySync, gen, runSync};

// Create effects
$effect = succeed(42)
    ->map(fn($n) => $n * 2)
    ->flatMap(fn($n) => succeed($n + 1));

// Run synchronously
$result = runSync($effect); // 85

// Handle errors
$safe = fail('something went wrong')
    ->catchAll(fn($error) => succeed('recovered'))
    ->map(fn($msg) => strtoupper($msg));

echo runSync($safe); // "RECOVERED"
```

## Core Concepts

### Effect<R, E, A>

The `Effect` type represents a lazy, composable computation that:
- **R** - Requires an environment/context (dependencies)
- **E** - May fail with an error of type E (expected failures)
- **A** - May succeed with a value of type A

Effects are lazy - they describe computations but don't execute until you run them.

```php
// Succeeds with a value
$success = Effect::succeed(42);

// Fails with an error
$failure = Effect::fail('error message');

// Wraps a side effect
$effect = Effect::sync(fn() => file_get_contents('file.txt'));

// Wraps a fallible operation
$safe = Effect::trySync(
    fn() => json_decode($input, true, 512, JSON_THROW_ON_ERROR),
    fn(\Throwable $e) => new JsonParseError($e->getMessage())
);
```

### Cause<E>

`Cause` represents the full story of why an effect failed. It distinguishes between:

- **Fail** - Expected, recoverable errors (your error type E)
- **Defect** - Unexpected errors (thrown exceptions)
- **Interrupt** - Fiber interruption

```php
$exit = runSyncExit($effect);

if ($exit->isFailure()) {
    $cause = $exit->causeOption();

    if ($cause->isFailure()) {
        $error = $cause->failureOption(); // Your typed error
    } elseif ($cause->isDie()) {
        $defect = $cause->defectOption(); // Throwable
    }
}
```

### Exit<E, A>

`Exit` represents the result of running an effect - either `Success<A>` or `Failure<E>`.

```php
use EffectPHP\Runtime\SyncRuntime;

$runtime = new SyncRuntime();
$exit = $runtime->runSyncExit($effect);

$result = $exit->match(
    onSuccess: fn($value) => "Got: $value",
    onFailure: fn($cause) => "Failed: " . $cause->squash()->getMessage()
);
```

## Transformations

### map / flatMap

```php
$effect = succeed(5)
    ->map(fn($n) => $n * 2)           // Transform success value
    ->flatMap(fn($n) => succeed($n)); // Chain effects
```

### tap

Execute a side effect without changing the value:

```php
$effect = succeed(42)
    ->tap(fn($n) => print("Value: $n"));
```

### zip

Combine effects:

```php
$effect = succeed(1)->zip(succeed(2));
// Result: [1, 2]

$effect = succeed(1)->zipWith(succeed(2), fn($a, $b) => $a + $b);
// Result: 3
```

## Error Handling

### catchAll

Recover from all errors:

```php
$effect = fail('error')
    ->catchAll(fn($e) => succeed('default'));
```

### catchTag

Recover from specific error types:

```php
class NotFoundError extends Exception {}
class ValidationError extends Exception {}

$effect = fetchUser($id)
    ->catchTag(NotFoundError::class, fn($e) => succeed($defaultUser))
    ->catchTag(ValidationError::class, fn($e) => fail(new BadRequest()));
```

### mapError

Transform error type:

```php
$effect = fail('raw error')
    ->mapError(fn($e) => new DomainError($e));
```

### orElse / orElseSucceed

Fallback on error:

```php
$effect = fail('error')->orElse(succeed('fallback'));
$effect = fail('error')->orElseSucceed('default value');
```

### orDie

Convert expected errors to defects:

```php
$effect = fetchUser($id)->orDie(); // Throws on error
```

## Do-Notation

Use generators for sequential composition:

```php
use function EffectPHP\gen;

$program = gen(function () {
    $user = yield fetchUser($userId);
    $posts = yield fetchPosts($user->id);
    $validated = yield validatePosts($posts);

    return [
        'user' => $user,
        'posts' => $validated,
    ];
});
```

## Dependency Injection

Use `Context` and `Tag` for dependency injection:

```php
use EffectPHP\Context\Tag;
use EffectPHP\Context\Context;

// Define service tags
$dbTag = Tag::of(Database::class);
$loggerTag = Tag::of(Logger::class);

// Access services in effects
$program = Effect::getService($dbTag)
    ->flatMap(fn($db) => Effect::trySync(fn() => $db->query('SELECT * FROM users')));

// Provide services at runtime
$context = Context::empty()
    ->add($dbTag, new PostgresDatabase())
    ->add($loggerTag, new FileLogger());

$runtime = (new SyncRuntime())->withContext($context);
$result = $runtime->runSync($program);
```

## Combinators

### All

Run multiple effects:

```php
use EffectPHP\Combinators\All;

// Sequential execution
$results = All::seq([
    fetchUser(1),
    fetchUser(2),
    fetchUser(3),
]);

// First success
$result = All::firstSuccess([
    fetchFromCache($key),
    fetchFromDatabase($key),
    fetchFromRemote($key),
]);
```

### Retry

Retry failed effects:

```php
use EffectPHP\Combinators\Retry;
use EffectPHP\Combinators\RetryPolicy;

// Retry 3 times with exponential backoff
$effect = Retry::retry(
    fetchData(),
    RetryPolicy::exponential(retries: 3, baseDelayMs: 100)
);

// Simple retry
$effect = Retry::retryN(fetchData(), times: 5);
```

### Timing

```php
use EffectPHP\Combinators\Timing;

// Delay execution
$effect = Timing::delay(1000)->flatMap(fn() => doSomething());

// Measure duration
$effect = Timing::timed(fetchData());
// Result: ['value' => $data, 'durationMs' => 123.45]

// Repeat
$effect = Timing::repeatN(ping(), times: 5);
```

## Resource Management

Safely acquire and release resources:

```php
use EffectPHP\Resource\AcquireRelease;

$program = AcquireRelease::bracket(
    acquire: Effect::sync(fn() => fopen('file.txt', 'r')),
    release: fn($handle) => Effect::sync(fn() => fclose($handle)),
    use: fn($handle) => Effect::trySync(fn() => fread($handle, 1024))
);
```

The release function is guaranteed to run even if the use function fails.

## Runtimes

### SyncRuntime

Traditional synchronous execution:

```php
use EffectPHP\Runtime\SyncRuntime;

$runtime = new SyncRuntime();
$result = $runtime->runSync($effect);      // Returns value or throws
$exit = $runtime->runSyncExit($effect);    // Returns Exit<E, A>
```

### FiberRuntime

Fiber-based execution with async support:

```php
use EffectPHP\Runtime\FiberRuntime;

$runtime = new FiberRuntime();
$result = $runtime->runSync($effect);

// With callback
$runtime->runCallback($effect, function ($exit) {
    // Handle result
});
```

## Helper Functions

Global functions for convenience:

```php
use function EffectPHP\{
    succeed,      // Effect::succeed()
    fail,         // Effect::fail()
    defect,       // Effect::defect()
    sync,         // Effect::sync()
    trySync,      // Effect::trySync()
    suspend,      // Effect::suspend()
    async,        // Effect::async()
    service,      // Effect::getService()
    all,          // All::all()
    traverse,     // Map and collect
    delay,        // Timing::delay()
    sleep,        // Timing::sleep()
    retry,        // Retry::retry()
    bracket,      // AcquireRelease::bracket()
    gen,          // Do-notation
    pipe,         // Pipe helper
    runSync,      // Quick run with SyncRuntime
    runSyncExit,  // Quick run returning Exit
    runFiber,     // Quick run with FiberRuntime
};
```

## Example: HTTP Client

```php
use function EffectPHP\{gen, trySync, fail, succeed, retry, runSync};

class HttpError extends Exception {
    public function __construct(public int $status, string $message) {
        parent::__construct($message);
    }
}

function httpGet(string $url): Effect {
    return trySync(
        fn() => file_get_contents($url),
        fn($e) => new HttpError(0, $e->getMessage())
    )->flatMap(fn($body) => $body === false
        ? fail(new HttpError(404, 'Not found'))
        : succeed($body)
    );
}

function fetchJson(string $url): Effect {
    return httpGet($url)
        ->flatMap(fn($body) => trySync(
            fn() => json_decode($body, true, 512, JSON_THROW_ON_ERROR),
            fn($e) => new HttpError(0, 'Invalid JSON')
        ));
}

// Usage
$program = gen(function () {
    $users = yield retry(fetchJson('https://api.example.com/users'), 3);
    $posts = yield fetchJson("https://api.example.com/posts?userId={$users[0]['id']}");

    return ['user' => $users[0], 'posts' => $posts];
});

try {
    $result = runSync($program);
    print_r($result);
} catch (HttpError $e) {
    echo "HTTP Error {$e->status}: {$e->getMessage()}";
}
```

## License

MIT
