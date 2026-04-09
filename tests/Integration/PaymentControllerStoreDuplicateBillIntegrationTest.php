<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Controllers\PaymentController;
use App\Controllers\PaymentControllerTestDoubleState;
use PHPUnit\Framework\TestCase;

final class PaymentControllerStoreDuplicateBillIntegrationTest extends TestCase
{
    private array $originalPost = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->originalPost = $_POST;
        PaymentControllerTestDoubleState::reset();
        PaymentControllerTestDoubleState::$isAdmin = false;
        PaymentControllerTestDoubleState::$isLandlord = true;
        PaymentControllerTestDoubleState::$user = ['id' => 1];
    }

    protected function tearDown(): void
    {
        $_POST = $this->originalPost;
        parent::tearDown();
    }

    public function testStoreRedirectsWithFlashWhenBillAlreadyExistsForPeriod(): void
    {
        PaymentControllerTestDoubleState::$dbFetchHandler = static function (string $sql, array $params): ?array {
            if (str_contains($sql, 'FROM contracts c')) {
                return [
                    'id' => 12,
                    'contract_number' => 'CT-12',
                    'tenant_name' => '测试租客',
                    'rent_amount' => '3500.00',
                    'payment_day' => 5,
                    'property_name' => 'A-101',
                    'owner_id' => 1,
                ];
            }

            if (str_contains($sql, 'FROM rent_payments WHERE contract_id = ? AND payment_period = ? LIMIT 1')) {
                return ['id' => 88];
            }

            return null;
        };

        $_POST = [
            '_token' => 'ok',
            'contract_id' => '12',
            'period' => '2026-04',
        ];

        $controller = new PaymentController();
        $response = $controller->store();

        self::assertSame(302, $response->getStatusCode());
        self::assertSame('/payments/create?contract_id=12&period=2026-04', (string) $response->getHeader('Location'));

        $error = (string) \App\Controllers\get_flash('error');
            self::assertSame('该合同当前周期账单已存在', $error);
    }
}
