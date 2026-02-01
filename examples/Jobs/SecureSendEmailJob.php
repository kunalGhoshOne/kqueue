<?php

namespace App\Jobs;

use KQueue\Jobs\KQueueJob;
use Illuminate\Contracts\Queue\ShouldQueue;

class SecureSendEmailJob extends KQueueJob implements ShouldQueue
{
    public int $timeout = 5;
    public bool $isolated = false;

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
        echo sprintf("  [Email] To: %s | Message: %s\n", $this->email, $this->message);
        usleep(300000); // 0.3 seconds
        echo sprintf("  [Email] Sent to %s\n", $this->email);
    }
}
