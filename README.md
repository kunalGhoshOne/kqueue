# KQueue

A Node.js-style, event-loop based runtime for Laravel queues — without changing how you write jobs.

## Why KQueue exists

Laravel queues today are process-based and linear:

- One job blocks one worker
- Concurrency requires multiple workers
- Supervisor blindly restarts crashed workers
- One bad job can starve the queue
- Memory leaks kill entire workers

This model works, but it does not scale cleanly for I/O-heavy or mixed workloads.

## What KQueue is

KQueue is a **queue runtime**, not a queue driver.

It replaces the execution engine behind `php artisan queue:work` with a long-running, event-loop driven daemon that:

- Executes jobs concurrently
- Prevents one job from blocking others
- Isolates heavy or unsafe jobs in child processes
- Monitors memory and execution time
- Keeps Laravel job APIs unchanged

## What KQueue is NOT

- ❌ Not a new queue backend
- ❌ Not a Redis/SQS replacement
- ❌ Not Laravel Horizon
- ❌ Not Octane or Swoole
- ❌ Not a framework replacement

KQueue only controls **how jobs are executed**, not where they are stored.

## How it works

1. Laravel pushes jobs to the queue as usual
2. KQueue pulls jobs using Laravel queue contracts
3. Jobs are scheduled by an internal event loop
4. Lightweight jobs run inline
5. Heavy or unsafe jobs run in isolated child processes
6. The daemon monitors health, memory, and execution

## Job compatibility

KQueue is fully compatible with Laravel jobs.

You write jobs the same way:

```php
class SendEmail extends KQueueJob
{
    public bool $isolated = false;

    public function handle()
    {
        // job logic
    }
}
```

No new syntax. No async keywords. No learning curve.

## Project status

⚠️ This project is currently in **Alpha** stage.

**What works:**
- ✅ Event-loop based runtime (ReactPHP)
- ✅ Concurrent job execution
- ✅ Process isolation (child process spawning)
- ✅ Job class definitions (Laravel-compatible)
- ✅ Security hardening (production-ready validation)
- ✅ Graceful shutdown (signal handling)
- ✅ Memory monitoring and timeout enforcement

**What's missing for production use:**
- ❌ Artisan command (`php artisan kqueue:run`)
- ❌ Queue driver adapter (cannot pull from Redis/SQS/DB)
- ❌ Job retries and failed job handling
- ❌ Unit test coverage
- ❌ Configuration publishing
- ❌ Process pooling
- ❌ Observability hooks

**Can I use this now?**
- ✅ For testing the concept and understanding the architecture
- ✅ For demos and proof-of-concept projects
- ❌ NOT for production deployment (missing critical queue integration)

## Why not just run more workers?

Running more workers increases:
- Memory usage
- Process overhead
- Operational complexity

KQueue aims to provide:
- Better resource utilization
- Safer execution
- Predictable behavior

## Installation

⚠️ **Alpha Software - Not Production Ready**

This package is available on Packagist for testing and evaluation purposes only.

```bash
composer require kqueue/kqueue:^0.1.0-alpha
```

**Note:** KQueue currently lacks critical features for production use (queue driver integration, Artisan commands, job retries). It's intended for:
- Understanding the architecture
- Testing the concept
- Contributing to development

### Running the Examples

```bash
composer install
php examples/demo.php
```

See [LARAVEL_TEST_RESULTS.md](LARAVEL_TEST_RESULTS.md) for Laravel integration tests and [SECURITY.md](SECURITY.md) for security documentation.

## Roadmap

- **v0.1.0-alpha** (current): Core runtime and execution strategies
- **v0.2.0-alpha**: Artisan commands and queue driver adapter
- **v0.3.0-beta**: Job retries, failed job handling, and test coverage
- **v0.4.0-beta**: Process pooling and configuration system
- **v1.0.0**: Production-ready runtime with full Laravel integration

## Design philosophy

- Runtime > workers
- Isolation over blind concurrency
- Compatibility over clever APIs
- Explicit execution strategies
- Production first

## Security

KQueue has undergone security analysis and hardening. All critical vulnerabilities (RCE, path injection, DoS) have been identified and fixed.

See [SECURITY.md](SECURITY.md) for complete security documentation.

## License

MIT

## Author

Kunal Ghosh (kunalghosh10000@gmail.com)
