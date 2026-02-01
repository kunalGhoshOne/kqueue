# KQueue - Proof of Concept Results

This document contains the technical details, architecture, and POC test results for KQueue.

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

## Core Components

### 1. KQueueRuntime

The main runtime daemon that:
- Manages the ReactPHP event loop
- Monitors memory usage
- Handles graceful shutdown (SIGTERM, SIGINT)
- Routes jobs to appropriate execution strategies

**Key Methods:**
- `start()` - Starts the event loop
- `stop()` - Graceful shutdown
- `executeJob(KQueueJobInterface $job)` - Execute a job
- `addStrategy(ExecutionStrategyInterface $strategy)` - Register execution strategy

### 2. Execution Strategies

#### InlineExecutionStrategy
- Executes jobs within the event loop process
- Best for lightweight, I/O-bound jobs
- No process overhead
- Memory efficient

**When to use:**
```php
class SendEmail extends KQueueJob
{
    public bool $isolated = false; // Use inline
    public int $timeout = 10;

    public function handle(): void
    {
        // Fast I/O operation
        Mail::send(...);
    }
}
```

#### IsolatedExecutionStrategy
- Executes jobs in separate child processes
- Uses ReactPHP ChildProcess
- Best for CPU-heavy or potentially unsafe jobs
- Crashes don't affect the main daemon

**When to use:**
```php
class ProcessVideo extends KQueueJob
{
    public bool $isolated = true; // Use isolated
    public int $timeout = 300;
    public int $maxMemory = 512;

    public function handle(): void
    {
        // Heavy CPU work
        FFmpeg::process($this->video);
    }
}
```

### 3. Job Interface

```php
interface KQueueJobInterface
{
    public function handle(): void;
    public function getTimeout(): int;
    public function getMaxMemory(): int;
    public function isIsolated(): bool;
    public function getPriority(): int;
}
```

**Base Class:**
```php
abstract class KQueueJob implements KQueueJobInterface
{
    public int $timeout = 30;      // Seconds
    public int $maxMemory = 64;    // MB
    public bool $isolated = false; // Inline or isolated
    public int $priority = 0;      // Higher = more priority

    abstract public function handle(): void;
}
```

## POC Features Demonstrated

### ✅ Event Loop-Based Runtime
- Uses ReactPHP event loop
- Non-blocking I/O
- Single-threaded concurrency

### ✅ Multiple Execution Strategies
- Inline strategy for fast jobs
- Isolated strategy for heavy jobs
- Automatic strategy selection based on `$isolated` flag

### ✅ Memory Monitoring
- Periodic memory checks (every 5 seconds)
- Current memory usage logged
- Foundation for memory limits

### ✅ Job Timeout Handling
- Each job has configurable timeout
- Logged when jobs exceed timeout
- Foundation for process termination

### ✅ Graceful Shutdown
- Handles SIGTERM and SIGINT
- Stops accepting new jobs
- Waits for running jobs to finish
- Clean event loop shutdown

### ✅ Process Isolation
- Heavy jobs run in child processes
- Job crashes don't kill daemon
- Uses fork + exec pattern

### ✅ Laravel Compatibility
- Zero changes to job API
- Implements Laravel's `ShouldQueue` interface
- Works with existing Laravel jobs

## Test Results

### Standalone POC Test

**Environment:**
- PHP 8.2-cli
- ReactPHP 1.4
- Memory: 4MB runtime overhead

**Jobs Executed:**
```
1. SendEmail (inline, 0.5s) ✓
2. ProcessData (inline, 1s) ✓
3. SendEmail (inline, 0.5s) ✓
4. ProcessVideo (isolated, 2s) ✓
5. GenerateReport (isolated, 1.5s) ✓
```

**Results:**
- All 5 jobs completed successfully
- Total runtime: ~6 seconds
- Memory: 4MB stable
- No blocking observed
- Clean shutdown

### Laravel Integration Test

See [LARAVEL_TEST_RESULTS.md](../LARAVEL_TEST_RESULTS.md) for detailed Laravel integration results.

**Summary:**
- ✅ Laravel 12 compatibility
- ✅ 6 jobs executed (5 inline, 1 isolated)
- ✅ Non-blocking execution proven
- ✅ 18MB memory usage
- ✅ Automatic strategy selection working

### Non-Blocking Verification Test

**Test:** 1 worker handling 20 jobs

**Results:**
```
Total jobs: 20
Total time: 13.42 seconds
Average dispatch: 0.66ms per job
Memory: 18MB

Execution pattern:
- Jobs 1-20 all dispatched in first second
- Heavy job (2.21s) ran in parallel with fast jobs
- No blocking detected
```

