<?php

namespace sagacorp\queue\azure\service;

use yii\base\BaseObject;

/**
 * @property string $property
 *
 * partition key
 * enqueuedTimeUtc
 */
class Message extends BaseObject
{
    //region Public Properties
    /**
     * The body of the brokered message.
     */
    public ?string $body;
    /**
     * The properties of the broker.
     */
    public ?BrokerProperties $brokerProperties;
    /**
     * The content type of the brokered message.
     */
    public ?string $contentType;
    /**
     * The date of the brokered message.
     */
    public string $date;
    /**
     * The URI of the locked message. You can use this URI to unlock or delete the message.
     */
    public ?string $location;
    /**
     * The properties of the message that are customized.
     */
    public array $properties = [];
    //endregion Public Properties

    //region Getters/Setters
    /**
     * Gets the value of a custom property.
     *
     * @param string $propertyName The name of the property
     *
     * @return string|null
     */
    public function getProperty(string $propertyName): ?string
    {
        return $this->properties[strtolower($propertyName)] ?? null;
    }

    /**
     * Sets the value of a custom property.
     */
    public function setProperty(string $propertyName, mixed $propertyValue): void
    {
        $this->properties[strtolower($propertyName)] = $propertyValue;
    }
    //endregion Getters/Setters
}
