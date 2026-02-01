# KQueue - Node.js-style Queue Runtime for Laravel

A production-grade runtime for Laravel queues that brings Node.js-style execution guarantees to PHP.

## The Problem

Laravel queues today suffer from:
- **Blocking execution** - One slow job stalls the entire worker
- **Process-based concurrency** - Need multiple workers for parallelism
- **Poor isolation** - A crashed job kills the worker
- **Linear FIFO** - Not ideal for real-world workloads
- **Supervisor dependency** - Process manager doesn't understand job state

## The Solution

KQueue replaces the **execution engine** (not the queue driver) with a long-lived runtime inspired by Node.js and libuv:

- **Event loop-based** execution (ReactPHP)
- **Non-blocking** job processing
- **Automatic isolation** for heavy/unsafe jobs
- **Process-level safety** - crashes don't kill the daemon
- **Zero learning curve** - Laravel jobs stay 100% compatible

## POC Installation

```bash
# Install dependencies
composer install

# Run the demo
php examples/demo.php
```

## How It Works

### 1. Write Jobs (Same as Laravel!)

```php
use KQueue\Jobs\KQueueJob;

class SendEmail extends KQueueJob
{
    public int $timeout = 10;      // Optional: timeout in seconds
    public int $maxMemory = 64;    // Optional: memory limit in MB
    public bool $isolated = false; // Optional: run in separate process

    public function handle(): void
    {
        // Your normal Laravel job code
        Mail::to($this->user)->send(new WelcomeEmail());
    }
}
```

### 2. Two Execution Strategies

**Inline Strategy** (Fast jobs)
- Runs within the event loop
- Best for I/O-bound, lightweight jobs
- No process overhead

**Isolated Strategy** (Heavy jobs)
- Runs in separate process (fork)
- Best for CPU-heavy, unsafe jobs
- Crashes don't affect the daemon

### 3. Automatic Strategy Selection

```php
// This runs inline (fast, non-blocking)
class FastJob extends KQueueJob
{
    public bool $isolated = false;
}

// This runs isolated (safe, contained)
class HeavyJob extends KQueueJob
{
    public bool $isolated = true;
}
```

## What Makes This Different?

| Feature | Laravel Queue | KQueue |
|---------|--------------|---------|
| Execution Model | Blocking, synchronous | Event loop, non-blocking |
| One slow job | Blocks entire worker | Isolated & time-sliced |
| Process Management | Supervisor restarts blindly | Runtime manages job state |
| Concurrency | Multiple workers | Single daemon + strategies |
| Crash Handling | Worker dies | Job dies, daemon lives |
| Memory Leaks | Restart worker | Self-monitoring + limits |

## Architecture

```
┌─────────────────────────────────────┐
│   Laravel Queue (Redis/DB/SQS)      │  ← We don't touch this
└────────────────┬────────────────────┘
                 │
                 ▼
┌─────────────────────────────────────┐
│   KQueue Runtime (ReactPHP Loop)    │
│   - Job scheduler                   │
│   - Memory monitoring               │
│   - Signal handlers                 │
└────────────────┬────────────────────┘
                 │
          ┌──────┴──────┐
          ▼             ▼
   ┌───────────┐  ┌────────────┐
   │  Inline   │  │  Isolated  │
   │ Strategy  │  │  Strategy  │
   └───────────┘  └────────────┘
```

## POC Features Demonstrated

✅ **Event loop-based runtime** (ReactPHP)
✅ **Multiple execution strategies** (inline + isolated)
✅ **Memory monitoring** (periodic health checks)
✅ **Job timeout handling** (automatic)
✅ **Graceful shutdown** (SIGTERM/SIGINT)
✅ **Process isolation** (child processes for heavy jobs)
✅ **Zero Laravel API changes** (same job interface)

## Running the Demo

```bash
php examples/demo.php
```

You'll see:
1. Fast jobs executing inline (non-blocking)
2. Heavy job running isolated (separate process)
3. Memory monitoring every 5 seconds
4. Job completion tracking
5. Graceful shutdown

## Next Steps (Beyond POC)

- [ ] Laravel service provider integration
- [ ] Artisan commands (`php artisan kqueue:run`)
- [ ] Queue driver adapter (Redis, SQS, etc.)
- [ ] Fiber-based cooperative multitasking
- [ ] Job retry logic
- [ ] Better error handling & logging
- [ ] Process pool for isolated jobs
- [ ] Metrics & observability

## Philosophy

**We replace the execution runtime, not the queue driver.**

Laravel already handles:
- Job storage (Redis, DB, SQS)
- Payload serialization
- Queue semantics

KQueue handles:
- Job execution
- Scheduling
- Isolation
- Timeouts
- Memory limits
- Lifecycle management

## License

MIT

## Author

Kunal Ghosh (kunalghosh10000@gmail.com)
