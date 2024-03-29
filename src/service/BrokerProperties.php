<?php

namespace sagacorp\queue\azure\service;

use Carbon\Carbon;
use yii\base\Model;

/**
 * Class MessageProperties
 *
 * @package common\services\azure
 */
class BrokerProperties extends Model
{
    // region Public Properties
    /**
     * The correlation ID.
     */
    public ?string $correlationId = null;
    /**
     * The delivery count.
     */
    public int $deliveryCount = 1;
    /**
     * The label.
     */
    public ?string $label = null;
    /**
     * The lock token.
     */
    public ?string $lockToken = null;
    /**
     * The message Id.
     */
    public ?string $messageId = null;
    /**
     * The partitioned entity.
     */
    public ?string $partitionKey = null;
    /**
     * The reply to.
     */
    public ?string $replyTo = null;
    /**
     * The reply to session ID.
     */
    public ?string $replyToSessionId = null;
    /**
     * The sequence number.
     */
    public ?string $sequenceNumber = null;
    /**
     * The session ID.
     */
    public ?string $sessionId = null;
    /**
     * The state of the message.
     */
    public ?string $state = null;
    /**
     * The time to live.
     */
    public float $timeToLive = 1;
    /**
     * The to.
     */
    public ?string $to = null;
    // endregion Public Properties

    // region Private Properties
    /**
     * The enqueued time.
     */
    private ?Carbon $enqueuedTimeUtc = null;
    /**
     * The locked until time.
     */
    private ?Carbon $lockedUntilUtc = null;
    /**
     * The scheduled enqueue time.
     */
    private ?Carbon $scheduledEnqueueTimeUtc = null;
    // endregion Private Properties

    // region Initialization
    public function init(): void
    {
        parent::init();

        $this->messageId ??= uniqid('', true);
    }

    /**
     * Gets a string representing the settable broker properties
     *
     * @throws \JsonException
     * @return string
     */
    public function __toString()
    {
        $values = [];

        $settableProperties = [
            'CorrelationId' => 'correlationId',
            'SessionId' => 'sessionId',
            'MessageId' => 'messageId',
            'Label' => 'label',
            'ReplyTo' => 'replyTo',
            'TimeToLive' => 'timeToLive',
            'To' => 'to',
            'ScheduledEnqueueTimeUtc' => 'scheduledEnqueueTimeUtc',
            'ReplyToSessionId' => 'replyToSessionId',
            'PartitionKey' => 'partitionKey',
        ];

        foreach ($settableProperties as $key => $value) {
            if (null !== $this->$value) {
                $values[$key] = $this->$value instanceof Carbon ? $this->carbonToAzureDate($this->$value) : $this->$value;
            }
        }

        return (string) \json_encode($values, JSON_THROW_ON_ERROR);
    }
    // endregion Initialization

    // region Getters/Setters
    public function getEnqueuedTimeUtc(): ?Carbon
    {
        return $this->enqueuedTimeUtc;
    }

    public function getLockedUntilUtc(): ?Carbon
    {
        return $this->lockedUntilUtc;
    }

    public function getScheduledEnqueueTimeUtc(): ?Carbon
    {
        return $this->scheduledEnqueueTimeUtc;
    }

    public function setDelay(int $value): void
    {
        $this->setScheduledEnqueueTimeUtc(Carbon::now()->addSeconds($value)->setTimezone('UTC'));
    }

    public function setEnqueuedTimeUtc(Carbon|string $enqueuedTimeUtc): void
    {
        if (!$enqueuedTimeUtc instanceof Carbon) {
            $enqueuedTimeUtc = $this->azureDateToCarbon($enqueuedTimeUtc);
        }

        $this->enqueuedTimeUtc = $enqueuedTimeUtc;
    }

    public function setLockedUntilUtc(Carbon|string $lockedUntilUtc): void
    {
        if (!$lockedUntilUtc instanceof Carbon) {
            $lockedUntilUtc = $this->azureDateToCarbon($lockedUntilUtc);
        }

        $this->lockedUntilUtc = $lockedUntilUtc;
    }

    public function setScheduledEnqueueTimeUtc(Carbon|string $scheduledEnqueueTimeUtc): void
    {
        if (!$scheduledEnqueueTimeUtc instanceof Carbon) {
            $scheduledEnqueueTimeUtc = $this->azureDateToCarbon($scheduledEnqueueTimeUtc);
        }

        $this->scheduledEnqueueTimeUtc = $scheduledEnqueueTimeUtc;
    }
    // endregion Getters/Setters

    // region Public Methods
    public function isTo(string $id): bool
    {
        return $this->to === $id;
    }
    // endregion Public Methods

    // region Protected Methods
    protected function azureDateToCarbon(string $date): ?Carbon
    {
        return Carbon::parse($date, 'UTC') ?: null;
    }

    protected function carbonToAzureDate(Carbon $carbon): string
    {
        return $carbon->format(\DateTimeInterface::RFC7231);
    }
    // endregion Protected Methods
}
