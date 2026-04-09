<?php

declare(strict_types=1);

namespace Tests\Support;

use ReflectionMethod;

/**
 * Reflection helper for invoking non-public methods in focused unit tests.
 */
trait InvokesPrivateMethods
{
    protected function invokePrivate(object $target, string $method, array $args = []): mixed
    {
        $reflection = new ReflectionMethod($target, $method);
        $reflection->setAccessible(true);

        return $reflection->invokeArgs($target, $args);
    }
}
