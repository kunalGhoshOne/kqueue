<?php

require_once __DIR__ . '/../vendor/autoload.php';

use KQueue\Jobs\KQueueJob;

/**
 * Example: Heavy job that runs isolated
 * Executes in separate process to prevent blocking
 */
class HeavyJob extends KQueueJob
{
    public int $timeout = 10;
    public int $maxMemory = 128;
    public bool $isolated = true; // Run in separate process

    private int $workload;

    public function __construct(int $workload)
    {
        parent::__construct();
        $this->workload = $workload;
    }

    public function handle(): void
    {
        echo "  [HeavyJob] Starting heavy processing (workload: {$this->workload})...\n";

        // Simulate heavy CPU work
        $result = 0;
        for ($i = 0; $i < $this->workload; $i++) {
            $result += sqrt($i);
            usleep(1000); // 1ms per iteration
        }

        echo "  [HeavyJob] Completed! Result: " . number_format($result, 2) . "\n";
    }
}
