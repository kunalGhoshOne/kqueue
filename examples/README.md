# KQueue Examples

This directory contains example scripts and jobs demonstrating KQueue usage.

## Files

### Standalone Examples
- **`demo.php`** - Basic POC demonstration
- **`security-test.php`** - Security validation tests

### Laravel Integration Examples
- **`secure-laravel-test.php`** - Production-safe Laravel integration test
- **`Jobs/SecureSendEmailJob.php`** - Example Laravel job with security

## Running Standalone Examples

```bash
# From kqueue root directory
composer install
php examples/demo.php
php examples/security-test.php
```

## Running Laravel Examples

### 1. Create a Fresh Laravel App

```bash
# Create Laravel app
composer create-project laravel/laravel my-laravel-app
cd my-laravel-app
```

### 2. Install KQueue

**Option A: From Packagist (when published)**
```bash
composer require kqueue/kqueue
```

**Option B: From Local Path (development)**
```bash
# In my-laravel-app directory
composer config repositories.kqueue path ../kqueue
composer require kqueue/kqueue:@dev
```

### 3. Copy Example Files

```bash
# Copy the test script
cp vendor/kqueue/kqueue/examples/secure-laravel-test.php .

# Copy the example job
cp vendor/kqueue/kqueue/examples/Jobs/SecureSendEmailJob.php app/Jobs/
```

### 4. Run the Test

```bash
php secure-laravel-test.php
```

## Expected Output

### secure-laravel-test.php

```
===========================================
  KQueue SECURE Laravel Integration Test
===========================================

Security Configuration:
  Max Timeout: 10s
  Max Memory: 256MB
  Max Concurrent: 50
  Allowed Paths: /path/to/app/Jobs
  Strict Mode: YES

✓ Secure runtime initialized
✓ Using SecureKQueueRuntime
✓ Using SecureIsolatedExecutionStrategy
✓ Using SecureInlineExecutionStrategy

Starting secure runtime...

--- Test 1: Valid Jobs (Should Work) ---
  [Email] To: user1@example.com | Message: Welcome!
  [Email] Sent to user1@example.com
✓ All valid jobs accepted

--- Test 2: Security Validation (Excessive Timeout) ---
✓ Security working: Job timeout exceeds maximum allowed (10 seconds)

--- Test 3: Security Validation (Excessive Memory) ---
✓ Security working: Job memory exceeds maximum allowed (256 MB)

--- Test 4: Additional Valid Jobs ---
✓ Jobs dispatched

===========================================
  SECURE Laravel Integration - SUCCESS
===========================================
Test Results:
  ✓ Laravel integration: WORKING
  ✓ Secure runtime: WORKING
  ✓ Input validation: ENFORCED
  ✓ Security limits: ENFORCED
  ✓ Malicious jobs: REJECTED
  ✓ Valid jobs: EXECUTED

Security Status: PRODUCTION READY
===========================================
```

## Example Job Structure

### SecureSendEmailJob

```php
<?php

namespace App\Jobs;

use KQueue\Jobs\KQueueJob;
use Illuminate\Contracts\Queue\ShouldQueue;

class SecureSendEmailJob extends KQueueJob implements ShouldQueue
{
    // Job configuration
    public int $timeout = 5;        // 5 seconds max
    public bool $isolated = false;  // Run inline (fast)

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
        // Your job logic here
        echo "Sending email to {$this->email}\n";
    }
}
```

## Job Configuration Options

| Property | Type | Default | Description |
|----------|------|---------|-------------|
| `$timeout` | int | 30 | Maximum execution time in seconds |
| `$maxMemory` | int | 64 | Maximum memory in MB |
| `$isolated` | bool | false | Run in separate process if true |
| `$priority` | int | 0 | Higher number = higher priority |

## Testing with Docker

If you want to test in a clean environment:

```bash
# Create a test directory
mkdir kqueue-test
cd kqueue-test

# Create docker-compose.yml
cat > docker-compose.yml << 'EOF'
version: '3.8'
services:
  php:
    image: php:8.2-cli
    volumes:
      - ../kqueue:/kqueue
      - .:/app
    working_dir: /app
    command: tail -f /dev/null
EOF

# Start container
docker compose up -d

# Enter container
docker compose exec php bash

# Inside container
cd /kqueue
composer install
php examples/demo.php
```

## Security Notes

Always use the secure components in production:
- `SecureKQueueRuntime` instead of `KQueueRuntime`
- `SecureIsolatedExecutionStrategy` instead of `IsolatedExecutionStrategy`
- `SecureInlineExecutionStrategy` instead of `InlineExecutionStrategy`
- Configure `SecurityConfig::production()` with appropriate limits

See [SECURITY.md](../SECURITY.md) for complete security documentation.

## More Information

- [Main README](../README.md) - Project overview
- [POC Documentation](../docs/POC.md) - Technical details
- [Laravel Test Results](../LARAVEL_TEST_RESULTS.md) - Integration test results
- [Security Documentation](../SECURITY.md) - Security analysis and fixes
