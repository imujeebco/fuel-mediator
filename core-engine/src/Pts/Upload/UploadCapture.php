<?php

declare(strict_types=1);

namespace App\Pts\Upload;

use App\Shared\Clock;
use JsonException;
use Yiisoft\Db\Connection\ConnectionInterface;

use function array_slice;
use function array_values;
use function base64_decode;
use function count;
use function implode;
use function in_array;
use function is_array;
use function is_int;
use function is_string;
use function json_decode;
use function json_encode;
use function preg_match;
use function random_int;
use function reset;
use function str_contains;
use function stripos;
use function strstr;
use function strtolower;
use function substr;

/**
 * Decision D30, step 1: records what a controller pushes through its remote-server HTTP upload and acknowledges it.
 *
 * Nothing is derived from the data yet; the raw request is kept so the packet format, the controller's
 * authentication and the upload timing can be checked against a real capture first. Every well-formed packet is
 * acknowledged with "OK" (an unacknowledged record is re-sent by the controller and holds back every newer one).
 * Passwords never reach the database: only the authentication scheme and user name are kept.
 */
final class UploadCapture
{
    public const MAX_BODY_BYTES = 1_048_576;
    private const PRUNE_ONE_IN = 200;
    private const PRUNE_BATCH = 5000;
    private const SKIPPED_HEADERS = ['authorization', 'cookie', 'proxy-authorization'];

    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly Clock $clock,
        private readonly int $keepHours,
    ) {}

    /**
     * @param array<string, mixed> $controllerRow A controllers row.
     * @param array<array-key, array<array-key, string>> $headers Request headers as PSR-7 returns them.
     *
     * @return array{status: int, body: array<string, mixed>} The jsonPTS reply for the controller.
     */
    public function capture(array $controllerRow, string $kind, string $body, array $headers, ?string $remoteAddr): array
    {
        $parsed = self::parse($body);
        [$authKind, $authUser] = self::authentication(self::header($headers, 'authorization'));

        $ptsId = self::header($headers, 'x-pts-id');
        if ($ptsId === null && is_string($parsed['ptsId'])) {
            $ptsId = $parsed['ptsId'];
        }

        $this->db->createCommand()->insert('pts_uploads', [
            'controller_id' => (int) $controllerRow['id'],
            'station_id' => (int) $controllerRow['station_id'],
            'upload_kind' => $kind,
            'packet_types' => $parsed['types'] === [] ? null : substr(implode(',', $parsed['types']), 0, 255),
            'packet_count' => count($parsed['acks']),
            'pts_id' => $ptsId === null ? null : substr($ptsId, 0, 64),
            'auth_kind' => $authKind,
            'auth_user' => $authUser === null ? null : substr($authUser, 0, 64),
            'remote_addr' => $remoteAddr === null ? null : substr($remoteAddr, 0, 64),
            'headers' => json_encode(self::keptHeaders($headers), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            'body' => $body,
            'parse_error' => $parsed['error'],
            'received_at' => $this->clock->nowForDb(),
        ])->execute();

        if (random_int(1, self::PRUNE_ONE_IN) === 1) {
            $this->prune();
        }

        if ($parsed['error'] !== null) {
            return ['status' => 400, 'body' => ['Protocol' => 'jsonPTS', 'Error' => $parsed['error']]];
        }

        return ['status' => 200, 'body' => ['Protocol' => 'jsonPTS', 'Packets' => $parsed['acks']]];
    }

    /** Deletes captures older than the retention window, a bounded batch at a time. */
    public function prune(): int
    {
        $cutoff = $this->clock->now()->modify('-' . $this->keepHours . ' hours')->format('Y-m-d H:i:s.v');

        return $this->db->createCommand(
            'DELETE FROM pts_uploads WHERE received_at < :cutoff ORDER BY received_at LIMIT ' . self::PRUNE_BATCH,
            [':cutoff' => $cutoff],
        )->execute();
    }

    /**
     * @return array{acks: list<array<string, mixed>>, types: list<string>, ptsId: mixed, error: string|null}
     */
    private static function parse(string $body): array
    {
        try {
            /** @var mixed $data */
            $data = json_decode($body, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return ['acks' => [], 'types' => [], 'ptsId' => null, 'error' => 'Malformed JSON'];
        }
        if (!is_array($data) || !is_array($data['Packets'] ?? null)) {
            return ['acks' => [], 'types' => [], 'ptsId' => null, 'error' => 'Request must be a jsonPTS object with a Packets array'];
        }

        $acks = [];
        $types = [];
        /** @var mixed $packet */
        foreach ($data['Packets'] as $packet) {
            $id = is_array($packet) ? ($packet['Id'] ?? null) : null;
            $type = is_array($packet) ? ($packet['Type'] ?? null) : null;
            $ack = ['Id' => is_int($id) ? $id : 0];
            if (is_string($type)) {
                $ack['Type'] = $type;
                $types[$type] = $type;
            }
            $ack['Message'] = 'OK';
            $acks[] = $ack;
        }

        return ['acks' => $acks, 'types' => array_values($types), 'ptsId' => $data['PtsId'] ?? null, 'error' => null];
    }

    /**
     * @return array{0: string, 1: string|null} Scheme ("none", "basic", "digest" or "other") and user name.
     */
    private static function authentication(?string $authorization): array
    {
        if ($authorization === null || $authorization === '') {
            return ['none', null];
        }
        if (stripos($authorization, 'Basic ') === 0) {
            $decoded = base64_decode(substr($authorization, 6), true);
            if ($decoded === false) {
                return ['basic', null];
            }

            return ['basic', str_contains($decoded, ':') ? (string) strstr($decoded, ':', true) : $decoded];
        }
        if (stripos($authorization, 'Digest ') === 0) {
            return ['digest', preg_match('/username="([^"]*)"/i', $authorization, $m) === 1 ? $m[1] : null];
        }

        return ['other', null];
    }

    /**
     * @param array<array-key, array<array-key, string>> $headers
     */
    private static function header(array $headers, string $name): ?string
    {
        foreach ($headers as $key => $values) {
            if (strtolower((string) $key) === $name && $values !== []) {
                return (string) reset($values);
            }
        }

        return null;
    }

    /**
     * @param array<array-key, array<array-key, string>> $headers
     *
     * @return array<string, string>
     */
    private static function keptHeaders(array $headers): array
    {
        $kept = [];
        foreach (array_slice($headers, 0, 50, true) as $key => $values) {
            $name = strtolower((string) $key);
            if (!in_array($name, self::SKIPPED_HEADERS, true)) {
                $kept[$name] = substr(implode(', ', $values), 0, 512);
            }
        }

        return $kept;
    }
}
