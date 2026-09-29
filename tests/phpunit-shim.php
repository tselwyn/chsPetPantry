<?php
declare(strict_types=1);

/*
 * Minimal stand-in for PHPUnit\Framework\TestCase, used by tests/run.php only while
 * PHPUnit is not installed (Composer is not yet set up on every machine). The tests are
 * ordinary PHPUnit tests: once `composer install` has run, use vendor/bin/phpunit instead.
 * Supports only what the tests use.
 */

namespace PHPUnit\Framework;

if (!class_exists(TestCase::class, false)) {
    class AssertionFailedError extends \RuntimeException
    {
    }

    abstract class TestCase
    {
        private ?string $expectedException = null;
        private ?string $expectedMessage = null;
        public int $assertions = 0;

        protected function setUp(): void
        {
        }

        protected function tearDown(): void
        {
        }

        public function runTest(string $method): void
        {
            $this->expectedException = null;
            $this->expectedMessage = null;
            $this->setUp();
            try {
                try {
                    $this->$method();
                } catch (AssertionFailedError $e) {
                    throw $e;
                } catch (\Throwable $e) {
                    if ($this->expectedException !== null && $e instanceof $this->expectedException) {
                        if ($this->expectedMessage !== null && !str_contains($e->getMessage(), $this->expectedMessage)) {
                            throw new AssertionFailedError("Exception message '{$e->getMessage()}' does not contain '{$this->expectedMessage}'");
                        }
                        $this->assertions++;
                        return;
                    }
                    throw $e;
                }
                if ($this->expectedException !== null) {
                    throw new AssertionFailedError("Expected exception {$this->expectedException} was not thrown");
                }
            } finally {
                $this->tearDown();
            }
        }

        public function expectException(string $class): void
        {
            $this->expectedException = $class;
        }

        public function expectExceptionMessage(string $message): void
        {
            $this->expectedMessage = $message;
        }

        public function assertTrue(mixed $value, string $message = ''): void
        {
            $this->check($value === true, $message ?: 'Expected true, got ' . var_export($value, true));
        }

        public function assertFalse(mixed $value, string $message = ''): void
        {
            $this->check($value === false, $message ?: 'Expected false, got ' . var_export($value, true));
        }

        public function assertNull(mixed $value, string $message = ''): void
        {
            $this->check($value === null, $message ?: 'Expected null, got ' . var_export($value, true));
        }

        public function assertNotNull(mixed $value, string $message = ''): void
        {
            $this->check($value !== null, $message ?: 'Expected a value, got null');
        }

        public function assertSame(mixed $expected, mixed $actual, string $message = ''): void
        {
            $this->check($expected === $actual, $message ?: 'Expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
        }

        public function assertNotSame(mixed $expected, mixed $actual, string $message = ''): void
        {
            $this->check($expected !== $actual, $message ?: 'Did not expect ' . var_export($actual, true));
        }

        public function assertEquals(mixed $expected, mixed $actual, string $message = ''): void
        {
            $this->check($expected == $actual, $message ?: 'Expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
        }

        public function assertCount(int $count, \Countable|array $value, string $message = ''): void
        {
            $this->check(count($value) === $count, $message ?: "Expected $count items, got " . count($value));
        }

        public function assertContains(mixed $needle, iterable $haystack, string $message = ''): void
        {
            $this->check(in_array($needle, is_array($haystack) ? $haystack : iterator_to_array($haystack), true),
                $message ?: 'Expected to contain ' . var_export($needle, true));
        }

        public function assertNotContains(mixed $needle, iterable $haystack, string $message = ''): void
        {
            $this->check(!in_array($needle, is_array($haystack) ? $haystack : iterator_to_array($haystack), true),
                $message ?: 'Did not expect to contain ' . var_export($needle, true));
        }

        public function assertStringContainsString(string $needle, string $haystack, string $message = ''): void
        {
            $this->check(str_contains($haystack, $needle), $message ?: "Expected '$haystack' to contain '$needle'");
        }

        public function assertEmpty(mixed $value, string $message = ''): void
        {
            $this->check(empty($value), $message ?: 'Expected empty, got ' . var_export($value, true));
        }

        public function assertNotEmpty(mixed $value, string $message = ''): void
        {
            $this->check(!empty($value), $message ?: 'Expected a non-empty value');
        }

        public function assertGreaterThan(int|float $expected, int|float $actual, string $message = ''): void
        {
            $this->check($actual > $expected, $message ?: "Expected more than $expected, got $actual");
        }

        public static function fail(string $message = ''): never
        {
            throw new AssertionFailedError($message ?: 'Failed');
        }

        private function check(bool $ok, string $message): void
        {
            $this->assertions++;
            if (!$ok) {
                throw new AssertionFailedError($message);
            }
        }
    }
}
