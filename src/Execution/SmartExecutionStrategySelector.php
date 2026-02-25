<?php

namespace KQueue\Execution;

use KQueue\Contracts\KQueueJobInterface;
use KQueue\Contracts\ExecutionStrategy;
use KQueue\Analysis\JobAnalyzer;
use Illuminate\Support\Facades\Log;

/**
 * Smart Execution Strategy Selector
 *
 * Automatically selects the optimal execution strategy based on job analysis.
 * Works like Node.js import parser - analyzes jobs and makes intelligent decisions.
 *
 * Decision Flow:
 * 1. Analyze job (explicit hints → historical data → code analysis → name patterns)
 * 2. Map analysis result to execution strategy
 * 3. Return appropriate strategy
 */
class SmartExecutionStrategySelector
{
    private JobAnalyzer $analyzer;
    private array $strategyMap = [];
    private array $executionStats = [];

    public function __construct(JobAnalyzer $analyzer)
    {
        $this->analyzer = $analyzer;
    }

    /**
     * Register a strategy for a specific execution mode
     */
    public function registerStrategy(string $mode, ExecutionStrategy $strategy): void
    {
        $this->strategyMap[$mode] = $strategy;
    }

    /**
     * Select optimal execution strategy for the job
     */
    public function selectStrategy(KQueueJobInterface $job): ExecutionStrategy
    {
        // Analyze the job
        $mode = $this->analyzer->analyze($job);

        // Get the corresponding strategy
        if (!isset($this->strategyMap[$mode])) {
            throw new \RuntimeException("No strategy registered for mode: {$mode}");
        }

        $strategy = $this->strategyMap[$mode];

        // Log the decision
        Log::info("Selected execution strategy", [
            'job' => get_class($job),
            'job_id' => $job->getJobId(),
            'mode' => $mode,
            'strategy' => get_class($strategy),
        ]);

        // Track stats
        $this->recordStrategySelection($mode);

        return $strategy;
    }

    /**
     * Get execution statistics
     */
    public function getStats(): array
    {
        return $this->executionStats;
    }

    /**
     * Record strategy selection for monitoring
     */
    private function recordStrategySelection(string $mode): void
    {
        if (!isset($this->executionStats[$mode])) {
            $this->executionStats[$mode] = 0;
        }
        $this->executionStats[$mode]++;
    }
}
