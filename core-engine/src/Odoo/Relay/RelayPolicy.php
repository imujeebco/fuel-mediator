<?php

declare(strict_types=1);

namespace App\Odoo\Relay;

use function in_array;

/**
 * Allow-list for the Odoo-compatible relay (docs/api-compatibility-matrix.md §2), derived from FuelPay's client
 * (docs/discovery/fuelpay-odoo-contract.md). Anything not listed is denied, so the relay is never an open proxy.
 */
final class RelayPolicy
{
    public const PASS = 'pass';
    public const JOURNAL = 'journal';
    public const REWRITE = 'rewrite';
    /** Forwarded; Odoo's answer may be adjusted for fills already paid by another sale (D21, SettledFillFilter). */
    public const FILTER = 'filter';

    /** @var array<string, array<string, string>> model => method => mode */
    private const OBJECT_METHODS = [
        'pos.config' => [
            'pts_api_bootstrap_proxy' => self::PASS, 'mobile_get_price_version' => self::PASS,
            'mobile_get_paid_fills' => self::FILTER, 'mobile_get_unpaid_fills' => self::FILTER,
            'mobile_is_fill_paid' => self::FILTER, 'mobile_list_employees' => self::PASS, 'mobile_fuel_tanks' => self::PASS,
        ],
        'pts.backend.config' => [
            'register_terminal_sale' => self::JOURNAL, 'register_terminal_sales_bulk' => self::JOURNAL,
            'mobile_retry_failed_pos_syncs' => self::PASS,
            'request_mobile_pump_permit' => self::JOURNAL, 'release_mobile_pump_permit' => self::JOURNAL,
            'extend_mobile_pump_permit' => self::JOURNAL, 'mobile_claim_pump_auth' => self::JOURNAL,
            'mobile_peek_pump_auth' => self::PASS, 'register_order_meter_event' => self::JOURNAL,
            'mobile_list_stations' => self::PASS, 'mobile_get_pumps' => self::REWRITE, 'mobile_get_fuel_price' => self::PASS,
        ],
        'pts.terminal.sale' => ['mobile_terminal_order_high_water' => self::PASS],
        'pos.order' => ['mobile_request_invoice' => self::PASS, 'mobile_get_receipt_qr' => self::PASS],
        'pts.mobile.shift' => [
            'mobile_get_shift_sales' => self::PASS, 'mobile_get_pos_session_summary' => self::PASS,
            'mobile_count_session_draft_orders' => self::PASS, 'mobile_get_active_shift' => self::PASS,
            'mobile_open_shift' => self::JOURNAL, 'mobile_close_shift' => self::JOURNAL,
            'mobile_calculate_auto_opening' => self::PASS, 'mobile_get_previous_closing_meters' => self::PASS,
            'mobile_sync_session_counters' => self::JOURNAL, 'mobile_analyze_session_meters' => self::PASS,
            'mobile_calculate_expected_closing' => self::PASS,
        ],
        'pts.nozzle.reconciliation' => ['sync_from_mobile' => self::JOURNAL],
        'pts.mobile.terminal' => ['mobile_register_terminal' => self::JOURNAL],
        'pts.tank.delivery' => ['mobile_record_delivery' => self::PASS],
        'pts.petrol.tank' => ['mobile_sync_tank_levels' => self::PASS],
        'product.template' => ['search_read' => self::PASS],
        'res.company' => ['search_read' => self::PASS],
        'res.partner' => ['search_read' => self::PASS, 'create' => self::PASS, 'write' => self::PASS],
        'whatsapp.template' => ['search_read' => self::PASS],
        'mail.message' => ['create' => self::PASS],
        'whatsapp.message' => ['create' => self::PASS, 'action_send_now' => self::PASS],
    ];

    /** Methods whose device read timeout is 60 s instead of 15 s. */
    private const LONG_METHODS = ['mobile_open_shift', 'mobile_close_shift'];

    /** @return string|null Mode, or null when denied. */
    public static function mode(string $service, string $method, ?string $model = null, ?string $modelMethod = null): ?string
    {
        return match ($service) {
            'db' => $method === 'list' ? self::PASS : null,
            'common' => in_array($method, ['authenticate', 'version'], true) ? self::PASS : null,
            'object' => $method === 'execute_kw' && $model !== null && $modelMethod !== null
                ? (self::OBJECT_METHODS[$model][$modelMethod] ?? null)
                : null,
            default => null,
        };
    }

    /** Upstream timeout, kept below the device's own read timeout so the device always gets an answer. */
    public static function timeoutSeconds(?string $modelMethod): float
    {
        return $modelMethod !== null && in_array($modelMethod, self::LONG_METHODS, true) ? 55.0 : 13.0;
    }
}
