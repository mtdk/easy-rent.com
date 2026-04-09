<?php

declare(strict_types=1);

namespace App\Controllers;

/**
 * Shared state for PaymentController test doubles.
 */
final class PaymentControllerTestDoubleState
{
    public static bool $authCheck = true;
    public static bool $isAdmin = true;

    /** @var array<string, mixed> */
    public static array $user = ['id' => 1];

    /** @var array<int, array<string, mixed>> */
    public static array $monthlyRows = [];

    /** @var array<int, array<string, mixed>> */
    public static array $paymentRows = [];

    /** @var array<int, array<int, array<string, mixed>>> */
    public static array $meterRowsByPaymentId = [];

    public static function reset(): void
    {
        self::$authCheck = true;
        self::$isAdmin = true;
        self::$user = ['id' => 1];
        self::$monthlyRows = [];
        self::$paymentRows = [];
        self::$meterRowsByPaymentId = [];
    }
}

if (!function_exists(__NAMESPACE__ . '\\auth')) {
    function auth(): object
    {
        return new class {
            public function check(): bool
            {
                return PaymentControllerTestDoubleState::$authCheck;
            }

            /** @return array<string, mixed> */
            public function user(): array
            {
                return PaymentControllerTestDoubleState::$user;
            }

            public function isAdmin(): bool
            {
                return PaymentControllerTestDoubleState::$isAdmin;
            }
        };
    }
}

if (!function_exists(__NAMESPACE__ . '\\db')) {
    function db(): object
    {
        return new class {
            /** @return array<int, array<string, mixed>> */
            public function fetchAll(string $sql, array $params = []): array
            {
                if (str_contains($sql, 'FROM rent_payments rp') && str_contains($sql, 'GROUP BY rp.payment_period')) {
                    return PaymentControllerTestDoubleState::$monthlyRows;
                }

                if (str_contains($sql, 'FROM rent_payments rp')) {
                    return PaymentControllerTestDoubleState::$paymentRows;
                }

                if (str_contains($sql, 'FROM rent_payment_meter_details')) {
                    $paymentId = (int) ($params[0] ?? 0);
                    return PaymentControllerTestDoubleState::$meterRowsByPaymentId[$paymentId] ?? [];
                }

                return [];
            }
        };
    }
}
