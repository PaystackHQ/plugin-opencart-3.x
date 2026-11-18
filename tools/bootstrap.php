<?php

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

$pass = 0;
$fail = 0;

function check($name, $cond)
{
    global $pass, $fail;
    if ($cond) {
        $pass++;
    } else {
        $fail++;
        echo "FAIL: $name\n";
    }
}

function same($name, $actual, $expected)
{
    $ok = $actual === $expected;
    check($name, $ok);
    if (!$ok) {
        echo '  expected ' . var_export($expected, true) . "\n  actual   " . var_export($actual, true) . "\n";
    }
}

function finish()
{
    global $pass, $fail;
    echo "passed: $pass, failed: $fail\n";
    exit($fail ? 1 : 0);
}
