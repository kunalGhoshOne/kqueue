<?php

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/FastJob.php';
require_once __DIR__ . '/HeavyJob.php';

use KQueue\Runtime\KQueueRuntime;
use KQueue\Execution\InlineExecutionStrategy;
use KQueue\Execution\IsolatedExecutionStrategy;

echo "===========================================\n";
echo "  KQueue POC - Node.js-style Queue Runtime\n";
echo "===========================================\n\n";

// Create the runtime
$runtime = new KQueueRuntime(memoryLimitMB: 256);

// Register execution strategies
$runtime->addStrategy(new IsolatedExecutionStrategy($runtime->getLoop()));
$runtime->addStrategy(new InlineExecutionStrategy());

echo "Runtime initialized with 2 execution strategies:\n";
echo "  1. IsolatedExecutionStrategy (for heavy/unsafe jobs)\n";
echo "  2. InlineExecutionStrategy (for fast jobs)\n\n";

// Schedule some jobs
$loop = $runtime->getLoop();

// Schedule fast jobs that run inline
$loop->futureTick(function() use ($runtime) {
    echo "\n--- Scheduling Fast Jobs (Inline) ---\n";
    $runtime->executeJob(new FastJob("Email notification #1"));
    $runtime->executeJob(new FastJob("Email notification #2"));
    $runtime->executeJob(new FastJob("Email notification #3"));
});

// Schedule a heavy job after 2 seconds (runs isolated)
$loop->addTimer(2.0, function() use ($runtime) {
    echo "\n--- Scheduling Heavy Job (Isolated) ---\n";
    $runtime->executeJob(new HeavyJob(1000));
});

// Schedule more fast jobs after 3 seconds
$loop->addTimer(3.0, function() use ($runtime) {
    echo "\n--- Scheduling More Fast Jobs ---\n";
    $runtime->executeJob(new FastJob("Push notification #1"));
    $runtime->executeJob(new FastJob("Push notification #2"));
});

// Stop after 10 seconds for demo purposes
$loop->addTimer(10.0, function() use ($runtime) {
    echo "\n--- Demo Complete ---\n";
    $runtime->stop();
});

echo "Starting event loop...\n";
echo "Press Ctrl+C to stop\n\n";

// Start the runtime
$runtime->start();

echo "\nRuntime stopped.\n";
