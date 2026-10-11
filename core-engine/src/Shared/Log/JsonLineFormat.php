<?php

declare(strict_types=1);

namespace App\Shared\Log;

use Yiisoft\Log\Message;

use function json_encode;

/**
 * Log target format: one redacted JSON object per line (structured logging).
 */
final class JsonLineFormat
{
    /** Keys added by yiisoft/log itself; they add noise and may contain request data. */
    private const INTERNAL_CONTEXT_KEYS = ['time', 'trace', 'memory', 'category'];

    public function __construct(
        private readonly Redactor $redactor = new Redactor(),
    ) {}

    public function __invoke(Message $message, array $commonContext): string
    {
        /** @var array<string, mixed> $context */
        $context = $message->context();
        foreach (self::INTERNAL_CONTEXT_KEYS as $key) {
            unset($context[$key]);
        }

        $line = [
            'ts' => $message->time()->format('Y-m-d\TH:i:s.vP'),
            'level' => $message->level(),
            'category' => $message->category(),
            'message' => $this->redactor->redact($message->message()),
        ];
        if ($context !== []) {
            $line['context'] = $this->redactor->redact($context);
        }

        return json_encode(
            $line,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR,
        ) ?: '{"level":"error","message":"log encoding failed"}';
    }
}
