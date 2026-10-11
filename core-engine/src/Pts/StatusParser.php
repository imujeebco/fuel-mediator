<?php

declare(strict_types=1);

namespace App\Pts;

use function is_array;
use function is_float;
use function is_int;
use function is_numeric;
use function is_string;
use function number_format;

/**
 * Parses `PumpGetStatus` reply packets (shapes captured from a PTS-2 controller, Station 11, 2026-10-11).
 *
 * Decimal values are returned as normalized strings (3 decimals for volume/meters, 2 for money) so they are stored
 * exactly in DECIMAL columns and never pass through float arithmetic afterwards.
 */
final class StatusParser
{
    /**
     * @param array<string, mixed>|null $packet
     */
    public static function parse(int $requestedPump, ?array $packet): PumpStatus
    {
        if ($packet === null) {
            return new PumpStatus($requestedPump, PumpStatus::UNKNOWN, '', errorMessage: 'No reply packet');
        }

        $type = self::str($packet['Type'] ?? null) ?? '';
        /** @var array<string, mixed> $data */
        $data = is_array($packet['Data'] ?? null) ? $packet['Data'] : [];
        $pump = self::int($data['Pump'] ?? null) ?? $requestedPump;

        if (($packet['Error'] ?? false) === true || $type === '') {
            return new PumpStatus(
                $pump,
                PumpStatus::ERROR,
                $type,
                errorMessage: self::str($packet['Message'] ?? null) ?? 'Error packet',
            );
        }

        $state = match ($type) {
            'PumpIdleStatus' => PumpStatus::IDLE,
            'PumpFillingStatus' => PumpStatus::FILLING,
            'PumpEndOfTransactionStatus' => PumpStatus::END_OF_TRANSACTION,
            'PumpOfflineStatus' => PumpStatus::OFFLINE,
            default => PumpStatus::UNKNOWN,
        };

        if ($state === PumpStatus::FILLING) {
            return new PumpStatus(
                pump: $pump,
                state: $state,
                packetType: $type,
                nozzleUp: self::int($data['Nozzle'] ?? null),
                currentTransaction: self::int($data['Transaction'] ?? null),
                currentVolume: self::decimal($data['Volume'] ?? null, 3),
                currentAmount: self::decimal($data['Amount'] ?? null, 2),
            );
        }

        return new PumpStatus(
            pump: $pump,
            state: $state,
            packetType: $type,
            nozzleUp: self::int($data['NozzleUp'] ?? null),
            lastTransaction: self::int($data['LastTransaction'] ?? null),
            lastNozzle: self::int($data['LastNozzle'] ?? null),
            lastVolume: self::decimal($data['LastVolume'] ?? null, 3),
            lastAmount: self::decimal($data['LastAmount'] ?? null, 2),
            lastPrice: self::decimal($data['LastPrice'] ?? null, 3),
            lastTotalVolume: self::positiveDecimal($data['LastTotalVolume'] ?? null, 3),
            lastTotalAmount: self::positiveDecimal($data['LastTotalAmount'] ?? null, 2),
            lastStartedAtLocal: self::str($data['LastDateTimeStart'] ?? null),
            lastEndedAtLocal: self::str($data['LastDateTime'] ?? null),
            lastUser: self::str($data['LastUser'] ?? null),
        );
    }

    private static function str(mixed $value): ?string
    {
        return is_string($value) ? $value : null;
    }

    private static function int(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && ctype_digit($value)) {
            return (int) $value;
        }

        return null;
    }

    private static function decimal(mixed $value, int $scale): ?string
    {
        if (!is_int($value) && !is_float($value) && !(is_string($value) && is_numeric($value))) {
            return null;
        }

        return number_format((float) $value, $scale, '.', '');
    }

    /** Meter totals of 0 are treated as "not reported" (a real totalizer never reads exactly zero after use). */
    private static function positiveDecimal(mixed $value, int $scale): ?string
    {
        $decimal = self::decimal($value, $scale);

        return $decimal === null || (float) $decimal <= 0.0 ? null : $decimal;
    }
}
