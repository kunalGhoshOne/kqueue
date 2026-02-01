<?php

namespace App\Jobs;

use KQueue\Jobs\KQueueJob;
use Illuminate\Contracts\Queue\ShouldQueue;

class ProcessVideoJob extends KQueueJob implements ShouldQueue
{
    public int $timeout = 30;
    public int $maxMemory = 256;
    public bool $isolated = true; // Run in separate process

    public string $videoPath;

    public function __construct(string $videoPath)
    {
        parent::__construct();
        $this->videoPath = $videoPath;
    }

    public function handle(): void
    {
        echo sprintf("  [ProcessVideoJob] Processing video: %s\n", $this->videoPath);

        // Simulate heavy CPU work
        $result = 0;
        for ($i = 0; $i < 2000; $i++) {
            $result += sqrt($i);
            usleep(1000); // 1ms per iteration
        }

        echo sprintf(
            "  [ProcessVideoJob] Video processed: %s (result: %s)\n",
            $this->videoPath,
            number_format($result, 2)
        );
    }
}
