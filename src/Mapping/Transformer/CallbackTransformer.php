<?php

declare(strict_types=1);

namespace Daktela\CrmSync\Mapping\Transformer;

final class CallbackTransformer implements ValueTransformerInterface
{
    /** @var array<string, \Closure> */
    private array $callbacks = [];

    public function getName(): string
    {
        return 'callback';
    }

    public function registerCallback(string $name, \Closure $callback): void
    {
        $this->callbacks[$name] = $callback;
    }

    /** @param array<string, mixed> $params */
    public function transform(mixed $value, array $params = []): mixed
    {
        $callbackName = (string) ($params['name'] ?? '');

        if ($callbackName === '' || !isset($this->callbacks[$callbackName])) {
            return $value;
        }

        return ($this->callbacks[$callbackName])($value, $params);
    }
}
