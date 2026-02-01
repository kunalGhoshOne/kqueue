<?php

require_once __DIR__ . '/../vendor/autoload.php';

use KQueue\Jobs\KQueueJob;

/**
 * Example: Fast, inline job
 * Executes within the event loop
 */
class FastJob extends KQueueJob
{
    public int $timeout = 5;
    public bool $isolated = false;

    private string $message;

    public function __construct(string $message)
    {
        parent::__construct();
        $this->message = $message;
    }

    public function handle(): void
    {
        echo "  [FastJob] Processing: {$this->message}\n";
        usleep(500000); // 0.5 seconds
        echo "  [FastJob] Done: {$this->message}\n";
    }
}
