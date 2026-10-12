<?php

declare(strict_types=1);

namespace App\Shared\Crypto;

use RuntimeException;
use SensitiveParameter;

use function base64_decode;
use function base64_encode;
use function random_bytes;
use function sodium_crypto_secretbox;
use function sodium_crypto_secretbox_keygen;
use function sodium_crypto_secretbox_open;
use function str_starts_with;
use function strlen;
use function substr;

/**
 * Authenticated encryption for secrets stored in the database (decision D23: controller passwords read from Odoo).
 *
 * libsodium secretbox (XSalsa20-Poly1305). The key is `MEDIATOR_SECRET_KEY` (32 bytes, base64) and exists only in the
 * server environment, so a database dump alone never reveals a password. Stored form: `sb1:` + base64(nonce ‖ box).
 */
final class SecretBox
{
    private const PREFIX = 'sb1:';

    private readonly ?string $key;

    public function __construct(#[SensitiveParameter] string $base64Key)
    {
        if ($base64Key === '') {
            $this->key = null;
            return;
        }
        $key = base64_decode($base64Key, true);
        if ($key === false || strlen($key) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
            throw new RuntimeException('MEDIATOR_SECRET_KEY must be 32 bytes, base64-encoded (yii secret:generate-key).');
        }
        $this->key = $key;
    }

    public static function generateKey(): string
    {
        return base64_encode(sodium_crypto_secretbox_keygen());
    }

    public function isConfigured(): bool
    {
        return $this->key !== null;
    }

    public function encrypt(#[SensitiveParameter] string $plaintext): string
    {
        $key = $this->key ?? throw new RuntimeException('MEDIATOR_SECRET_KEY is not set; secrets cannot be stored.');
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

        return self::PREFIX . base64_encode($nonce . sodium_crypto_secretbox($plaintext, $nonce, $key));
    }

    /** @return string|null Null when the key is missing, the value is not ours, or it was tampered with. */
    public function decrypt(string $stored): ?string
    {
        $key = $this->key;
        if ($key === null) {
            return null;
        }
        if (!str_starts_with($stored, self::PREFIX)) {
            return null;
        }
        $raw = base64_decode(substr($stored, strlen(self::PREFIX)), true);
        if ($raw === false || strlen($raw) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            return null;
        }
        $plain = sodium_crypto_secretbox_open(
            substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
            substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
            $key,
        );

        return $plain === false ? null : $plain;
    }
}
