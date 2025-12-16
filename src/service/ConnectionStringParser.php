<?php

declare(strict_types=1);

namespace sagacorp\queue\azure\service;

use yii\base\InvalidArgumentException;

readonly class ConnectionStringParser
{
    public function __construct(
        private string $connectionString
    ) {}

    public function parseConnectionString(): array
    {
        $result = [];

        foreach (explode(';', trim($this->connectionString, ';')) as $pair) {
            [$key, $value] = explode('=', $pair, 2);
            $result[$key] = $value;
        }

        foreach (['Endpoint', 'SharedAccessKeyName', 'SharedAccessKey'] as $required) {
            if (!isset($result[$required])) {
                throw new InvalidArgumentException("Missing {$required}");
            }
        }

        $parsed = parse_url($result['Endpoint']);

        return [
            ...$result,
            'host' => $parsed['host'] ?? '',
        ];
    }
}
