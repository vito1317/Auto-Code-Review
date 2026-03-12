<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

$task = \App\Models\ReviewTask::find(1421);
$text = $task->ai_raw_output;
$text = trim($text);
$text = preg_replace('/<think>.*?<\/think>/s', '', $text);
$text = trim($text);

// Try to find the exact position where JSON fails
// by progressively parsing larger chunks
$len = strlen($text);
echo "Total JSON length: $len\n";

// Use json_decode error position - PHP 7.3+ reports position in error
json_decode($text, true, 512);
echo "JSON error: " . json_last_error_msg() . "\n";

// Find the problematic area by binary search
// First check if the outer structure is OK by extracting just summary + quality
if (preg_match('/"overall_quality"\s*:\s*"([^"]+)"/', $text, $m)) {
    echo "Quality found: " . $m[1] . "\n";
}

// Count findings by looking for severity fields
$findingsCount = preg_match_all('/"severity"\s*:\s*"/', $text, $m);
echo "Findings by severity count: $findingsCount\n";

// Try to find actual problematic characters
for ($i = 0; $i < $len; $i++) {
    $ch = ord($text[$i]);
    if ($ch < 32 && $ch != 10 && $ch != 13 && $ch != 9) {
        echo "Bad char at pos $i: 0x" . dechex($ch) . " in context: ..." . substr($text, max(0, $i-20), 40) . "...\n";
    }
}

// Try to find strings with unescaped control characters
if (preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f]/', $text, $match, PREG_OFFSET_CAPTURE)) {
    $pos = $match[0][1];
    echo "Found control char at pos $pos: 0x" . dechex(ord($match[0][0])) . "\n";
    echo "Context: " . json_encode(substr($text, max(0, $pos-30), 60)) . "\n";
}

// Try fixing: clean control characters and re-parse
$cleaned = preg_replace('/[\x00-\x08\x0b\x0c\x0e-\x1f]/', '', $text);
$p = json_decode($cleaned, true, 512);
echo "\nAfter cleaning control chars:\n";
echo "JSON error: " . json_last_error_msg() . "\n";
if (is_array($p)) {
    echo "Quality: " . ($p['overall_quality'] ?? 'N/A') . "\n";
    echo "Findings: " . count($p['findings'] ?? []) . "\n";
} else {
    // Maybe it's a depth issue or very nested content in code snippets
    echo "Still failing. Trying with higher depth...\n";
    $p = json_decode($cleaned, true, 1024);
    echo "JSON error (depth 1024): " . json_last_error_msg() . "\n";

    // Look for unescaped newlines inside strings
    // JSON standard requires \n not actual newlines inside strings
    // But LM Studio might output multiline strings
    if (preg_match('/"body"\s*:\s*"[^"]*\n[^"]*"/', $text)) {
        echo "Found unescaped newline inside a body string!\n";
    }
    if (preg_match('/"suggestion"\s*:\s*"[^"]*\n[^"]*"/', $text)) {
        echo "Found unescaped newline inside a suggestion string!\n";
    }
}
