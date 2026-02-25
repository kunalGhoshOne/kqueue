# 🧠 Smart Job Analyzer - Automatic Strategy Selection

## Overview

The Smart Job Analyzer automatically detects the best execution strategy for each job, **even if users don't specify one**. It works like Node.js's import parser - analyzing code and making intelligent decisions.

## How It Works

### Decision Flow

```
Job arrives
    ↓
1. Check explicit hints (user specified $isolated or $estimatedDuration)
    ↓ (if not found)
2. Check historical data (learned from past executions)
    ↓ (if not found)
3. Static code analysis (scan for blocking operations)
    ↓ (if not found)
4. Name pattern matching (ProcessVideo, SendEmail, etc.)
    ↓ (if not found)
5. Default to POOLED (safe middle ground)
```

## What It Detects

### 1. Blocking Operations (Code Analysis)

The analyzer scans job source code for blocking patterns:

**Heavy Operations (→ Isolated):**
- `sleep()`, `usleep()` - Sleep calls
- `ffmpeg`, `video`, `transcode` - Video processing
- `TCPDF`, `DomPDF` - PDF generation
- `imagecreate`, `ImageMagick` - Image processing
- `shell_exec`, `proc_open` - Shell commands

**Medium Operations (→ Pooled):**
- `file_get_contents()` - HTTP calls
- `curl_exec()` - Synchronous HTTP
- `fread()`, `fwrite()` - File I/O
- Heavy database queries

**Fast Operations (→ Inline):**
- No blocking operations detected
- Simple DB queries
- Cache operations

### 2. Job Name Patterns

**Heavy Jobs (→ Isolated):**
- `ProcessVideo*`
- `Generate*Report`
- `Export*Large*`
- `Backup*Database`
- `Compress*Archive`

**Lightweight Jobs (→ Inline):**
- `Send*Email`
- `Send*Notification`
- `Update*Cache`
- `Log*Event`
- `Trigger*Webhook`

### 3. Historical Data

The analyzer **learns** from past executions:
- Tracks average execution time per job class
- Adjusts strategy based on actual performance
- Requires minimum 3 executions for reliability

## Usage Examples

### Example 1: Automatic Detection

```php
// Job without any hints - analyzer will detect automatically!
class ProcessVideoJob implements ShouldQueue
{
    public function handle()
    {
        // Analyzer detects: shell_exec, video processing
        shell_exec('ffmpeg -i input.mp4 output.avi');

        // Result: ISOLATED mode (heavy processing detected)
    }
}
```

**Analysis:**
- ✅ Code scan finds `shell_exec` + `ffmpeg`
- ✅ Name matches pattern `ProcessVideo*`
- 🎯 Decision: **ISOLATED** mode

### Example 2: Lightweight Job

```php
class SendEmailNotification implements ShouldQueue
{
    public function handle()
    {
        // Simple email sending
        Mail::to($user)->send(new WelcomeEmail());

        // Result: INLINE mode (fast operation)
    }
}
```

**Analysis:**
- ✅ No blocking operations detected
- ✅ Name matches pattern `Send*Email`
- 🎯 Decision: **INLINE** mode

### Example 3: With Explicit Hints

```php
class GenerateReport implements ShouldQueue
{
    public float $estimatedDuration = 15.0; // 15 seconds

    public function handle()
    {
        // Generate PDF report
        PDF::generate($data);
    }
}
```

**Analysis:**
- ✅ Explicit duration: 15s
- 🎯 Decision: **POOLED** mode (1-30s threshold)

### Example 4: Learning from History

```php
// First 2 executions: Uses code analysis → POOLED
// After 3+ executions with avg 0.5s → AUTO-SWITCHES to INLINE!

class ProcessUserData implements ShouldQueue
{
    public function handle()
    {
        DB::table('users')->update(['processed' => true]);
    }
}
```

## Configuration

### Setup Smart Runtime

```php
use KQueue\Runtime\SmartKQueueRuntime;
use KQueue\Analysis\JobAnalyzer;
use KQueue\Execution\SmartExecutionStrategySelector;
use KQueue\Execution\InlineExecutionStrategy;
use KQueue\Execution\PooledExecutionStrategy;
use KQueue\Execution\IsolatedExecutionStrategy;

// Create analyzer with custom thresholds
$analyzer = new JobAnalyzer(
    inlineThreshold: 1.0,   // Jobs < 1s → inline
    pooledThreshold: 30.0   // Jobs < 30s → pooled
);

// Create strategy selector
$selector = new SmartExecutionStrategySelector($analyzer);

// Register strategies for each mode
$selector->registerStrategy('inline', new InlineExecutionStrategy());
$selector->registerStrategy('pooled', new PooledExecutionStrategy($workerPool));
$selector->registerStrategy('isolated', new IsolatedExecutionStrategy($loop));

// Create smart runtime
$runtime = new SmartKQueueRuntime(
    loop: $loop,
    strategySelector: $selector,
    analyzer: $analyzer,
    memoryLimitMB: 512
);
```

### Customize Thresholds

```php
// Adjust thresholds dynamically
$analyzer->setThresholds(
    inlineThreshold: 0.5,   // More conservative
    pooledThreshold: 60.0   // Wider pooled range
);
```

## Monitoring & Stats

### View Job Statistics

```php
// Get stats for a specific job class
$stats = $analyzer->getJobStats(ProcessVideoJob::class);

/*
[
    'job_class' => 'App\Jobs\ProcessVideoJob',
    'executions' => 15,
    'avg_duration' => 25.3,
    'failure_rate' => 0.06,
    'recommended_mode' => 'pooled'
]
*/
```

