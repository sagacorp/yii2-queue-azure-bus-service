<?php

namespace sagacorp\queue\azure\service;

use Carbon\Carbon;

class Message
{
    // region Initialization
    public function __construct(
        public ?string $body,
        public ?string $contentType = 'application/vnd.microsoft.servicebus.yml',
        public ?Carbon $date = null,
        public ?string $location = null,
        public ?BrokerProperties $brokerProperties = null,
        public array $properties = [],
    ) {
    }
    // endregion Initialization

    // region Getters/Setters
    public function getProperty(string $propertyName): mixed
    {
        return $this->properties[strtolower($propertyName)] ?? null;
    }

    public function setProperty(string $propertyName, mixed $propertyValue): void
    {
        $this->properties[strtolower($propertyName)] = $propertyValue;
    }
    // endregion Getters/Setters
}
