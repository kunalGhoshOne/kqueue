<?php

namespace KQueue\Config;

/**
 * Security Configuration for KQueue
 *
 * Centralized security settings to prevent vulnerabilities
 */
class SecurityConfig
{
    /**
     * Maximum job timeout in seconds (server-enforced)
     * Jobs cannot exceed this regardless of what they request
     */
    public int $maxJobTimeout = 300; // 5 minutes

    /**
     * Maximum job memory in MB (server-enforced)
     */
    public int $maxJobMemory = 512; // 512MB

    /**
     * Maximum concurrent jobs
     * Prevents resource exhaustion DoS
     */
    public int $maxConcurrentJobs = 100;

    /**
     * Maximum jobs per minute (rate limiting)
     * Prevents job flooding DoS
     */
    public int $maxJobsPerMinute = 1000;

    /**
     * Maximum queue depth
     * Prevents memory exhaustion from queued jobs
     */
    public int $maxQueueDepth = 1000;

    /**
     * Allowed directories for job class files
     * Empty array = allow all (development only!)
     * Production should whitelist specific directories
     */
    public array $allowedJobPaths = [];

    /**
     * Enable strict mode
     * In strict mode, all security validations are enforced
     */
    public bool $strictMode = true;

    /**
     * Log security events
     */
    public bool $logSecurityEvents = true;

    /**
     * Sanitize error messages (remove sensitive info)
     */
    public bool $sanitizeErrors = true;

    /**
     * Default memory limit for runtime (MB)
     */
    public int $runtimeMemoryLimit = 512;

    /**
     * Create production-safe configuration
     */
    public static function production(): self
    {
        $config = new self();
        $config->maxJobTimeout = 300;
        $config->maxJobMemory = 256;
        $config->maxConcurrentJobs = 50;
        $config->maxJobsPerMinute = 500;
        $config->strictMode = true;
        $config->logSecurityEvents = true;
        $config->sanitizeErrors = true;

        // IMPORTANT: Set allowed job paths in production!
        // Example: $config->allowedJobPaths = ['/app/Jobs', '/vendor/mypackage/jobs'];

        return $config;
    }

    /**
     * Create development configuration (more permissive)
     */
    public static function development(): self
    {
        $config = new self();
        $config->maxJobTimeout = 600;
        $config->maxJobMemory = 1024;
        $config->maxConcurrentJobs = 200;
        $config->maxJobsPerMinute = 5000;
        $config->strictMode = false;
        $config->allowedJobPaths = []; // Allow all in dev
        $config->sanitizeErrors = false; // Show full errors in dev

        return $config;
    }

    /**
     * Validate configuration
     */
    public function validate(): void
    {
        $errors = [];

        if ($this->maxJobTimeout <= 0) {
            $errors[] = 'maxJobTimeout must be positive';
        }

        if ($this->maxJobMemory <= 0) {
            $errors[] = 'maxJobMemory must be positive';
        }

        if ($this->maxConcurrentJobs <= 0) {
            $errors[] = 'maxConcurrentJobs must be positive';
        }

        if ($this->strictMode && empty($this->allowedJobPaths)) {
            $errors[] = 'Strict mode requires allowedJobPaths to be configured';
        }

        if (!empty($errors)) {
            throw new \InvalidArgumentException(
                'Invalid security configuration: ' . implode(', ', $errors)
            );
        }
    }
}
