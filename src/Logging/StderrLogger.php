<?php

declare(strict_types=1);

namespace Daktela\CrmSync\Logging;

use Psr\Log\AbstractLogger;

final class StderrLogger extends AbstractLogger
{
    /** @var resource */
    private readonly mixed $stream;

    /**
     * @param resource|null $stream Output stream (defaults to STDERR)
     */
    public function __construct(mixed $stream = null)
    {
        $this->stream = $stream ?? STDERR;
    }

    public function log($level, string|\Stringable $message, array $context = []): void
    {
        $messageStr = (string) $message;

        // Interpolate {key} placeholders from context
        $replacements = [];
        foreach ($context as $key => $value) {
            $replacement = match (true) {
                is_string($value), is_int($value), is_float($value) => (string) $value,
                $value instanceof \Stringable => (string) $value,
                $value instanceof \DateTimeInterface => $value->format(\DateTimeInterface::ATOM),
                $value === null => '',
                default => '[' . get_debug_type($value) . ']',
            };
            $replacements['{' . $key . '}'] = $replacement;
        }

        if ($replacements !== []) {
            $messageStr = strtr($messageStr, $replacements);
        }

        $timestamp = date('Y-m-d H:i:s');

        fprintf($this->stream, "[%s] %s: %s\n", $timestamp, strtoupper((string) $level), $messageStr);
    }
}
