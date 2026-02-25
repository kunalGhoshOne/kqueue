<?php

namespace KQueue\Analysis;

use KQueue\Contracts\KQueueJobInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Smart Job Analyzer - Automatically detects optimal execution strategy
 *
 * Analyzes jobs using multiple techniques:
 * 1. Static code analysis (detect blocking operations)
 * 2. Historical performance data
 * 3. Job class hints and patterns
 * 4. Runtime heuristics
 */
class JobAnalyzer
{
    private const CACHE_PREFIX = 'kqueue_job_stats:';
    private const CACHE_TTL = 86400; // 24 hours

    // Execution modes
    public const MODE_INLINE = 'inline';      // < 1s
    public const MODE_POOLED = 'pooled';      // 1-30s
    public const MODE_ISOLATED = 'isolated';  // > 30s

    // Time thresholds (seconds)
    private float $inlineThreshold;
    private float $pooledThreshold;

    // Blocking operation patterns
    private array $blockingPatterns = [
        // Sleep operations
        'sleep' => '/\b(sleep|usleep|time_nanosleep)\s*\(/i',

        // Heavy processing
        'image_processing' => '/\b(imagecreate|imagefilter|imagescale|gd_|ImageMagick|Intervention\\\\Image)\b/i',
        'video_processing' => '/\b(ffmpeg|video|encode|decode|transcode)\b/i',
        'pdf_generation' => '/\b(TCPDF|FPDF|DomPDF|Snappy|wkhtmltopdf)\b/i',
        'encryption' => '/\b(openssl_encrypt|openssl_decrypt|password_hash|bcrypt|argon2)\b/i',

        // External calls
        'http_sync' => '/\b(file_get_contents|curl_exec|Http::get|Http::post|Guzzle\\\\Client)\b/i',
        'shell_exec' => '/\b(shell_exec|exec|system|proc_open|passthru)\s*\(/i',

        // File operations
        'large_files' => '/\b(file_get_contents|fread|fwrite|copy|rename)\s*\(/i',

        // Database (synchronous)
        'db_heavy' => '/\b(DB::select|DB::insert|whereIn\(.*1000|chunk\(.*1000)\b/i',
    ];

    // Job name patterns indicating heavy work
    private array $heavyJobPatterns = [
        '/Process.*Video/i',
        '/Generate.*Report/i',
        '/Export.*Large/i',
        '/Compress.*Archive/i',
        '/Backup.*Database/i',
        '/Import.*Bulk/i',
        '/Migrate.*Data/i',
    ];

    // Lightweight job patterns
    private array $lightJobPatterns = [
        '/Send.*Email/i',
        '/Send.*Notification/i',
        '/Update.*Cache/i',
        '/Log.*Event/i',
        '/Dispatch.*Event/i',
        '/Trigger.*Webhook/i',
    ];

    public function __construct(
        float $inlineThreshold = 1.0,
        float $pooledThreshold = 30.0
    ) {
        $this->inlineThreshold = $inlineThreshold;
        $this->pooledThreshold = $pooledThreshold;
    }

    /**
     * Analyze job and determine optimal execution mode
     */
    public function analyze(KQueueJobInterface $job): string
    {
        $jobClass = get_class($job);

        // 1. Check if user explicitly specified mode
        $explicitMode = $this->getExplicitMode($job);
        if ($explicitMode !== null) {
            Log::debug("Job {$jobClass} using explicit mode: {$explicitMode}");
            return $explicitMode;
        }

        // 2. Check historical performance data
        $historicalMode = $this->analyzeHistoricalData($jobClass);
        if ($historicalMode !== null) {
            Log::debug("Job {$jobClass} using historical mode: {$historicalMode}");
            return $historicalMode;
        }

        // 3. Static code analysis
        $staticMode = $this->analyzeCode($job);
        if ($staticMode !== null) {
            Log::debug("Job {$jobClass} using static analysis mode: {$staticMode}");
            return $staticMode;
        }

        // 4. Name-based heuristics
        $heuristicMode = $this->analyzeJobName($jobClass);
        if ($heuristicMode !== null) {
            Log::debug("Job {$jobClass} using name heuristic mode: {$heuristicMode}");
            return $heuristicMode;
        }

        // 5. Default to pooled (safe middle ground)
        Log::debug("Job {$jobClass} using default mode: pooled");
        return self::MODE_POOLED;
    }

