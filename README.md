# KQueue

A non-blocking queue runtime for Laravel — powered by Swoole coroutines.

Run thousands of jobs concurrently on a single thread. No changes to your existing job code.

> **Community Testing** — core runtime is complete. Install, test, report issues.

---

## How it works

Laravel's default queue worker processes one job at a time per worker process. To get concurrency you have to spawn more workers, which means more RAM and more OS overhead.

KQueue replaces the execution engine with a Swoole coroutine runtime:

- Enables `SWOOLE_HOOK_ALL` at startup — `sleep()`, DB queries, HTTP calls, file I/O are all automatically non-blocking
- Each job runs in its own coroutine on a single thread
- While Job A is waiting for a DB response, Job B runs — no blocking, no extra processes
- CPU-heavy jobs are automatically routed to isolated child processes

```
Traditional workers (3 jobs x 2s I/O each):
Job 1: [====2s====]
Job 2:             [====2s====]
Job 3:                         [====2s====]
Total: 6 seconds

KQueue (same 3 jobs, 1 thread):
Job 1: [====2s====]
Job 2: [====2s====]
Job 3: [====2s====]
Total: ~2 seconds
```

---

## What KQueue is NOT

- Not a queue backend (does not replace Redis, SQS, or the database driver)
- Not Laravel Horizon (no dashboard)
- Not Laravel Octane (does not replace the HTTP server)
- Not a new job syntax

KQueue only controls **how jobs are executed**, not where they are stored.

---

## Requirements

- PHP 8.1+
- Laravel 10, 11, or 12
- Swoole extension 5.0+

---

## Installation

### Option A — One-line installer (recommended)

Detects your Linux distro, installs Swoole, updates php.ini, and installs the Composer package automatically.

Run from inside your Laravel project root:

```bash
curl -fsSL https://raw.githubusercontent.com/kunalGhoshOne/kqueue/main/install.sh | sudo bash
```

Supports: Ubuntu, Debian, CentOS, RHEL, Fedora, Rocky, AlmaLinux, Alpine, macOS.

After it finishes:

```bash
php artisan kqueue:work
```

---

### Option B — Manual installation

#### Step 1 — Install Swoole (once per machine/server)

```bash
# Ubuntu / Debian
apt install php-swoole

# PECL (any distro)
pecl install swoole

# Docker — add to your Dockerfile
RUN pecl install swoole && docker-php-ext-enable swoole
```

Verify it is installed:

```bash
php -m | grep swoole
```

#### Step 2 — Install KQueue

```bash
composer require kqueue/kqueue
```

Laravel auto-discovers the service provider. No manual registration needed.

#### Step 3 — (Optional) Publish config

```bash
php artisan vendor:publish --tag=kqueue-config
```

Only needed if you want to customise timeouts, memory limits, or the singleton reset list. Sensible defaults apply without publishing.

#### Step 4 — Run the worker

```bash
php artisan kqueue:work
```

That's it. Your existing jobs run concurrently without any code changes.

---

## Your jobs — zero changes required

```php
class SendEmailJob extends Job implements ShouldQueue
{
    public function handle()
    {
        sleep(2);                               // non-blocking
        Http::post('https://api.example.com');  // non-blocking
        DB::table('logs')->insert([...]);        // non-blocking
        Mail::to($this->user)->send(new Welcome()); // non-blocking
    }
}
```

No `async`, no `await`, no coroutine keywords. KQueue handles all of it transparently.

---

## Worker command options

```bash
php artisan kqueue:work [connection] [options]
```

| Option | Default | Description |
|---|---|---|
| `connection` | `queue.default` | Queue connection name (redis, sqs, database) |
| `--queue` | `default` | Queue name to consume |
| `--sleep` | `100` | Milliseconds between polls when queue is empty |
| `--timeout` | `60` | Seconds a job can run before timing out |
| `--memory` | `512` | Runtime memory limit in MB |
| `--max-jobs` | `0` | Stop after N jobs (0 = run forever) |
| `--max-time` | `0` | Stop after N seconds (0 = run forever) |
| `--inline` | off | Disable process isolation, run all jobs as coroutines |
| `--smart` | on | Auto-detect strategy per job (default) |
| `--secure` | on | Enable hardened runtime with rate limiting |

Examples:

```bash
# Use a specific connection and queue
php artisan kqueue:work redis --queue=emails

# Stop after 1000 jobs (useful with Supervisor restart)
php artisan kqueue:work --max-jobs=1000

# Force all jobs inline (no child processes)
php artisan kqueue:work --inline
```

---

## Execution strategies

KQueue automatically picks the right strategy for each job.

| Strategy | When | How |
|---|---|---|
| **Inline coroutine** | I/O-bound jobs (email, HTTP, DB, cache) | Runs in a Swoole coroutine on the main thread. SWOOLE_HOOK_ALL makes all blocking calls non-blocking. |
| **Isolated process** | CPU-bound jobs (image/video processing, heavy computation) | Spawns a child process. Coroutine suspends non-blocking while waiting for the process to finish. |

### Controlling the strategy

By default, `SmartRuntime` analyses each job automatically. You can also set it explicitly on the job class:

```php
class SendEmailJob extends KQueueJob
{
    // Run inline as a coroutine (I/O-bound — default for fast jobs)
    public ?bool $isolated = false;

    public function handle(): void { ... }
}

class ProcessVideoJob extends KQueueJob
{
    // Run in an isolated child process (CPU-bound)
    public ?bool $isolated = true;

    public function handle(): void { ... }
}

class AutoDetectJob extends KQueueJob
{
    // null = let KQueue decide automatically (default)
    public ?bool $isolated = null;

    public function handle(): void { ... }
}
```

