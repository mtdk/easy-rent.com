<?php

declare(strict_types=1);

namespace Tests\Integration;

use PHPUnit\Framework\TestCase;

final class SmokeIntegrationTest extends TestCase
{
    public function testPublicEntryFilesExist(): void
    {
        self::assertFileExists(__DIR__ . '/../../public/app.php');
        self::assertFileExists(__DIR__ . '/../../public/index.php');
    }

    public function testRegressionScriptExists(): void
    {
        self::assertFileExists(__DIR__ . '/../../scripts/run-regression.sh');
    }
}
