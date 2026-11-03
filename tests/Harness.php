<?php

final class AssertionFailed extends \Exception
{
}

final class SkipTest extends \Exception
{
}

final class Harness
{
    private static array $tests = [];
    private static string $suite = '';

    public static function suite(string $name): void
    {
        self::$suite = $name;
    }

    public static function add(string $name, callable $fn): void
    {
        self::$tests[] = [self::$suite, $name, $fn];
    }

    public static function run(): int
    {
        $passed = 0;
        $failed = 0;
        $skipped = 0;
        $started = microtime(true);
        foreach (self::$tests as [$suite, $name, $fn]) {
            $t = microtime(true);
            try {
                $fn();
                $passed++;
                printf("  PASS  [%s] %s (%.2fs)\n", $suite, $name, microtime(true) - $t);
            } catch (SkipTest $e) {
                $skipped++;
                printf("  SKIP  [%s] %s: %s\n", $suite, $name, $e->getMessage());
            } catch (\Throwable $e) {
                $failed++;
                printf("  FAIL  [%s] %s\n        %s: %s\n        at %s:%d\n", $suite, $name, get_class($e), $e->getMessage(), $e->getFile(), $e->getLine());
            }
        }
        printf("\n%d passed, %d failed, %d skipped, %d total (%.1fs)\n", $passed, $failed, $skipped, $passed + $failed + $skipped, microtime(true) - $started);
        return $failed > 0 ? 1 : 0;
    }
}

function test(string $name, callable $fn): void
{
    Harness::add($name, $fn);
}

function skip(string $reason): void
{
    throw new SkipTest($reason);
}

function fail(string $message): void
{
    $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS);
    foreach ($trace as $frame) {
        if (isset($frame['file']) && $frame['file'] !== __FILE__) {
            $message .= ' (line ' . $frame['line'] . ' of ' . basename($frame['file']) . ')';
            break;
        }
    }
    throw new AssertionFailed($message);
}

function export($value): string
{
    return str_replace("\n", ' ', var_export($value, true));
}

function assert_true($value, string $message = 'expected true'): void
{
    if ($value !== true) {
        fail($message . ', got ' . export($value));
    }
}

function assert_same($expected, $actual, string $message = ''): void
{
    if ($expected !== $actual) {
        fail(($message !== '' ? $message . ': ' : '') . 'expected ' . export($expected) . ', got ' . export($actual));
    }
}

function assert_equals($expected, $actual, string $message = ''): void
{
    if ($expected != $actual) {
        fail(($message !== '' ? $message . ': ' : '') . 'expected ' . export($expected) . ', got ' . export($actual));
    }
}

function assert_throws(string $class, callable $fn, ?callable $check = null)
{
    try {
        $fn();
    } catch (\Throwable $e) {
        if (!($e instanceof $class)) {
            fail('expected ' . $class . ', got ' . get_class($e) . ': ' . $e->getMessage());
        }
        if ($check !== null && $check($e) !== true) {
            fail('exception ' . get_class($e) . ' did not match: ' . $e->getMessage());
        }
        return $e;
    }
    fail('expected ' . $class . ' to be thrown');
}
