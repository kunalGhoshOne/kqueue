# KQueue POC - Quick Start Guide

## Prerequisites

- Docker installed
- Sudo access (password: 0)

## Running the POC

### 1. Build the Docker container

```bash
sudo docker-compose build
```

### 2. Run the demo

```bash
sudo docker-compose run --rm kqueue php examples/demo.php
```

Expected output:
```
===========================================
  KQueue POC - Node.js-style Queue Runtime
===========================================

Runtime initialized with 2 execution strategies:
  1. IsolatedExecutionStrategy (for heavy/unsafe jobs)
  2. InlineExecutionStrategy (for fast jobs)

[2026-02-01 17:11:43] KQueue Runtime started (PID: 1)
[2026-02-01 17:11:43] Executing job job_xxx (strategy: InlineExecutionStrategy)
  [FastJob] Processing: Email notification #1
  [FastJob] Done: Email notification #1
[2026-02-01 17:11:44] Job job_xxx completed in 0.50s
...
```

## Creating Your Own Jobs

### 1. Create a Fast Job (Inline Execution)

```php
<?php
use KQueue\Jobs\KQueueJob;

class SendEmailJob extends KQueueJob
{
    public int $timeout = 5;
    public bool $isolated = false; // Run inline

    private $email;

    public function __construct(string $email)
    {
        parent::__construct();
        $this->email = $email;
    }

    public function handle(): void
    {
        // Send email logic
        echo "Sending email to {$this->email}\n";
    }
}
```

### 2. Create a Heavy Job (Isolated Execution)

```php
<?php
use KQueue\Jobs\KQueueJob;

class ProcessVideoJob extends KQueueJob
{
    public int $timeout = 60;
    public int $maxMemory = 512; // MB
    public bool $isolated = true; // Run in separate process

    private $videoPath;

    public function __construct(string $videoPath)
    {
        parent::__construct();
        $this->videoPath = $videoPath;
    }

    public function handle(): void
    {
        // Heavy video processing
        echo "Processing video: {$this->videoPath}\n";
        // If this crashes, it won't kill the runtime!
    }
}
```

### 3. Execute Jobs

```php
<?php
require_once __DIR__ . '/vendor/autoload.php';

use KQueue\Runtime\KQueueRuntime;
use KQueue\Execution\InlineExecutionStrategy;
use KQueue\Execution\IsolatedExecutionStrategy;

// Create runtime
$runtime = new KQueueRuntime(memoryLimitMB: 256);

// Add strategies
$runtime->addStrategy(new IsolatedExecutionStrategy($runtime->getLoop()));
$runtime->addStrategy(new InlineExecutionStrategy());

// Schedule jobs
$loop = $runtime->getLoop();

$loop->futureTick(function() use ($runtime) {
    $runtime->executeJob(new SendEmailJob('user@example.com'));
    $runtime->executeJob(new ProcessVideoJob('/path/to/video.mp4'));
});

// Start the runtime
$runtime->start();
```

## Understanding Execution Strategies

### InlineExecutionStrategy

- **When to use**: Fast, I/O-bound jobs
- **Characteristics**:
  - Runs within the event loop
  - Zero process overhead
  - Best for quick operations
- **Set**: `public bool $isolated = false;`

### IsolatedExecutionStrategy

- **When to use**: Heavy, CPU-intensive, or unsafe jobs
- **Characteristics**:
  - Runs in separate child process
  - Crash-safe (daemon survives)
  - Memory isolated
- **Set**: `public bool $isolated = true;`

## Job Properties

```php
class MyJob extends KQueueJob
{
    // Timeout in seconds (default: 30)
    public int $timeout = 10;

    // Max memory in MB (default: 64)
    public int $maxMemory = 128;

    // Run isolated? (default: false)
    public bool $isolated = false;

    // Priority (higher = more important, default: 0)
    public int $priority = 5;

    public function handle(): void
    {
        // Your job logic here
    }
}
```

## Monitoring

The runtime automatically monitors:
- Memory usage (every 5 seconds)
- Jobs processed count
- Currently running jobs
- Job execution time

## Graceful Shutdown

Press `Ctrl+C` or send `SIGTERM` to stop gracefully.

## Testing Without Docker

If you have PHP 8.2+ and Composer installed locally:

```bash
# Install dependencies
composer install

# Run demo
php examples/demo.php
```

## Next Steps

1. Read `POC_RESULTS.md` for detailed analysis
2. Read `README.md` for project overview
3. Explore `src/` for implementation details
4. Check `idea.txt` and `disscuss.txt` for design philosophy

## Troubleshooting

**Issue**: "composer: command not found"
**Solution**: Use Docker (already includes Composer)

**Issue**: "Permission denied"
**Solution**: Run with `sudo` or add user to docker group

**Issue**: Jobs not executing
**Solution**: Check that you're adding execution strategies before starting runtime
