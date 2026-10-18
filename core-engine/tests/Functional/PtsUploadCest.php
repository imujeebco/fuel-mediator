<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Tests\Support\FunctionalTester;
use App\Tests\Support\PtsFixture;
use App\Tests\Support\TestDatabase;
use HttpSoft\Message\ServerRequest;
use HttpSoft\Message\StreamFactory;
use Yiisoft\Db\Query\Query;

use function base64_encode;
use function json_decode;
use function PHPUnit\Framework\assertArrayNotHasKey;
use function PHPUnit\Framework\assertSame;
use function PHPUnit\Framework\assertStringNotContainsString;

/**
 * Controller remote-server HTTP upload, step 1 of decision D30: record raw, acknowledge each packet.
 */
final class PtsUploadCest
{
    private const TRANSACTION = '{"Protocol":"jsonPTS","PtsId":"0027003A3438510935383135","Packets":['
        . '{"Id":7,"Type":"UploadPumpTransaction","Data":{"Pump":3,"Nozzle":1,"Transaction":931,"Volume":8.58,"Amount":20}},'
        . '{"Id":8,"Type":"UploadPumpTransaction","Data":{"Pump":4,"Nozzle":2,"Transaction":12,"Volume":4.29,"Amount":10}}]}';

    public function _before(): void
    {
        $db = TestDatabase::connection();
        TestDatabase::truncate($db);
        PtsFixture::seedController($db, 1, 2, [1]);
    }

    public function acknowledgesEveryPacketAndKeepsTheRawRequest(FunctionalTester $tester): void
    {
        $request = $this->post('/stations/2/upload/transactions', self::TRANSACTION)
            ->withHeader('Authorization', 'Basic ' . base64_encode('0027003A3438510935383135:s3cret'))
            ->withHeader('X-Pts-Signature', 'abc');

        $response = $tester->sendRequest($request);

        assertSame(200, $response->getStatusCode());
        assertSame(
            '{"Protocol":"jsonPTS","Packets":[{"Id":7,"Type":"UploadPumpTransaction","Message":"OK"},{"Id":8,"Type":"UploadPumpTransaction","Message":"OK"}]}',
            $response->getBody()->getContents(),
        );

        $row = (new Query(TestDatabase::connection()))->from('pts_uploads')->one();
        assertSame('transactions', $row['upload_kind']);
        assertSame('UploadPumpTransaction', $row['packet_types']);
        assertSame(2, (int) $row['packet_count']);
        assertSame('0027003A3438510935383135', $row['pts_id']);
        assertSame('basic', $row['auth_kind']);
        assertSame('0027003A3438510935383135', $row['auth_user']);
        assertSame(self::TRANSACTION, $row['body']);
        $headers = json_decode((string) $row['headers'], true);
        assertArrayNotHasKey('authorization', $headers);
        assertSame('abc', $headers['x-pts-signature']);
        assertStringNotContainsString('s3cret', (string) $row['headers']);
    }

    public function malformedBodyIsKeptButRefused(FunctionalTester $tester): void
    {
        $response = $tester->sendRequest($this->post('/stations/2/upload/status', 'not json'));

        assertSame(400, $response->getStatusCode());
        $row = (new Query(TestDatabase::connection()))->from('pts_uploads')->one();
        assertSame('Malformed JSON', $row['parse_error']);
        assertSame('none', $row['auth_kind']);
    }

    public function unknownControllerIs404AndNothingIsKept(FunctionalTester $tester): void
    {
        $response = $tester->sendRequest($this->post('/stations/999/upload/transactions', self::TRANSACTION));

        assertSame(404, $response->getStatusCode());
        assertSame(0, (int) (new Query(TestDatabase::connection()))->from('pts_uploads')->count());
    }

    private function post(string $uri, string $body): ServerRequest
    {
        return (new ServerRequest(method: 'POST', uri: $uri, headers: ['Content-Type' => 'application/json']))
            ->withBody((new StreamFactory())->createStream($body));
    }
}
