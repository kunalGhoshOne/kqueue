<?php

require __DIR__.'/laravel-test/laravel-app/laravel-app/vendor/autoload.php';

$app = require_once __DIR__.'/laravel-test/laravel-app/laravel-app/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Jobs\TestQueueJob;

echo "🚀 Dispatching test jobs to queue...\n\n";

TestQueueJob::dispatch('Job #1 - Fast', 1);
echo "  ✓ Dispatched Job #1 (1 second)\n";

TestQueueJob::dispatch('Job #2 - Normal', 2);
echo "  ✓ Dispatched Job #2 (2 seconds)\n";

TestQueueJob::dispatch('Job #3 - Slow', 3);
echo "  ✓ Dispatched Job #3 (3 seconds)\n";

TestQueueJob::dispatch('Job #4 - Fast', 1);
echo "  ✓ Dispatched Job #4 (1 second)\n";

TestQueueJob::dispatch('Job #5 - Normal', 2);
echo "  ✓ Dispatched Job #5 (2 seconds)\n";

echo "\n✅ 5 jobs dispatched to '" . config('queue.default') . "' queue\n";
echo "📊 Total expected time if sequential: 9 seconds\n";
echo "⚡ With KQueue concurrent: ~3 seconds\n\n";
