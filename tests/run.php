<?php

/**
 * Runs every tests/*Test.php in one process. Run: php tests/run.php
 */

define('TESTS_RUNNER', true);

require_once __DIR__ . '/bootstrap.php';

$testFiles = glob(__DIR__ . '/*Test.php');
sort($testFiles);

foreach($testFiles as $testFile)
{
    $before = $GLOBALS['tests'];
    tests_reset();

    require $testFile;

    printf(
        "%s: %d assertions, %d failures\n",
        basename($testFile),
        $GLOBALS['tests']['assertions'] - $before['assertions'],
        $GLOBALS['tests']['failures'] - $before['failures']
    );
}

$t = $GLOBALS['tests'];
echo ($t['failures'] === 0 ? 'OK' : 'FAILED') . " (" . count($testFiles) . " files, {$t['assertions']} assertions, {$t['failures']} failures)\n";
exit($t['failures'] === 0 ? 0 : 1);