---

## Swoole issues — handled automatically

Swoole has known pitfalls when running long-lived PHP processes. KQueue fixes all of them without any action from you.

| Issue | Cause | How KQueue fixes it |
|---|---|---|
| **Global state leaks** | Static variables persist between jobs | `SwooleStateManager` snapshots `$GLOBALS` before each job and restores after |
| **Singleton leaks** | Same Laravel service instance reused across jobs | Container instances (`auth`, `db`, `cache`, `session`) are flushed before each job |
| **Non-hookable extensions** | Some C extensions bypass PHP's stream layer | Detected at startup with a clear warning; affected jobs auto-routed to isolated processes |
| **sleep() outside coroutine** | Hooks only work inside a coroutine context | `SWOOLE_HOOK_ALL` + `Swoole\Coroutine\run()` are always enabled before any job code runs |

### Customising the singleton reset list

If you observe stale state from your own services between jobs, add them to the reset list in `config/kqueue.php`:

```php
'swoole' => [
    'resettable_singletons' => [
        'auth',
        'db',
        'cache',
        'session',
        'your-custom-service',  // add yours here
    ],
],
```

---

## Configuration reference

After publishing (`php artisan vendor:publish --tag=kqueue-config`), the file lives at `config/kqueue.php`.

```php
'runtime' => [
    'memory_limit' => 512,   // MB — total runtime memory cap
    'smart'        => true,  // auto-detect strategy per job
    'secure'       => true,  // validation, rate limiting, sanitized logs
],

'analysis' => [
    'inline_threshold' => 1.0,   // jobs estimated <= 1s run inline
    'pooled_threshold' => 30.0,  // jobs estimated <= 30s run pooled
    // jobs over 30s run isolated
],

'jobs' => [
    'default_timeout'     => 60,    // seconds
    'default_memory'      => 128,   // MB
    'isolated_by_default' => true,  // true = concurrent, false = sequential
    'max_timeout'         => 300,   // server-side hard limit
    'max_memory'          => 512,   // server-side hard limit
    'max_concurrent'      => 100,   // max jobs running at the same time
],

'security' => [
    // Whitelist job class file paths (empty = allow all)
    'allowed_job_paths'   => [app_path('Jobs')],
    'max_jobs_per_minute' => 1000,
],
```

Environment variables:

```env
KQUEUE_CONNECTION=redis
KQUEUE_QUEUE=default
KQUEUE_MEMORY=512
KQUEUE_SMART=true
KQUEUE_SECURE=true
KQUEUE_INLINE_THRESHOLD=1.0
KQUEUE_POOLED_THRESHOLD=30.0
```

---

## Running with Supervisor

```ini
[program:kqueue-worker]
command=php /var/www/artisan kqueue:work redis --queue=default --max-jobs=1000
directory=/var/www
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
user=www-data
numprocs=1
redirect_stderr=true
stdout_logfile=/var/log/kqueue-worker.log
```

Note: because KQueue handles concurrency internally with coroutines, you typically need far fewer worker processes than with a traditional `queue:work` setup. Start with `numprocs=1` and scale up only if you have CPU-bound jobs that need true parallelism.

---

## Project status

**v0.2.0 — Community Testing**

The core runtime is complete. We are looking for community testers to run KQueue against real Laravel applications and report issues before the stable release.

**What is done:**

| Feature | Status |
|---|---|
| Swoole coroutine runtime | Done |
| SWOOLE_HOOK_ALL (non-blocking I/O) | Done |
| Automatic state isolation per job | Done |
| Singleton reset per job | Done |
| Non-hookable extension detection | Done |
| Artisan command (`kqueue:work`) | Done |
| Laravel queue driver integration | Done |
| Smart strategy auto-detection | Done |
| Process isolation for CPU jobs | Done |
| Security hardening | Done |
| Graceful shutdown (SIGTERM/SIGINT) | Done |
| Memory monitoring | Done |

**What is planned before stable:**

| Feature | Status |
|---|---|
| Job retries and failed job handling | Planned |
| Unit test coverage | Planned |
| Process pooling | Planned |
| Observability hooks | Planned |

**How to help:**

- Install KQueue in a Laravel project and run `php artisan kqueue:work`
- Dispatch some jobs and confirm they run concurrently
- Report any bugs, unexpected behaviour, or edge cases at [GitHub Issues](https://github.com/kunalGhoshOne/kqueue/issues)
- If your jobs involve DB queries, HTTP calls, or `sleep()` — those are the most valuable test cases

---

## Design philosophy

- **Runtime over workers** — one smart process beats many dumb ones
- **Transparent** — developers write normal PHP, KQueue handles the concurrency
- **Isolation where it matters** — CPU-bound work runs in child processes, I/O-bound work runs as coroutines
- **Production first** — security validation, rate limiting, and memory enforcement are on by default

---

## Security

All job class paths are validated against an allowlist before process isolation. Job properties are serialized with JSON (not `serialize()`) in secure mode to prevent PHP object injection attacks.

See [SECURITY.md](SECURITY.md) for full details. See [SMART_ANALYZER.md](SMART_ANALYZER.md) for how the automatic strategy selector works.

---

## License

MIT

## Author

Kunal Ghosh — kunalghosh10000@gmail.com
