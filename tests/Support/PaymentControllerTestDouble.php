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
    public static bool $isLandlord = false;
    public static bool $sessionTokenValid = true;

    /** @var array<string, mixed> */
    public static array $user = ['id' => 1];

    /** @var array<string, mixed> */
    public static array $flashBag = [];

    /** @var array<int, array<string, mixed>> */
    public static array $monthlyRows = [];

    /** @var array<int, array<string, mixed>> */
    public static array $paymentRows = [];

    /** @var array<int, array<int, array<string, mixed>>> */
    public static array $meterRowsByPaymentId = [];

    /** @var null|callable(string, array): (array<string, mixed>|null) */
    public static $dbFetchHandler = null;

    /** @var null|callable(string, array): array<int, array<string, mixed>> */
    public static $dbFetchAllHandler = null;

    /** @var null|callable(string, array): mixed */
    public static $dbFetchColumnHandler = null;

    /** @var null|callable(string, array<string, mixed>): int */
    public static $dbInsertHandler = null;

    /** @var null|callable(string, array<string, mixed>, array<string, mixed>): int */
    public static $dbUpdateHandler = null;

    /** @var array<int, array{table: string, data: array<string, mixed>, where: array<string, mixed>}> */
    public static array $dbUpdateCalls = [];

    public static function reset(): void
    {
        self::$authCheck = true;
        self::$isAdmin = true;
        self::$isLandlord = false;
        self::$sessionTokenValid = true;
        self::$user = ['id' => 1];
        self::$flashBag = [];
        self::$monthlyRows = [];
        self::$paymentRows = [];
        self::$meterRowsByPaymentId = [];
        self::$dbFetchHandler = null;
        self::$dbFetchAllHandler = null;
        self::$dbFetchColumnHandler = null;
        self::$dbInsertHandler = null;
        self::$dbUpdateHandler = null;
        self::$dbUpdateCalls = [];
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

            public function isLandlord(): bool
            {
                return PaymentControllerTestDoubleState::$isLandlord;
            }

            public function id(): int
            {
                return (int) (PaymentControllerTestDoubleState::$user['id'] ?? 0);
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
                if (is_callable(PaymentControllerTestDoubleState::$dbFetchAllHandler)) {
                    $rows = call_user_func(PaymentControllerTestDoubleState::$dbFetchAllHandler, $sql, $params);
                    return is_array($rows) ? $rows : [];
                }

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

            public function fetch(string $sql, array $params = []): ?array
            {
                if (is_callable(PaymentControllerTestDoubleState::$dbFetchHandler)) {
                    $row = call_user_func(PaymentControllerTestDoubleState::$dbFetchHandler, $sql, $params);
                    return is_array($row) ? $row : null;
                }

                return null;
            }

            public function fetchColumn(string $sql, array $params = [])
            {
                if (is_callable(PaymentControllerTestDoubleState::$dbFetchColumnHandler)) {
                    return call_user_func(PaymentControllerTestDoubleState::$dbFetchColumnHandler, $sql, $params);
                }

                return null;
            }

            public function insert(string $table, array $data): int
            {
                if (is_callable(PaymentControllerTestDoubleState::$dbInsertHandler)) {
                    return (int) call_user_func(PaymentControllerTestDoubleState::$dbInsertHandler, $table, $data);
                }

                return 1;
            }

            public function update(string $table, array $data, array $where): int
            {
                PaymentControllerTestDoubleState::$dbUpdateCalls[] = [
                    'table' => $table,
                    'data' => $data,
                    'where' => $where,
                ];

                if (is_callable(PaymentControllerTestDoubleState::$dbUpdateHandler)) {
                    return (int) call_user_func(PaymentControllerTestDoubleState::$dbUpdateHandler, $table, $data, $where);
                }

                return 1;
            }
        };
    }
}

if (!function_exists(__NAMESPACE__ . '\\session')) {
    function session(?string $key = null, $default = null)
    {
        return new class {
            public function validateToken(?string $token): bool
            {
                return PaymentControllerTestDoubleState::$sessionTokenValid;
            }

            public function flash(string $key, $value): void
            {
                PaymentControllerTestDoubleState::$flashBag[$key] = $value;
            }
        };
    }
}

if (!function_exists(__NAMESPACE__ . '\\flash')) {
    function flash(string $key, $value): void
    {
        PaymentControllerTestDoubleState::$flashBag[$key] = $value;
    }
}

if (!function_exists(__NAMESPACE__ . '\\has_flash')) {
    function has_flash(string $key): bool
    {
        return array_key_exists($key, PaymentControllerTestDoubleState::$flashBag);
    }
}

if (!function_exists(__NAMESPACE__ . '\\get_flash')) {
    function get_flash(string $key, $default = null)
    {
        if (!array_key_exists($key, PaymentControllerTestDoubleState::$flashBag)) {
            return $default;
        }

        $value = PaymentControllerTestDoubleState::$flashBag[$key];
        unset(PaymentControllerTestDoubleState::$flashBag[$key]);
        return $value;
    }
}
