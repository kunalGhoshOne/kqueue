# Testing KQueue with Redis

## Prerequisites

Install Predis in your Laravel app:

```bash
cd laravel-test/laravel-app/laravel-app
composer require predis/predis
```

Or use PHP with Redis extension:
```bash
docker run --rm --network host \
  -v $(pwd):/app -w /app \
  php:8.2-cli-alpine \
  sh -c "apk add php-redis && php artisan..."
```

## Update .env

```env
QUEUE_CONNECTION=redis
REDIS_CLIENT=predis  # or phpredis if extension installed
```

## Dispatch Jobs

```bash
php artisan tinker
>>> App\Jobs\TestQueueJob::dispatch('Redis Job', 2);
>>> exit
```

## Run Worker

```bash
php artisan kqueue:work redis --queue=default --secure
```

## Expected Result

Same as database queue test:
- Worker connects to Redis
- Polls Redis queue for jobs
- Processes jobs concurrently
- Deletes from Redis on success

**The KQueue code is IDENTICAL** - that's the point of queue-agnostic design!
