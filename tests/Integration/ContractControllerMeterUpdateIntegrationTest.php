<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Controllers\ContractController;
use App\Controllers\PaymentControllerTestDoubleState;
use PHPUnit\Framework\TestCase;

final class ContractControllerMeterUpdateIntegrationTest extends TestCase
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

        PaymentControllerTestDoubleState::$dbFetchAllHandler = static function (string $sql, array $params): array {
            if (str_contains($sql, 'FROM meter_types')) {
                return [
                    ['type_key' => 'water', 'type_name' => '水表', 'default_code_prefix' => 'WATER', 'sort_order' => 10],
                    ['type_key' => 'electric', 'type_name' => '电表', 'default_code_prefix' => 'ELECTRIC', 'sort_order' => 20],
                ];
            }

            return [];
        };
    }

    protected function tearDown(): void
    {
        $_POST = $this->originalPost;
        parent::tearDown();
    }

    public function testUpdateMeterAllowsFixingInitialReadingWhenInputValid(): void
    {
        PaymentControllerTestDoubleState::$dbFetchHandler = static function (string $sql, array $params): ?array {
            if (str_contains($sql, 'FROM contracts c')) {
                return [
                    'id' => 100,
                    'property_id' => 88,
                    'contract_number' => 'CT-100',
                    'tenant_name' => '测试租客',
                    'property_name' => 'A-101',
                    'owner_id' => 1,
                ];
            }

            if (str_contains($sql, 'FROM contract_meters WHERE id = ? LIMIT 1')) {
                return ['id' => 9, 'contract_id' => 100];
            }

            if (str_contains($sql, 'FROM contract_meters WHERE contract_id = ? AND meter_code = ? AND id <> ? LIMIT 1')) {
                return null;
            }

            return null;
        };

        $_POST = [
            '_token' => 'ok',
            'meter_type' => 'water',
            'meter_code' => 'W-001',
            'meter_name' => '主水表',
            'default_unit_price' => '3.4567',
            'initial_reading' => '123.4',
        ];

        $controller = new ContractController();
        $response = $controller->updateMeter(100, 9);

        self::assertSame(302, $response->getStatusCode());
        self::assertSame('/contracts/100', (string) $response->getHeader('Location'));
        self::assertSame('表计信息已更新', (string) \App\Controllers\get_flash('success'));
        self::assertCount(1, PaymentControllerTestDoubleState::$dbUpdateCalls);

        $update = PaymentControllerTestDoubleState::$dbUpdateCalls[0];
        self::assertSame('contract_meters', $update['table']);
        self::assertSame(['id' => 9], $update['where']);
        self::assertSame('123.40', (string) ($update['data']['initial_reading'] ?? ''));
        self::assertSame('3.4567', (string) ($update['data']['default_unit_price'] ?? ''));
        self::assertSame('water', (string) ($update['data']['meter_type'] ?? ''));
        self::assertSame('W-001', (string) ($update['data']['meter_code'] ?? ''));
    }

    public function testUpdateMeterRejectsDuplicateMeterCodeInSameContract(): void
    {
        PaymentControllerTestDoubleState::$dbFetchHandler = static function (string $sql, array $params): ?array {
            if (str_contains($sql, 'FROM contracts c')) {
                return [
                    'id' => 100,
                    'property_id' => 88,
                    'contract_number' => 'CT-100',
                    'tenant_name' => '测试租客',
                    'property_name' => 'A-101',
                    'owner_id' => 1,
                ];
            }

            if (str_contains($sql, 'FROM contract_meters WHERE id = ? LIMIT 1')) {
                return ['id' => 9, 'contract_id' => 100];
            }

            if (str_contains($sql, 'FROM contract_meters WHERE contract_id = ? AND meter_code = ? AND id <> ? LIMIT 1')) {
                return ['id' => 77];
            }

            return null;
        };

        $_POST = [
            '_token' => 'ok',
            'meter_type' => 'water',
            'meter_code' => 'W-EXISTS',
            'meter_name' => '重复编号',
            'default_unit_price' => '3.0000',
            'initial_reading' => '10.00',
        ];

        $controller = new ContractController();
        $response = $controller->updateMeter(100, 9);

        self::assertSame(302, $response->getStatusCode());
        self::assertSame('/contracts/100', (string) $response->getHeader('Location'));
        self::assertSame('该合同下已存在相同表计编号', (string) \App\Controllers\get_flash('error'));
        self::assertCount(0, PaymentControllerTestDoubleState::$dbUpdateCalls);
    }

    public function testUpdateMeterRejectsNegativeInitialReading(): void
    {
        PaymentControllerTestDoubleState::$dbFetchHandler = static function (string $sql, array $params): ?array {
            if (str_contains($sql, 'FROM contracts c')) {
                return [
                    'id' => 100,
                    'property_id' => 88,
                    'contract_number' => 'CT-100',
                    'tenant_name' => '测试租客',
                    'property_name' => 'A-101',
                    'owner_id' => 1,
                ];
            }

            if (str_contains($sql, 'FROM contract_meters WHERE id = ? LIMIT 1')) {
                return ['id' => 9, 'contract_id' => 100];
            }

            return null;
        };

        $_POST = [
            '_token' => 'ok',
            'meter_type' => 'water',
            'meter_code' => 'W-001',
            'meter_name' => '主水表',
            'default_unit_price' => '3.0000',
            'initial_reading' => '-0.01',
        ];

        $controller = new ContractController();
        $response = $controller->updateMeter(100, 9);

        self::assertSame(302, $response->getStatusCode());
        self::assertSame('/contracts/100', (string) $response->getHeader('Location'));
        self::assertSame('初始读数格式无效', (string) \App\Controllers\get_flash('error'));
        self::assertCount(0, PaymentControllerTestDoubleState::$dbUpdateCalls);
    }
}
