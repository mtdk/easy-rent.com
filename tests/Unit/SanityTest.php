<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class SanityTest extends TestCase
{
    public function testEnvHelperReturnsDefaultForMissingKey(): void
    {
        $value = env('EASY_RENT_TEST_MISSING_KEY', 'fallback-value');

        self::assertSame('fallback-value', $value);
    }

    public function testControllerClassCanBeAutoloaded(): void
    {
        self::assertTrue(class_exists(\App\Controllers\PaymentController::class));
    }
}
