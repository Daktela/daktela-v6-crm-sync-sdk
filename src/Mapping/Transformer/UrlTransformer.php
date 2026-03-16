<?php

declare(strict_types=1);

namespace Daktela\CrmSync\Mapping\Transformer;

final class UrlTransformer implements ValueTransformerInterface
{
    public function getName(): string
    {
        return 'url';
    }

    /** @param array<string, mixed> $params */
    public function transform(mixed $value, array $params = []): mixed
    {
        if ($value === null || $value === '') {
            return $value;
        }

        $template = (string) ($params['template'] ?? '{value}');

        return str_replace('{value}', (string) $value, $template);
    }
}
