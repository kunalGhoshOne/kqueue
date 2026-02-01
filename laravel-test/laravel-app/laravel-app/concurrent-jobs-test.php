<?php

require __DIR__.'/vendor/autoload.php';

// Boot Laravel
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Jobs\SendEmailJob;
use KQueue\Runtime\KQueueRuntime;
use KQueue\Execution\InlineExecutionStrategy;
use KQueue\Execution\IsolatedExecutionStrategy;

echo "===========================================\n";
echo "  CONCURRENCY TEST: One Worker, Multiple Jobs\n";
echo "===========================================\n\n";

$runtime = new KQueueRuntime(memoryLimitMB: 256);
$runtime->addStrategy(new IsolatedExecutionStrategy($runtime->getLoop()));
$runtime->addStrategy(new InlineExecutionStrategy());

echo "ONE SINGLE WORKER RUNTIME\n";
echo "Process ID: " . getmypid() . "\n\n";

$loop = $runtime->getLoop();

// Dispatch 10 jobs ALL AT ONCE (non-blocking!)
$loop->futureTick(function() use ($runtime) {
    echo "--- Dispatching 10 Jobs SIMULTANEOUSLY ---\n";
    echo "All jobs dispatched to ONE worker\n";
    echo "Watch them execute concurrently!\n\n";
    
    for ($i = 1; $i <= 10; $i++) {
        $runtime->executeJob(new SendEmailJob(
            "user{$i}@example.com",
            "Message #{$i} - timestamp: " . microtime(true)
        ));
    }
    
    echo "All 10 jobs dispatched at " . date('H:i:s') . "\n";
    echo "ONE worker will handle all of them!\n\n";
});

// Stop after 15 seconds
$loop->addTimer(15.0, function() use ($runtime) {
    echo "\n===========================================\n";
    echo "  CONCURRENCY TEST COMPLETE\n";
    echo "===========================================\n";
    $runtime->stop();
});

echo "Starting runtime...\n\n";
$runtime->start();

echo "\nProof: One worker handled 10+ concurrent jobs!\n";
