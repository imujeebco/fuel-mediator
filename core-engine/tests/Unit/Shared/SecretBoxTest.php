<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared;

use App\Shared\Crypto\SecretBox;
use Codeception\Test\Unit;
use RuntimeException;

use function base64_decode;
use function base64_encode;
use function PHPUnit\Framework\assertFalse;
use function PHPUnit\Framework\assertNotSame;
use function PHPUnit\Framework\assertNull;
use function PHPUnit\Framework\assertSame;
use function strlen;
use function substr;

final class SecretBoxTest extends Unit
{
    public function testRoundTripWithFreshNonceEachTime(): void
    {
        $box = new SecretBox(SecretBox::generateKey());
        $a = $box->encrypt('pts-pass');
        $b = $box->encrypt('pts-pass');

        assertNotSame($a, $b, 'random nonce per encryption');
        assertSame('pts-pass', $box->decrypt($a));
        assertSame('pts-pass', $box->decrypt($b));
    }

    public function testTamperedOrForeignValuesDoNotDecrypt(): void
    {
        $box = new SecretBox(SecretBox::generateKey());
        $stored = $box->encrypt('pts-pass');
        $raw = (string) base64_decode(substr($stored, 4), true);
        $raw[strlen($raw) - 1] = $raw[strlen($raw) - 1] === 'a' ? 'b' : 'a';

        assertNull($box->decrypt('sb1:' . base64_encode($raw)), 'tampered');
        assertNull((new SecretBox(SecretBox::generateKey()))->decrypt($stored), 'other key');
        assertNull($box->decrypt('plaintext'), 'not a stored secret');
    }

    public function testWithoutKeyNothingIsEncryptedOrDecrypted(): void
    {
        $box = new SecretBox('');

        assertFalse($box->isConfigured());
        assertNull($box->decrypt('sb1:AAAA'));
        $this->expectException(RuntimeException::class);
        $box->encrypt('x');
    }

    public function testInvalidKeyIsRejected(): void
    {
        $this->expectException(RuntimeException::class);
        new SecretBox(base64_encode('too-short'));
    }
}
