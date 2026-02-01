<?php

namespace App\Jobs;

use KQueue\Jobs\KQueueJob;
use Illuminate\Contracts\Queue\ShouldQueue;

class SendEmailJob extends KQueueJob implements ShouldQueue
{
    public int $timeout = 5;
    public bool $isolated = false; // Run inline (fast)

    public string $email;
    public string $message;

    public function __construct(string $email, string $message)
    {
        parent::__construct();
        $this->email = $email;
        $this->message = $message;
    }

    public function handle(): void
    {
        echo sprintf(
            "  [SendEmailJob] Sending email to %s: %s\n",
            $this->email,
            $this->message
        );

        // Simulate I/O operation
        usleep(500000); // 0.5 seconds

        echo sprintf("  [SendEmailJob] Email sent to %s\n", $this->email);
    }
}
