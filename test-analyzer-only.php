<?php

require_once '/kqueue/vendor/autoload.php';

use KQueue\Analysis\JobAnalyzer;

$analyzer = new JobAnalyzer(
    inlineThreshold: 1.0,
    pooledThreshold: 30.0
);

echo "\n" . str_repeat('=', 70) . "\n";
echo "🧠 SMART JOB ANALYZER - CODE ANALYSIS TEST\n";
echo str_repeat('=', 70) . "\n\n";

// Test job class paths
$jobs = [
    'SendEmailJob' => '/kqueue/laravel-test/laravel-app/laravel-app/app/Jobs/SendEmailJob.php',
    'ProcessImageJob' => '/kqueue/laravel-test/laravel-app/laravel-app/app/Jobs/ProcessImageJob.php',
    'ProcessVideoJob' => '/kqueue/laravel-test/laravel-app/laravel-app/app/Jobs/ProcessVideoJob.php',
];

foreach ($jobs as $name => $path) {
    echo "Analyzing: {$name}\n";
    echo str_repeat('-', 70) . "\n";
    
    if (!file_exists($path)) {
        echo "❌ File not found: {$path}\n\n";
        continue;
    }
    
    $code = file_get_contents($path);
    
    // Check for patterns
    $patterns = [
        'sleep' => '/\\b(sleep|usleep|time_nanosleep)\\s*\\(/i',
        'image' => '/\\b(imagecreate|imagefilter|imagescale|gd_|ImageMagick)\\b/i',
        'video' => '/\\b(ffmpeg|video|encode|transcode)\\b/i',
        'shell' => '/\\b(shell_exec|exec|system|proc_open)\\s*\\(/i',
    ];
    
    $detected = [];
    $score = 0;
    
    foreach ($patterns as $type => $pattern) {
        if (preg_match($pattern, $code)) {
            $detected[] = $type;
            $score += match($type) {
                'sleep' => 10,
                'video' => 8,
                'image' => 6,
                'shell' => 5,
                default => 2
            };
        }
    }
    
    $mode = match(true) {
        $score === 0 => 'INLINE',
        $score <= 5 => 'POOLED',
        default => 'ISOLATED'
    };
    
    echo "Detected patterns: " . (empty($detected) ? 'none' : implode(', ', $detected)) . "\n";
    echo "Blocking score: {$score}\n";
    echo "Recommended mode: " . $mode . "\n";
    
    // Name pattern matching
    if (preg_match('/ProcessVideo/i', $name)) {
        echo "Name pattern: Matches 'ProcessVideo*' → ISOLATED\n";
    } elseif (preg_match('/SendEmail/i', $name)) {
        echo "Name pattern: Matches 'SendEmail*' → INLINE\n";
    }
    
    echo "\n";
}

echo str_repeat('=', 70) . "\n";
echo "✅ Analysis complete!\n";
echo str_repeat('=', 70) . "\n";
