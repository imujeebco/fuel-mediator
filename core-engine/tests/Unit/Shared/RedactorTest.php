<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared;

use App\Shared\Log\JsonLineFormat;
use App\Shared\Log\Redactor;
use Codeception\Test\Unit;
use Yiisoft\Log\Message;

use function PHPUnit\Framework\assertSame;
use function PHPUnit\Framework\assertStringContainsString;
use function PHPUnit\Framework\assertStringNotContainsString;

final class RedactorTest extends Unit
{
    public function testRedactsSensitiveKeysAtAnyDepth(): void
    {
        $redacted = (new Redactor())->redact([
            'username' => 'admin',
            'password' => 'K@1212',
            'pts_connection' => ['direct_pts_url' => 'https://x/jsonPTS', 'password' => 'admin', 'username' => 'admin'],
            'employees' => [['id' => 1, 'name' => 'A', 'pin' => '1234'], ['id' => 2, 'mobile_pin' => 'hash']],
            'headers' => ['Authorization' => 'Digest abc', 'X-API-Key' => 'k'],
            'odoo_pass' => 'p',
            'access_token' => 't',
        ]);

        assertSame('admin', $redacted['username']);
        assertSame(Redactor::MASK, $redacted['password']);
        assertSame(Redactor::MASK, $redacted['pts_connection']['password']);
        assertSame('https://x/jsonPTS', $redacted['pts_connection']['direct_pts_url']);
        assertSame(Redactor::MASK, $redacted['employees'][0]['pin']);
        assertSame(Redactor::MASK, $redacted['employees'][1]['mobile_pin']);
        assertSame(Redactor::MASK, $redacted['headers']['Authorization']);
        assertSame(Redactor::MASK, $redacted['headers']['X-API-Key']);
        assertSame(Redactor::MASK, $redacted['odoo_pass']);
        assertSame(Redactor::MASK, $redacted['access_token']);
    }

    public function testKeepsUsefulKeysThatOnlyResembleSecrets(): void
    {
        $redacted = (new Redactor())->redact([
            'card_number_masked' => '4201 32** **** 5591',
            'mapping' => 'x',
            'shipping' => 'y',
            'idempotency_key' => 'abc',
            'passport_no' => 'keep',
        ]);

        assertSame('4201 32** **** 5591', $redacted['card_number_masked']);
        assertSame('x', $redacted['mapping']);
        assertSame('y', $redacted['shipping']);
        assertSame('abc', $redacted['idempotency_key']);
        assertSame('keep', $redacted['passport_no']);
    }

    public function testMasksFullCardNumbersButKeepsRrn(): void
    {
        $redactor = new Redactor();

        assertSame('************1111', $redactor->redact('4111111111111111'));
        assertSame('card ************1111 ok', $redactor->redact('card 4111111111111111 ok'));
        // 12-digit RRN must stay readable for reconciliation.
        assertSame('125402109352', $redactor->redact('125402109352'));
    }

    public function testRedactsPositionalOdooPasswords(): void
    {
        $redactor = new Redactor();

        $executeKw = $redactor->redactOdooParams([
            'service' => 'object',
            'method' => 'execute_kw',
            'args' => ['db', 25, 'K@1212', 'pts.backend.config', 'register_terminal_sale', [['uuid' => 'u']]],
        ]);
        $authenticate = $redactor->redactOdooParams([
            'service' => 'common',
            'method' => 'authenticate',
            'args' => ['db', 'user@example.com', 'K@1212', []],
        ]);

        assertSame(Redactor::MASK, $executeKw['args'][2]);
        assertSame('pts.backend.config', $executeKw['args'][3]);
        assertSame(Redactor::MASK, $authenticate['args'][2]);
        assertSame('user@example.com', $authenticate['args'][1]);
    }

    /** Test scenario 20: log output contains no secrets or sensitive payment data. */
    public function testLogLinesContainNoSecretsOrCardNumbers(): void
    {
        $line = (new JsonLineFormat())(
            new Message('info', 'Relayed sale for card 4111111111111111', [
                'payload' => ['uuid' => 'u-1', 'rrn' => '125402109352', 'password' => 'K@1212'],
                'pts' => ['username' => 'admin', 'password' => 'admin'],
            ]),
            [],
        );

        assertStringNotContainsString('K@1212', $line);
        assertStringNotContainsString('4111111111111111', $line);
        assertStringNotContainsString('"password":"admin"', $line);
        assertStringContainsString('125402109352', $line);
        assertStringContainsString('"level":"info"', $line);
    }
}
