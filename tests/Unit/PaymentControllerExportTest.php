<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Controllers\PaymentController;
use App\Controllers\PaymentControllerTestDoubleState;
use PHPUnit\Framework\TestCase;
use Tests\Support\UsesPaymentControllerTestDoubleState;

final class PaymentControllerExportTest extends TestCase
{
    use UsesPaymentControllerTestDoubleState;

    public function testPaymentsExportWithMeterDetailContainsDetailHeaderAndRows(): void
    {
        $_GET = [
            'meter_detail' => '1',
            'period' => '2026-04',
        ];

        PaymentControllerTestDoubleState::$paymentRows = [
            [
                'id' => 101,
                'payment_number' => 'PAY001',
                'payment_period' => '2026-04',
                'contract_number' => 'CT001',
                'tenant_name' => '张三',
                'property_name' => 'A101',
                'amount_due' => '1200.00',
                'payment_status' => 'pending',
                'due_date' => '2026-04-10',
                'paid_date' => '',
            ],
            [
                'id' => 102,
                'payment_number' => 'PAY002',
                'payment_period' => '2026-04',
                'contract_number' => 'CT002',
                'tenant_name' => '李四',
                'property_name' => 'B202',
                'amount_due' => '980.00',
                'payment_status' => 'paid',
                'due_date' => '2026-04-12',
                'paid_date' => '2026-04-11',
            ],
        ];

        PaymentControllerTestDoubleState::$meterRowsByPaymentId = [
            101 => [
                [
                    'meter_type' => 'water',
                    'meter_code_snapshot' => 'W-01',
                    'meter_name_snapshot' => '主水表',
                    'previous_reading' => '10.00',
                    'current_reading' => '15.00',
                    'usage_amount' => '5.00',
                    'unit_price' => '3.5000',
                    'line_amount' => '17.50',
                ],
            ],
            102 => [],
        ];

        $controller = new PaymentController();
        $response = $controller->export();
        $content = (string) $response->getContent();
        $disposition = (string) $response->getHeader('Content-Disposition');

        self::assertStringContainsString('支付编号,账期,合同编号,租客,房产,应收总额,状态,到期日,支付日期,表计类型,表计编号,表计名称,上月读数,本月读数,当月用量,单价,费用', $content);
        self::assertStringContainsString('"PAY001","2026-04","CT001","张三","A101","1200.00","pending","2026-04-10","","水表","W-01","主水表","10.00","15.00","5.00","3.5000","17.50"', $content);
        self::assertStringContainsString('"PAY002","2026-04","CT002","李四","B202","980.00","paid","2026-04-12","2026-04-11","","","","","","","",""', $content);
        self::assertStringContainsString('rent-bills-2026-04-meter-details.csv', $disposition);
    }

    public function testPaymentsExportWithoutMeterDetailContainsFormulaColumns(): void
    {
        $_GET = [
            'period' => '2026-05',
        ];

        PaymentControllerTestDoubleState::$paymentRows = [
            [
                'id' => 201,
                'payment_number' => 'PAY101',
                'payment_period' => '2026-05',
                'contract_number' => 'CT101',
                'tenant_name' => '王五',
                'property_name' => 'C303',
                'amount_due' => '1680.00',
                'payment_status' => 'partial',
                'due_date' => '2026-05-12',
                'paid_date' => '2026-05-10',
                'notes' => json_encode([
                    'bill_type' => 'metered_rent',
                    'bill_schema_version' => 2,
                    'formula' => [
                        'rent_amount' => 1500,
                        'water_previous' => 11,
                        'water_current' => 16,
                        'water_usage' => 5,
                        'water_unit_price' => 3.5,
                        'water_fee' => 17.5,
                        'electric_previous' => 101,
                        'electric_current' => 131,
                        'electric_usage' => 30,
                        'electric_unit_price' => 5.4,
                        'electric_fee' => 162,
                    ],
                ], JSON_UNESCAPED_UNICODE),
            ],
        ];

        PaymentControllerTestDoubleState::$meterRowsByPaymentId = [];

        $controller = new PaymentController();
        $response = $controller->export();
        $content = (string) $response->getContent();
        $disposition = (string) $response->getHeader('Content-Disposition');

        self::assertStringContainsString('支付编号,账期,合同编号,租客,房产,固定租金,上月水表,本月水表,当月水量,水费单价,水费,上月电表,本月电表,当月电量,电费单价,电费,应收总额,状态,到期日,支付日期', $content);
        self::assertStringContainsString('"PAY101","2026-05","CT101","王五","C303","1500","11","16","5","3.5","17.5","101","131","30","5.4","162","1680.00","partial","2026-05-12","2026-05-10"', $content);
        self::assertStringNotContainsString('表计类型', $content);
        self::assertStringContainsString('rent-bills-2026-05.csv', $disposition);
    }
}
