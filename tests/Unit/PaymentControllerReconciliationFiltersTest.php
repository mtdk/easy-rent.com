<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Controllers\PaymentController;
use App\Controllers\PaymentControllerTestDoubleState;
use PHPUnit\Framework\TestCase;
use Tests\Support\InvokesPrivateMethods;
use Tests\Support\ParsesQueryFromLink;
use Tests\Support\UsesPaymentControllerTestDoubleState;

final class PaymentControllerReconciliationFiltersTest extends TestCase
{
    use InvokesPrivateMethods;
    use ParsesQueryFromLink;
    use UsesPaymentControllerTestDoubleState;

    public function testCollectReconciliationFiltersValidatesAndNormalizesMeterFilters(): void
    {
        $_GET = [
            'keyword' => 'A-101',
            'period_from' => '2026-05',
            'period_to' => '2026-04',
            'meter_type' => 'invalid-type',
            'meter_code' => str_repeat('x', 80),
            'sort_by' => 'bad',
            'sort_dir' => 'sideways',
            'unpaid_only' => '0',
            'lite' => '2',
            'summary_ref' => 'BAD-REF',
        ];

        $controller = new PaymentController();
        $filters = $this->invokePrivate($controller, 'collectReconciliationFiltersFromQuery');

        self::assertSame('2026-04', $filters['period_from']);
        self::assertSame('2026-05', $filters['period_to']);
        self::assertSame('', $filters['meter_type']);
        self::assertSame(60, strlen((string) $filters['meter_code']));
        self::assertSame('payment_period', $filters['sort_by']);
        self::assertSame('desc', $filters['sort_dir']);
        self::assertSame('', $filters['unpaid_only']);
        self::assertSame('', $filters['lite']);
        self::assertSame('', $filters['summary_ref']);
    }

    public function testBuildReconciliationSortLinkKeepsMeterFiltersAndTogglesDirection(): void
    {
        $controller = new PaymentController();
        $filters = [
            'keyword' => 'A-101',
            'period_from' => '2026-01',
            'period_to' => '2026-06',
            'meter_type' => 'water',
            'meter_code' => 'W-001',
            'unpaid_only' => '1',
            'lite' => '1',
            'sort_by' => 'paid_rate',
            'sort_dir' => 'asc',
        ];

        $link = $this->invokePrivate($controller, 'buildReconciliationSortLink', [$filters, 'paid_rate']);
        $query = $this->queryFromLink((string) $link);

        self::assertSame('water', $query['meter_type'] ?? null);
        self::assertSame('W-001', $query['meter_code'] ?? null);
        self::assertSame('paid_rate', $query['sort_by'] ?? null);
        self::assertSame('desc', $query['sort_dir'] ?? null);
    }

    public function testQuickRangeLinksPreserveMeterFilters(): void
    {
        $controller = new PaymentController();

        $recentLink = $this->invokePrivate($controller, 'buildRecentPeriodLink', [3, 'A-101', true, 'unpaid_amount', 'asc', true, 'electric', 'E-201']);
        $recentQuery = $this->queryFromLink((string) $recentLink);

        self::assertSame('electric', $recentQuery['meter_type'] ?? null);
        self::assertSame('E-201', $recentQuery['meter_code'] ?? null);
        self::assertSame('1', $recentQuery['unpaid_only'] ?? null);
        self::assertSame('1', $recentQuery['lite'] ?? null);

        $yearLink = $this->invokePrivate($controller, 'buildCurrentYearLink', ['A-101', true, 'paid_rate', 'asc', false, 'water', 'W-203']);
        $yearQuery = $this->queryFromLink((string) $yearLink);

        self::assertSame('water', $yearQuery['meter_type'] ?? null);
        self::assertSame('W-203', $yearQuery['meter_code'] ?? null);
        self::assertSame('paid_rate', $yearQuery['sort_by'] ?? null);
        self::assertSame('asc', $yearQuery['sort_dir'] ?? null);
    }

    public function testReconciliationTemplateExportLinkIncludesMeterFilters(): void
    {
        $controller = new PaymentController();

        $rows = [
            [
                'payment_period' => '2026-04',
                'bill_count' => 2,
                'receivable_amount' => 3200.00,
                'received_amount' => 2800.00,
                'paid_count' => 1,
            ],
        ];
        $filters = [
            'keyword' => 'A-101',
            'period_from' => '2026-01',
            'period_to' => '2026-04',
            'meter_type' => 'water',
            'meter_code' => 'W-009',
            'unpaid_only' => '1',
            'lite' => '1',
            'sort_by' => 'unpaid_amount',
            'sort_dir' => 'asc',
            'bom' => '0',
        ];

        $html = (string) $this->invokePrivate($controller, 'reconciliationTemplate', [$rows, $filters, []]);

        self::assertStringContainsString('/payments/reconciliation/export?', $html);
        self::assertStringContainsString('meter_type=water', $html);
        self::assertStringContainsString('meter_code=W-009', $html);
        self::assertStringContainsString('sort_by=unpaid_amount', $html);
        self::assertStringContainsString('sort_dir=asc', $html);
        self::assertStringContainsString('summary_ref=', $html);
    }

    public function testReconciliationTemplatePrintHeaderContainsMeterFilterLabels(): void
    {
        $controller = new PaymentController();

        $rows = [
            [
                'payment_period' => '2026-04',
                'bill_count' => 1,
                'receivable_amount' => 1000.00,
                'received_amount' => 1000.00,
                'paid_count' => 1,
            ],
        ];
        $filters = [
            'keyword' => '',
            'period_from' => '2026-04',
            'period_to' => '2026-04',
            'meter_type' => 'electric',
            'meter_code' => 'E-501',
            'unpaid_only' => '',
            'lite' => '',
            'sort_by' => 'payment_period',
            'sort_dir' => 'desc',
            'bom' => '',
        ];

        $html = (string) $this->invokePrivate($controller, 'reconciliationTemplate', [$rows, $filters, []]);

        self::assertStringContainsString('表计类型：电表', $html);
        self::assertStringContainsString('表计编号：E-501', $html);
    }

    public function testReconciliationExportCsvContainsMeterFilterMetadata(): void
    {
        $_GET = [
            'keyword' => 'A-101',
            'period_from' => '2026-01',
            'period_to' => '2026-04',
            'meter_type' => 'water',
            'meter_code' => 'W-009',
            'unpaid_only' => '1',
            'sort_by' => 'unpaid_amount',
            'sort_dir' => 'asc',
            'summary_ref' => 'REC-202604081230-ABC123',
        ];

        PaymentControllerTestDoubleState::$monthlyRows = [
            [
                'payment_period' => '2026-04',
                'bill_count' => 3,
                'receivable_amount' => 4500.00,
                'received_amount' => 3000.00,
                'paid_count' => 1,
            ],
        ];

        $controller = new PaymentController();
        $response = $controller->reconciliationExport();
        $content = (string) $response->getContent();

        self::assertStringContainsString('"schema_version","reconciliation_csv_v2"', $content);
        self::assertStringContainsString('"摘要编号","REC-202604081230-ABC123"', $content);
        self::assertStringContainsString('"表计类型","水表"', $content);
        self::assertStringContainsString('"表计编号","W-009"', $content);
        self::assertStringContainsString('"排序","未收差额（升序）"', $content);
        self::assertStringContainsString('账期,账单数,应收总额,实收总额,未收差额,已收账单数,收款率%', $content);
        self::assertStringContainsString('"2026-04","3","4500.00","3000.00","1500.00","1","33.33"', $content);
    }

}