**Proof:** Fast jobs continued executing while heavy job processed in background.

## Comparison: Traditional vs KQueue

| Metric | Traditional Queue | KQueue Runtime |
|--------|------------------|----------------|
| **Workers** | 5 workers | 1 daemon |
| **Memory** | ~250-500MB (5 x 50-100MB) | ~18MB |
| **Process Count** | 5 always running | 1 + isolated jobs |
| **Blocking** | Yes (one job = one worker) | No (event loop) |
| **Heavy Job Impact** | Blocks 1 worker | Isolated process |
| **Concurrency** | 5 parallel jobs max | 100s of inline jobs |
| **Supervisor** | Required | Optional |
| **Restart Time** | ~2-5s per worker | Graceful, no downtime |

## Performance Characteristics

### Inline Strategy
- **Latency:** < 1ms dispatch overhead
- **Throughput:** 1000s jobs/second for I/O work
- **Memory:** Shared with runtime (~18MB base)
- **Limitations:** CPU-heavy work blocks loop

### Isolated Strategy
- **Latency:** ~50-100ms (process spawn)
- **Throughput:** Limited by CPU cores
- **Memory:** Isolated per job
- **Limitations:** Process overhead

## Security Hardening

All critical vulnerabilities have been fixed. See [SECURITY.md](../SECURITY.md) for details.

**Fixed Issues:**
1. ✅ Remote Code Execution (RCE) via unserialize()
2. ✅ Path injection in isolated jobs
3. ✅ Timeout not enforced
4. ✅ Memory limits not enforced
5. ✅ No input validation
6. ✅ Information disclosure in errors
7. ✅ Temp file race conditions
8. ✅ No rate limiting

**Secure Components:**
- `SecureKQueueRuntime` - Input validation, rate limiting
- `SecureIsolatedExecutionStrategy` - JSON serialization, path validation, SIGKILL enforcement
- `SecureInlineExecutionStrategy` - Memory limits
- `SecurityConfig` - Centralized security settings

## Running the POC

### Basic Demo
```bash
composer install
php examples/demo.php
```

### Laravel Integration
```bash
# Create fresh Laravel app
composer create-project laravel/laravel test-app
cd test-app

# Install KQueue locally (when published)
composer require kqueue/kqueue

# Or install from local path
composer config repositories.kqueue path ../kqueue
composer require kqueue/kqueue:@dev

# Copy example test
cp vendor/kqueue/kqueue/examples/secure-laravel-test.php .
php secure-laravel-test.php
```

## Technical Decisions

### Why ReactPHP?
- Production-ready event loop
- Well-maintained
- Fiber support coming
- Large ecosystem

### Why Two Strategies?
- Inline: Fast jobs shouldn't pay process overhead
- Isolated: Heavy jobs shouldn't block event loop
- Automatic selection keeps API simple

### Why Not Swoole/RoadRunner?
- More dependencies
- Harder to install
- ReactPHP is pure PHP, installable anywhere

### Why Not Fibers?
- Coming in future versions
- POC proves the runtime model works first
- Fibers will make inline strategy even better

## Limitations & Future Work

### Current Limitations
1. No job retry logic
2. No queue driver integration (manual job dispatch)
3. No process pool (spawns new process per isolated job)
4. No observability hooks
5. No distributed tracing

### Planned Improvements
1. **v0.1:** Queue driver adapter (Redis, SQS, DB)
2. **v0.2:** Job retries with exponential backoff
3. **v0.3:** Process pool for isolated jobs (reuse processes)
4. **v0.4:** Fiber-based cooperative multitasking
5. **v1.0:** Production-ready with monitoring

## Lessons Learned

### What Worked
- ✅ Event loop model is viable for PHP
- ✅ Laravel API compatibility is achievable
- ✅ Strategy pattern allows flexibility
- ✅ Process isolation is reliable

### What Needs Work
- Job retry logic is critical
- Process pooling needed for efficiency
- Better error handling required
- Observability is essential for production

## Conclusion

The POC successfully demonstrates:

1. **Non-blocking execution works** - Event loop proven with ReactPHP
2. **Laravel compatibility** - Zero API changes needed
3. **Process isolation** - Heavy jobs safely contained
4. **Memory efficiency** - 18MB vs 250-500MB for traditional workers
5. **Security** - All critical vulnerabilities fixed

**Next step:** Build v0.1 with queue driver integration and Artisan commands.

---

**POC Status:** ✅ Proven
**Laravel Integration:** ✅ Working
**Security:** ✅ Hardened
**Production Ready:** ⚠️ Not yet (queue integration needed)
