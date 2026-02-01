<?php

namespace App\Jobs;

use KQueue\Jobs\KQueueJob;
use Illuminate\Contracts\Queue\ShouldQueue;

class NonBlockingJob extends KQueueJob implements ShouldQueue
{
    public int $timeout = 10;
    public bool $isolated = false; // Inline execution

    public string $taskName;
    public float $startTime;

    public function __construct(string $taskName)
    {
        parent::__construct();
        $this->taskName = $taskName;
        $this->startTime = microtime(true);
    }

    public function handle(): void
    {
        $elapsed = round((microtime(true) - $this->startTime) * 1000, 2);
        echo sprintf(
            "  [%s] Task '%s' executing (queued for %sms)\n",
            date('H:i:s.u'),
            $this->taskName,
            $elapsed
        );
        
        // Non-blocking! No sleep here - just instant execution
        // In real world, this would be async I/O operations
        
        echo sprintf(
            "  [%s] Task '%s' COMPLETED instantly (non-blocking)\n",
            date('H:i:s.u'),
            $this->taskName
        );
    }
}
