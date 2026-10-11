<?php

declare(strict_types=1);

namespace App\Tests\Support\Fake;

use App\Shared\Http\HttpOptions;
use App\Shared\Http\HttpResult;
use App\Shared\Http\HttpTransportInterface;
use App\Shared\Http\TransportException;
use Closure;

/**
 * Scripted transport: each call is answered by the next queued responder and recorded for assertions.
 */
final class FakeHttpTransport implements HttpTransportInterface
{
    /** @var list<array{url: string, body: string, headers: array<string, string>, options: ?HttpOptions}> */
    public array $requests = [];

    /** @var list<HttpResult|TransportException|Closure(string): HttpResult> */
    private array $queue = [];

    public function queue(HttpResult|TransportException|Closure $response): self
    {
        $this->queue[] = $response;
        return $this;
    }

    public function queueJson(mixed $data, int $status = 200): self
    {
        return $this->queue(new HttpResult($status, json_encode($data, JSON_THROW_ON_ERROR), 5));
    }

    public function post(string $url, string $body, array $headers = [], ?HttpOptions $options = null): HttpResult
    {
        $this->requests[] = ['url' => $url, 'body' => $body, 'headers' => $headers, 'options' => $options];
        $next = array_shift($this->queue);
        if ($next === null) {
            throw new \LogicException('FakeHttpTransport: no response queued for ' . $url);
        }
        if ($next instanceof TransportException) {
            throw $next;
        }

        return $next instanceof Closure ? $next($body) : $next;
    }

    /** @return array<string, mixed> */
    public function decodedRequest(int $index): array
    {
        /** @var array<string, mixed> */
        return json_decode($this->requests[$index]['body'], true, 512, JSON_THROW_ON_ERROR);
    }
}
