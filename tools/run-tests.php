<?php

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

$failed = array();
$files = glob(__DIR__ . '/test-*.php');
sort($files);

foreach ($files as $file) {
    echo '== ' . basename($file) . "\n";
    passthru(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($file), $code);
    if ($code !== 0) {
        $failed[] = basename($file);
    }
}

echo "\n" . (count($files) - count($failed)) . ' of ' . count($files) . " test files passed\n";
if ($failed) {
    echo 'Failed: ' . implode(', ', $failed) . "\n";
}
exit($failed || !$files ? 1 : 0);
