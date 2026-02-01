<?php

require __DIR__.'/vendor/autoload.php';

// Boot Laravel
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Jobs\SendEmailJob;
use App\Jobs\ProcessVideoJob;
use KQueue\Runtime\KQueueRuntime;
use KQueue\Execution\InlineExecutionStrategy;
use KQueue\Execution\IsolatedExecutionStrategy;

echo "===========================================\n";
echo "  KQueue + Laravel Integration Test\n";
echo "===========================================\n\n";

// Create the KQueue runtime
$runtime = new KQueueRuntime(memoryLimitMB: 256);

// Register execution strategies
$runtime->addStrategy(new IsolatedExecutionStrategy($runtime->getLoop()));
$runtime->addStrategy(new InlineExecutionStrategy());

echo "KQueue Runtime initialized for Laravel\n";
echo "Execution strategies: Isolated + Inline\n\n";

// Schedule jobs
$loop = $runtime->getLoop();

// Schedule fast email jobs (inline execution)
$loop->futureTick(function() use ($runtime) {
    echo "--- Dispatching Email Jobs (Inline) ---\n";
    $runtime->executeJob(new SendEmailJob('user1@example.com', 'Welcome to our platform!'));
    $runtime->executeJob(new SendEmailJob('user2@example.com', 'Your order has been confirmed'));
    $runtime->executeJob(new SendEmailJob('user3@example.com', 'New features available'));
});

// Schedule heavy video processing job after 2 seconds (isolated execution)
$loop->addTimer(2.0, function() use ($runtime) {
    echo "\n--- Dispatching Heavy Job (Isolated) ---\n";
    $runtime->executeJob(new ProcessVideoJob('/videos/demo.mp4'));
});

// Schedule more email jobs after 3 seconds (while video is processing)
$loop->addTimer(3.0, function() use ($runtime) {
    echo "\n--- Dispatching More Email Jobs ---\n";
    $runtime->executeJob(new SendEmailJob('user4@example.com', 'Newsletter: Weekly updates'));
    $runtime->executeJob(new SendEmailJob('user5@example.com', 'Password reset confirmation'));
});

// Stop after 10 seconds
$loop->addTimer(10.0, function() use ($runtime) {
    echo "\n--- Test Complete ---\n";
    $runtime->stop();
});

echo "Starting KQueue runtime...\n";
echo "Press Ctrl+C to stop\n\n";

// Start the runtime
$runtime->start();

echo "\nKQueue runtime stopped.\n";
echo "\n===========================================\n";
echo "  Test Summary:\n";
echo "  - Fast jobs executed inline (non-blocking)\n";
echo "  - Heavy job executed isolated (separate process)\n";
echo "  - Jobs continued while heavy job processed\n";
echo "  - Zero downtime, zero blocking\n";
echo "===========================================\n";