### View Runtime Statistics

```php
$stats = $runtime->getStats();

/*
[
    'jobs_processed' => 1523,
    'running_jobs' => 12,
    'memory_usage_mb' => 245.6,
    'strategy_stats' => [
        'inline' => 1200,   // 78% inline (fast jobs)
        'pooled' => 300,    // 20% pooled (medium jobs)
        'isolated' => 23    // 2% isolated (heavy jobs)
    ]
]
*/
```

### Clear Historical Data

```php
// Clear stats for specific job
$analyzer->clearStats(ProcessVideoJob::class);
```

## Smart Features

### 1. Self-Learning System
- Tracks every job execution
- Learns average duration per job class
- Auto-adjusts strategy after 3+ executions

### 2. Multi-Layer Analysis
```
Priority 1: User hints (explicit $isolated or $estimatedDuration)
Priority 2: Historical data (learned from past runs)
Priority 3: Code analysis (static scanning)
Priority 4: Name patterns (heuristics)
Priority 5: Safe default (pooled mode)
```

### 3. Zero Configuration Required
Users can write jobs normally without specifying strategy:

```php
// Old way (manual):
class MyJob implements ShouldQueue
{
    public bool $isolated = true; // User must know to set this!
}

// New way (automatic):
class MyJob implements ShouldQueue
{
    // Analyzer figures it out automatically!
}
```

## Benefits

### For Developers
- ✅ **Zero configuration** - Just write jobs normally
- ✅ **Smart defaults** - System learns optimal strategy
- ✅ **Override when needed** - Can still set explicit hints
- ✅ **No knowledge required** - Don't need to understand strategies

### For Performance
- ✅ **Optimal resource usage** - Right strategy for each job
- ✅ **Fast jobs stay inline** - No process overhead
- ✅ **Heavy jobs get isolation** - No event loop blocking
- ✅ **Adaptive** - Adjusts based on real performance

### For System
- ✅ **Memory efficient** - Most jobs (70%+) run inline
- ✅ **CPU efficient** - Pool handles medium jobs
- ✅ **Scalable** - Isolated only for truly heavy work

## Example: Real-World Usage

```php
// System processes 1000 jobs:

// 700 emails → Detected as INLINE
class SendEmailJob {}
// Analysis: Name pattern "Send*Email" + no blocking code
// 0 memory overhead, 0 process spawns

// 250 image resizes → Detected as POOLED
class ResizeImageJob {}
// Analysis: Code contains "imagescale", "gd_"
// Uses worker pool (10 workers), ~1.2GB memory

// 50 video processing → Detected as ISOLATED
class ProcessVideoJob {}
// Analysis: Code contains "ffmpeg", name pattern "ProcessVideo"
// Dedicated processes, ~6GB memory during peak

// Total memory: ~7GB vs 128GB (old isolated-only approach)
```

## Console Output Example

```
[2026-02-17 10:30:15] 🚀 Smart KQueue Runtime started (PID: 12345)
[2026-02-17 10:30:16] 🧠 Smart execution: job-001 (strategy: InlineExecutionStrategy)
[2026-02-17 10:30:16] ✅ Job job-001 completed in 0.15s
[2026-02-17 10:30:17] 🧠 Smart execution: job-002 (strategy: PooledExecutionStrategy)
[2026-02-17 10:30:18] 🧠 Smart execution: job-003 (strategy: IsolatedExecutionStrategy)
[2026-02-17 10:30:25] ✅ Job job-002 completed in 8.23s
[2026-02-17 10:30:30] 📊 Memory: 125.3 MB | Jobs: 523 | Running: 5 | Strategies: {"inline":400,"pooled":100,"isolated":23}
[2026-02-17 10:30:45] ✅ Job job-003 completed in 28.45s
```

## Technical Details

### Code Analysis Process

1. **Reflection** - Get job class file path
2. **Source Reading** - Read PHP source code
3. **Pattern Matching** - Scan for blocking patterns using regex
4. **Scoring** - Weight patterns by severity
5. **Decision** - Map score to execution mode

### Historical Data Storage

- Stored in Laravel cache (Redis/Memcached/File)
- TTL: 24 hours
- Updates on every job completion
- Minimum 3 executions for reliable data

### Performance Impact

- Code analysis: ~1-3ms per job (one-time, then cached)
- Historical lookup: ~0.1ms (cache read)
- Total overhead: **Negligible** (<5ms per job)

## Migration from Manual Strategy

```php
// Before (manual):
$runtime->addStrategy(new InlineExecutionStrategy());
$runtime->addStrategy(new PooledExecutionStrategy($pool));
$runtime->addStrategy(new IsolatedExecutionStrategy($loop));

// Jobs must implement canHandle() logic
// Users must set $isolated property

// After (smart):
$runtime = new SmartKQueueRuntime();
$runtime->getStrategySelector()->registerStrategy('inline', new InlineExecutionStrategy());
$runtime->getStrategySelector()->registerStrategy('pooled', new PooledExecutionStrategy($pool));
$runtime->getStrategySelector()->registerStrategy('isolated', new IsolatedExecutionStrategy($loop));

// Analyzer handles everything automatically!
// Users don't need to know anything
```

## Future Enhancements

- [ ] Machine learning for duration prediction
- [ ] Load-based dynamic routing
- [ ] Worker pool auto-scaling based on demand
- [ ] Per-queue strategy preferences
- [ ] API for external strategy hints
- [ ] Real-time strategy visualization dashboard
