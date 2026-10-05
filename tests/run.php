<?php
declare(strict_types=1);

/*
 * Run the test suite without PHPUnit (see tests/phpunit-shim.php). With PHPUnit installed,
 * run vendor/bin/phpunit instead; both use tests/bootstrap.php.
 *
 *   php tests/run.php [filter]      filter matches "Class::method" substrings
 *
 * The test database comes from PFPMS_TEST_CONFIG or config/config.test.php, and its name
 * must end in _test: it is emptied and rebuilt from migrations at the start of every run.
 */

if (PHP_SAPI !== 'cli') {
    exit;
}

require __DIR__ . '/phpunit-shim.php';
require __DIR__ . '/bootstrap.php';

$filter = $argv[1] ?? '';
$files = [];
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(__DIR__, FilesystemIterator::SKIP_DOTS));
foreach ($iterator as $file) {
    if (str_ends_with($file->getFilename(), 'Test.php')) {
        $files[] = $file->getPathname();
    }
}
sort($files);

$passed = $failed = $assertions = 0;
$failures = [];
foreach ($files as $path) {
    $before = get_declared_classes();
    require_once $path;
    foreach (array_diff(get_declared_classes(), $before) as $class) {
        $reflection = new ReflectionClass($class);
        if ($reflection->isAbstract() || !$reflection->isSubclassOf(PHPUnit\Framework\TestCase::class)) {
            continue;
        }
        foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if (!str_starts_with($method->getName(), 'test')) {
                continue;
            }
            $name = $reflection->getShortName() . '::' . $method->getName();
            if ($filter !== '' && !str_contains($name, $filter)) {
                continue;
            }
            $test = new $class();
            try {
                $test->runTest($method->getName());
                $passed++;
                echo '.';
            } catch (Throwable $e) {
                $failed++;
                echo 'F';
                $failures[] = "$name\n    " . get_class($e) . ': ' . $e->getMessage() . "\n    at " . basename($e->getFile()) . ':' . $e->getLine();
            }
            $assertions += $test->assertions;
        }
    }
}

echo "\n\n";
foreach ($failures as $i => $failure) {
    echo ($i + 1) . ") $failure\n\n";
}
printf("%s: %d tests, %d assertions, %d failures\n", $failed ? 'FAILED' : 'OK', $passed + $failed, $assertions, $failed);
exit($failed ? 1 : 0);
