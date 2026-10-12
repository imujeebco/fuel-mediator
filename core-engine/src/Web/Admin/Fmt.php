<?php

declare(strict_types=1);

namespace App\Web\Admin;

use DateTimeImmutable;
use DateTimeZone;
use Yiisoft\Html\Html;

use function abs;
use function number_format;
use function sprintf;

/**
 * Formatting for the admin views. Database values are UTC strings; display is station local time.
 */
final class Fmt
{
    public function __construct(private readonly DateTimeZone $zone) {}

    public function time(mixed $utc, string $format = 'H:i:s'): string
    {
        if ($utc === null || $utc === '') {
            return '—';
        }

        return (new DateTimeImmutable((string) $utc . ' UTC'))->setTimezone($this->zone)->format($format);
    }

    /** "12 s ago", "4 min ago", "3 h ago". */
    public function age(mixed $utc): string
    {
        $seconds = $this->ageSeconds($utc);
        if ($seconds === null) {
            return 'never';
        }
        return match (true) {
            $seconds < 90 => $seconds . ' s ago',
            $seconds < 5400 => intdiv($seconds, 60) . ' min ago',
            default => intdiv($seconds, 3600) . ' h ago',
        };
    }

    public function ageSeconds(mixed $utc): ?int
    {
        if ($utc === null || $utc === '') {
            return null;
        }

        return max(0, (new DateTimeImmutable('now', new DateTimeZone('UTC')))->getTimestamp() - (new DateTimeImmutable((string) $utc . ' UTC'))->getTimestamp());
    }

    public static function litres(mixed $value, int $decimals = 1): string
    {
        return $value === null || $value === '' ? '—' : number_format((float) $value, $decimals) . ' L';
    }

    public static function sar(mixed $value): string
    {
        return $value === null || $value === '' ? '—' : 'SAR ' . number_format((float) $value, 2);
    }

    public static function num(mixed $value, int $decimals = 0): string
    {
        return $value === null || $value === '' ? '—' : number_format((float) $value, $decimals);
    }

    /** Signed difference with a sign and unit: "+225.4 L" / "−42.9 L". */
    public static function gap(mixed $value, int $decimals = 1): string
    {
        $v = (float) $value;
        $text = number_format(abs($v), $decimals) . ' L';

        return abs($v) < 0.05 ? '0.0 L' : ($v > 0 ? '+' : '−') . $text;
    }

    public static function pill(string $text, string $tone): string
    {
        return Html::tag('span', Html::encode($text), ['class' => "pill $tone"])->render();
    }

    /** Tone for a pumped − sold gap: within tolerance = ok, positive = not sold (bad), negative = oversold (warn). */
    public static function gapTone(mixed $gap, float $tolerance = 20.0): string
    {
        $v = (float) $gap;

        return abs($v) <= $tolerance ? 'ok' : ($v > 0 ? 'bad' : 'warn');
    }

    public static function e(mixed $value): string
    {
        return Html::encode($value === null ? '' : (string) $value);
    }

    public static function stateLabel(string $state): string
    {
        return match ($state) {
            'matched' => 'Paid',
            'unmatched' => 'No sale',
            'observed' => 'Waiting',
            'ambiguous' => 'Ambiguous',
            default => $state,
        };
    }

    public static function issueLabel(string $type): string
    {
        return match ($type) {
            'unmatched_fill' => 'Fill without sale',
            'sale_without_fill' => 'Sale without fill',
            'duplicate_candidate' => 'Possible duplicate',
            'unsold_volume' => 'Unsold volume',
            'oversold_volume' => 'Oversold volume',
            'unknown_command_outcome' => 'Pump command outcome unknown',
            'odoo_sync_failed' => 'Odoo sync failed',
            'payment_unverified' => 'Payment unverified',
            'meter_gap' => 'Meter gap',
            'meter_regression' => 'Meter went backwards',
            'session_mismatch' => 'Session mismatch',
            'collector_gap' => 'Collector gap',
            default => $type,
        };
    }

    public static function duration(int $seconds): string
    {
        return sprintf('%d:%02d', intdiv($seconds, 60), $seconds % 60);
    }
}
