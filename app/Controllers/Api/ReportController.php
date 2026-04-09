<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Core\HttpException;
use App\Core\Response;

class ReportController
{
    public function financialSummary(): Response
    {
        $this->ensureAuthenticated();

        $period = trim((string) ($_GET['period'] ?? date('Y-m')));
        if (!preg_match('/^\d{4}-\d{2}$/', $period)) {
            throw HttpException::badRequest('period 参数格式错误，应为 YYYY-MM');
        }

        $isAdmin = auth()->isAdmin();
        $user = auth()->user() ?? [];

        $where = ['rp.payment_period = ?'];
        $params = [$period];

        if (!$isAdmin) {
            $where[] = 'p.owner_id = ?';
            $params[] = (int) ($user['id'] ?? 0);
        }

        $whereSql = ' WHERE ' . implode(' AND ', $where);

        $summary = db()->fetch(
            'SELECT
                COUNT(1) AS bill_count,
                SUM(rp.amount_due) AS total_amount_due,
                SUM(COALESCE(rp.amount_paid, 0)) AS total_amount_paid,
                SUM(COALESCE(rp.late_fee, 0)) AS total_late_fee,
                SUM(CASE WHEN rp.payment_status = "paid" THEN 1 ELSE 0 END) AS paid_count,
                SUM(CASE WHEN rp.payment_status IN ("pending", "partial", "overdue") THEN 1 ELSE 0 END) AS unpaid_count
             FROM rent_payments rp
             INNER JOIN contracts c ON c.id = rp.contract_id
             INNER JOIN properties p ON p.id = c.property_id'
            . $whereSql,
            $params
        ) ?? [];

        $methodRows = db()->fetchAll(
            'SELECT
                COALESCE(NULLIF(rp.payment_method, ""), "unknown") AS payment_method,
                COUNT(1) AS bill_count,
                SUM(COALESCE(rp.amount_paid, 0)) AS total_amount_paid
             FROM rent_payments rp
             INNER JOIN contracts c ON c.id = rp.contract_id
             INNER JOIN properties p ON p.id = c.property_id'
            . $whereSql
            . ' GROUP BY payment_method ORDER BY total_amount_paid DESC',
            $params
        );

        return Response::json([
            'success' => true,
            'filters' => [
                'period' => $period,
                'scope' => $isAdmin ? 'all' : 'owner',
            ],
            'summary' => [
                'bill_count' => (int) ($summary['bill_count'] ?? 0),
                'paid_count' => (int) ($summary['paid_count'] ?? 0),
                'unpaid_count' => (int) ($summary['unpaid_count'] ?? 0),
                'total_amount_due' => round((float) ($summary['total_amount_due'] ?? 0), 2),
                'total_amount_paid' => round((float) ($summary['total_amount_paid'] ?? 0), 2),
                'total_late_fee' => round((float) ($summary['total_late_fee'] ?? 0), 2),
            ],
            'by_payment_method' => array_map(static function (array $row): array {
                return [
                    'payment_method' => (string) ($row['payment_method'] ?? 'unknown'),
                    'bill_count' => (int) ($row['bill_count'] ?? 0),
                    'total_amount_paid' => round((float) ($row['total_amount_paid'] ?? 0), 2),
                ];
            }, $methodRows),
        ]);
    }

    public function occupancySummary(): Response
    {
        $this->ensureAuthenticated();

        $city = trim((string) ($_GET['city'] ?? ''));
        $status = trim((string) ($_GET['status'] ?? ''));

        $isAdmin = auth()->isAdmin();
        $user = auth()->user() ?? [];

        $where = [];
        $params = [];

        if (!$isAdmin) {
            $where[] = 'p.owner_id = ?';
            $params[] = (int) ($user['id'] ?? 0);
        }

        if ($city !== '') {
            $where[] = 'p.city = ?';
            $params[] = $city;
        }

        if ($status !== '') {
            $where[] = 'p.property_status = ?';
            $params[] = $status;
        }

        $whereSql = '';
        if ($where !== []) {
            $whereSql = ' WHERE ' . implode(' AND ', $where);
        }

        $summary = db()->fetch(
            'SELECT
                COUNT(1) AS property_count,
                SUM(COALESCE(p.total_rooms, 0)) AS total_rooms,
                SUM(COALESCE(p.total_rooms, 0) - COALESCE(p.available_rooms, 0)) AS occupied_rooms,
                SUM(COALESCE(p.available_rooms, 0)) AS available_rooms
             FROM properties p'
            . $whereSql,
            $params
        ) ?? [];

        $cityRows = db()->fetchAll(
            'SELECT
                COALESCE(NULLIF(p.city, ""), "unknown") AS city,
                COUNT(1) AS property_count,
                SUM(COALESCE(p.total_rooms, 0)) AS total_rooms,
                SUM(COALESCE(p.total_rooms, 0) - COALESCE(p.available_rooms, 0)) AS occupied_rooms
             FROM properties p'
            . $whereSql
            . ' GROUP BY city ORDER BY property_count DESC',
            $params
        );

        $totalRooms = (float) ($summary['total_rooms'] ?? 0);
        $occupiedRooms = (float) ($summary['occupied_rooms'] ?? 0);
        $occupancyRate = $totalRooms > 0 ? round($occupiedRooms * 100 / $totalRooms, 2) : 0.0;

        return Response::json([
            'success' => true,
            'filters' => [
                'city' => $city,
                'status' => $status,
                'scope' => $isAdmin ? 'all' : 'owner',
            ],
            'summary' => [
                'property_count' => (int) ($summary['property_count'] ?? 0),
                'total_rooms' => (int) $totalRooms,
                'occupied_rooms' => (int) $occupiedRooms,
                'available_rooms' => (int) ($summary['available_rooms'] ?? 0),
                'occupancy_rate' => $occupancyRate,
            ],
            'by_city' => array_map(static function (array $row): array {
                $rowTotalRooms = (float) ($row['total_rooms'] ?? 0);
                $rowOccupiedRooms = (float) ($row['occupied_rooms'] ?? 0);
                $rowRate = $rowTotalRooms > 0 ? round($rowOccupiedRooms * 100 / $rowTotalRooms, 2) : 0.0;

                return [
                    'city' => (string) ($row['city'] ?? 'unknown'),
                    'property_count' => (int) ($row['property_count'] ?? 0),
                    'total_rooms' => (int) $rowTotalRooms,
                    'occupied_rooms' => (int) $rowOccupiedRooms,
                    'occupancy_rate' => $rowRate,
                ];
            }, $cityRows),
        ]);
    }

    private function ensureAuthenticated(): void
    {
        if (!auth()->check()) {
            throw HttpException::unauthorized('请先登录');
        }
    }
}
