<?php

require_once '/kqueue/vendor/autoload.php';

echo "\n" . str_repeat('=', 70) . "\n";
echo "🧠 SMART JOB ANALYZER - 5 JOB ANALYSIS\n";
echo str_repeat('=', 70) . "\n\n";

$jobs = [
    'UpdateCacheJob' => '/kqueue/laravel-test/laravel-app/laravel-app/app/Jobs/UpdateCacheJob.php',
    'LogEventJob' => '/kqueue/laravel-test/laravel-app/laravel-app/app/Jobs/LogEventJob.php',
    'SendNotificationJob' => '/kqueue/laravel-test/laravel-app/laravel-app/app/Jobs/SendNotificationJob.php',
    'GenerateReportJob' => '/kqueue/laravel-test/laravel-app/laravel-app/app/Jobs/GenerateReportJob.php',
    'TriggerWebhookJob' => '/kqueue/laravel-test/laravel-app/laravel-app/app/Jobs/TriggerWebhookJob.php',
];

$patterns = [
    'sleep' => '/\\b(sleep|usleep|time_nanosleep)\\s*\\(/i',
    'image' => '/\\b(imagecreate|imagefilter|imagescale|gd_|ImageMagick)\\b/i',
    'video' => '/\\b(ffmpeg|video|encode|transcode)\\b/i',
    'pdf' => '/\\b(TCPDF|FPDF|DomPDF|wkhtmltopdf)\\b/i',
    'shell' => '/\\b(shell_exec|exec|system|proc_open)\\s*\\(/i',
    'http' => '/\\b(file_get_contents|curl_exec)\\s*\\(/i',
];

$namePatterns = [
    'heavy' => ['/ProcessVideo/i', '/Generate.*Report/i', '/Export.*Large/i', '/Backup.*Database/i'],
    'light' => ['/Send.*Email/i', '/Send.*Notification/i', '/Update.*Cache/i', '/Log.*Event/i', '/Trigger.*Webhook/i'],
];

foreach ($jobs as $name => $path) {
    echo "Job: " . str_pad($name, 25) . " ";
    
    if (!file_exists($path)) {
        echo "❌ File not found\n";
        continue;
    }
    
    $code = file_get_contents($path);
    
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
                'pdf' => 5,
                'http' => 3,
                default => 2
            };
        }
    }
    
    // Name pattern check
    $nameMatch = 'none';
    foreach ($namePatterns['heavy'] as $pattern) {
        if (preg_match($pattern, $name)) {
            $nameMatch = 'heavy';
            break;
        }
    }
    if ($nameMatch === 'none') {
        foreach ($namePatterns['light'] as $pattern) {
            if (preg_match($pattern, $name)) {
                $nameMatch = 'light';
                break;
            }
        }
    }
    
    $mode = match(true) {
        $score === 0 && $nameMatch === 'light' => 'INLINE',
        $score === 0 && $nameMatch === 'heavy' => 'ISOLATED',
        $score === 0 => 'POOLED',
        $score <= 5 => 'POOLED',
        default => 'ISOLATED'
    };
    
    $icon = match($mode) {
        'INLINE' => '⚡',
        'POOLED' => '⚙️',
        'ISOLATED' => '🔒'
    };
    
    echo $icon . " " . str_pad($mode, 10);
    echo " | Score: " . str_pad($score, 2, ' ', STR_PAD_LEFT);
    echo " | Name: " . str_pad($nameMatch, 5);
    echo " | Patterns: " . (empty($detected) ? 'none' : implode(', ', $detected));
    echo "\n";
}

echo "\n" . str_repeat('=', 70) . "\n";
echo "Legend: ⚡ INLINE (fast) | ⚙️ POOLED (medium) | 🔒 ISOLATED (heavy)\n";
echo str_repeat('=', 70) . "\n";
