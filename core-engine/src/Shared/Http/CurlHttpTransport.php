<?php

declare(strict_types=1);

namespace App\Shared\Http;

use function curl_close;
use function curl_errno;
use function curl_error;
use function curl_exec;
use function curl_getinfo;
use function curl_init;
use function curl_setopt_array;
use function hrtime;
use function in_array;
use function is_string;
use function sprintf;

/**
 * ext-curl transport. One request per call, no retries (see {@see HttpTransportInterface}).
 * Sends an explicit User-Agent: the Cloudflare edge in front of PTS controllers rejects some default agents (403).
 */
final class CurlHttpTransport implements HttpTransportInterface
{
    public const USER_AGENT = 'fuel-mediator/0.1';

    public function post(string $url, string $body, array $headers = [], ?HttpOptions $options = null): HttpResult
    {
        $options ??= new HttpOptions();

        $headerLines = ['Expect:'];
        foreach ($headers as $name => $value) {
            $headerLines[] = $name . ': ' . $value;
        }

        $handle = curl_init($url);
        if ($handle === false) {
            throw new TransportException('Unable to initialise cURL for ' . $url, isTimeout: false);
        }
        $curlOptions = [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => $headerLines,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_USERAGENT => self::USER_AGENT,
            CURLOPT_CONNECTTIMEOUT_MS => (int) ($options->connectTimeoutSeconds * 1000),
            CURLOPT_TIMEOUT_MS => (int) ($options->timeoutSeconds * 1000),
            CURLOPT_SSL_VERIFYPEER => $options->verifySsl,
            CURLOPT_SSL_VERIFYHOST => $options->verifySsl ? 2 : 0,
            CURLOPT_NOSIGNAL => true,
        ];
        if ($options->authMode === HttpOptions::AUTH_DIGEST || $options->authMode === HttpOptions::AUTH_BASIC) {
            $curlOptions[CURLOPT_HTTPAUTH] = $options->authMode === HttpOptions::AUTH_DIGEST ? CURLAUTH_DIGEST : CURLAUTH_BASIC;
            $curlOptions[CURLOPT_USERPWD] = $options->username . ':' . $options->password;
        }
        curl_setopt_array($handle, $curlOptions);

        $started = hrtime(true);
        $response = curl_exec($handle);
        $latencyMs = (int) ((hrtime(true) - $started) / 1_000_000);

        if (!is_string($response)) {
            $errno = curl_errno($handle);
            $error = curl_error($handle);
            curl_close($handle);
            throw new TransportException(
                sprintf('HTTP transport error %d: %s', $errno, $error),
                isTimeout: $errno === CURLE_OPERATION_TIMEDOUT,
                latencyMs: $latencyMs,
                requestMayHaveBeenSent: !in_array($errno, [CURLE_COULDNT_RESOLVE_HOST, CURLE_COULDNT_CONNECT, CURLE_UNSUPPORTED_PROTOCOL], true),
            );
        }

        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        curl_close($handle);

        return new HttpResult($status, $response, $latencyMs);
    }
}
