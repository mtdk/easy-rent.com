<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Controllers\PaymentControllerTestDoubleState;

/**
 * Initializes and restores global test state used by PaymentController tests.
 */
trait UsesPaymentControllerTestDoubleState
{
    private array $originalGet = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->originalGet = $_GET;
        PaymentControllerTestDoubleState::reset();
    }

    protected function tearDown(): void
    {
        $_GET = $this->originalGet;

        parent::tearDown();
    }
}
