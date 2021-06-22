<?php

namespace saga\queue\azure\service;

use yii\base\BaseObject;

/**
 * @property int              $contentType             =
 * @property BrokerProperties $messageProperties       ok
 * @property array            $properties              ok
 * @property string           $property                ok
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
    //endregion Public Properties

    //region Private Properties
    /**
     * The properties of the message that are customized.
     */
    private array $customProperties = [];
    //endregion Private Properties

    //region Getters/Setters
    /**
     * Gets the custom properties.
     *
     * @return array
     */
    public function getProperties(): array
    {
        if (null === $this->customProperties) {
            $this->customProperties = [];
        }

        return $this->customProperties;
    }

    /**
     * Gets the value of a custom property.
     *
     * @param string $propertyName The name of the property
     *
     * @return string|null
     */
    public function getProperty(string $propertyName): ?string
    {
        return $this->getProperties()[strtolower($propertyName)] ?? null;
    }

    /**
     * Sets the value of a custom property.
     */
    public function setProperty(string $propertyName, mixed $propertyValue): void
    {
        $this->customProperties[strtolower($propertyName)] = $propertyValue;
    }
    //endregion Getters/Setters
}