    /**
     * Check if job explicitly specifies execution mode
     */
    private function getExplicitMode(KQueueJobInterface $job): ?string
    {
        // Check $isolated property
        $isolated = $job->isIsolated();
        if ($isolated === true) {
            return self::MODE_ISOLATED;
        } elseif ($isolated === false) {
            return self::MODE_INLINE;
        }

        // Check $estimatedDuration property
        if (property_exists($job, 'estimatedDuration') && $job->estimatedDuration !== null) {
            $duration = $job->estimatedDuration;

            if ($duration <= $this->inlineThreshold) {
                return self::MODE_INLINE;
            } elseif ($duration <= $this->pooledThreshold) {
                return self::MODE_POOLED;
            } else {
                return self::MODE_ISOLATED;
            }
        }

        return null;
    }

    /**
     * Analyze historical performance data
     */
    private function analyzeHistoricalData(string $jobClass): ?string
    {
        $stats = Cache::get(self::CACHE_PREFIX . $jobClass);

        if (!$stats || $stats['executions'] < 3) {
            // Need at least 3 executions for reliable data
            return null;
        }

        $avgDuration = $stats['total_duration'] / $stats['executions'];

        if ($avgDuration <= $this->inlineThreshold) {
            return self::MODE_INLINE;
        } elseif ($avgDuration <= $this->pooledThreshold) {
            return self::MODE_POOLED;
        } else {
            return self::MODE_ISOLATED;
        }
    }

    /**
     * Static code analysis - inspect job source code
     */
    private function analyzeCode(KQueueJobInterface $job): ?string
    {
        try {
            $reflection = new \ReflectionClass($job);
            $fileName = $reflection->getFileName();

            if (!$fileName || !file_exists($fileName)) {
                return null;
            }

            $sourceCode = file_get_contents($fileName);

            // Detect blocking operations
            $blockingScore = 0;
            $detectedPatterns = [];

            foreach ($this->blockingPatterns as $type => $pattern) {
                if (preg_match($pattern, $sourceCode)) {
                    $detectedPatterns[] = $type;

                    // Weight different operations
                    $blockingScore += match($type) {
                        'sleep' => 10,
                        'video_processing', 'pdf_generation' => 8,
                        'image_processing', 'encryption' => 6,
                        'http_sync', 'shell_exec' => 5,
                        'large_files' => 4,
                        'db_heavy' => 3,
                        default => 2
                    };
                }
            }

            if (!empty($detectedPatterns)) {
                Log::debug("Detected blocking patterns in " . get_class($job), $detectedPatterns);
            }

            // Decision based on blocking score
            if ($blockingScore === 0) {
                return self::MODE_INLINE; // No blocking operations detected
            } elseif ($blockingScore <= 5) {
                return self::MODE_POOLED; // Light blocking
            } else {
                return self::MODE_ISOLATED; // Heavy blocking
            }

        } catch (\Throwable $e) {
            Log::warning("Failed to analyze job code: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Analyze job name for patterns
     */
    private function analyzeJobName(string $jobClass): ?string
    {
        // Check for heavy job patterns
        foreach ($this->heavyJobPatterns as $pattern) {
            if (preg_match($pattern, $jobClass)) {
                return self::MODE_ISOLATED;
            }
        }

        // Check for lightweight patterns
        foreach ($this->lightJobPatterns as $pattern) {
            if (preg_match($pattern, $jobClass)) {
                return self::MODE_INLINE;
            }
        }

        return null;
    }

    /**
     * Record job execution for historical analysis
     */
    public function recordExecution(string $jobClass, float $duration, bool $success): void
    {
        $cacheKey = self::CACHE_PREFIX . $jobClass;

        $stats = Cache::get($cacheKey, [
            'executions' => 0,
            'total_duration' => 0.0,
            'failures' => 0,
            'last_updated' => time(),
        ]);

        $stats['executions']++;
        $stats['total_duration'] += $duration;
        $stats['last_updated'] = time();

        if (!$success) {
            $stats['failures']++;
        }

        Cache::put($cacheKey, $stats, self::CACHE_TTL);
    }

    /**
     * Get statistics for a job class
     */
    public function getJobStats(string $jobClass): ?array
    {
        $stats = Cache::get(self::CACHE_PREFIX . $jobClass);

        if (!$stats) {
            return null;
        }

        return [
            'job_class' => $jobClass,
            'executions' => $stats['executions'],
            'avg_duration' => $stats['total_duration'] / $stats['executions'],
            'failure_rate' => $stats['failures'] / $stats['executions'],
            'recommended_mode' => $this->analyzeHistoricalData($jobClass) ?? 'pooled',
        ];
    }

    /**
     * Clear statistics for a job class
     */
    public function clearStats(string $jobClass): void
    {
        Cache::forget(self::CACHE_PREFIX . $jobClass);
    }

    /**
     * Update thresholds dynamically
     */
    public function setThresholds(float $inlineThreshold, float $pooledThreshold): void
    {
        $this->inlineThreshold = $inlineThreshold;
        $this->pooledThreshold = $pooledThreshold;
    }
}
