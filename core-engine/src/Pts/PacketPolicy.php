<?php

declare(strict_types=1);

namespace App\Pts;

use function in_array;

/**
 * Which jsonPTS packet types a caller may send. The collector uses READ_ONLY: it is physically unable to send a
 * command (docs/security-and-failure-handling.md §1).
 *
 * `PumpGetTotals` is deliberately NOT read-only-safe: it is asynchronous and occupies the pump's communication
 * channel (JSONPTS_ERROR_PUMP_BUSY_OTHER_REQUEST_EXECUTED), so background polling could collide with an authorize.
 */
final class PacketPolicy
{
    public const READ_ONLY = 'read_only';

    private const READ_ONLY_TYPES = [
        'PumpGetStatus',
        'GetConfigurationIdentifier',
        'GetPumpsConfiguration',
        'GetPumpNozzlesConfiguration',
        'GetFuelGradesConfiguration',
        'GetDateTime',
        'ReportGetPumpTransactions',
    ];

    public static function allows(string $policy, string $packetType): bool
    {
        return match ($policy) {
            self::READ_ONLY => in_array($packetType, self::READ_ONLY_TYPES, true),
            default => false,
        };
    }
}
