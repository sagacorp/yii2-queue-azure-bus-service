<?php

namespace sagacorp\queue\azure\service;

use Carbon\Carbon;

class BrokerProperties implements \JsonSerializable
{
    public function __construct(
        public ?string $correlationId = null,
        public ?string $sessionId = null,
        public int $deliveryCount = 1,
        public ?Carbon $lockedUntilUtc = null,
        public ?string $lockToken = null,
        public ?string $messageId = null,
        public ?string $label = null,
        public ?string $replyTo = null,
        public ?string $sequenceNumber = null,
        public float $timeToLive = 1,
        public ?string $to = null,
        public ?Carbon $scheduledEnqueueTimeUtc = null,
        public ?string $replyToSessionId = null,
        public ?string $partitionKey = null,
    ) {
        if (null === $this->messageId) {
            $this->messageId = uniqid('', true);
        }
    }

    public function isTo(string $id): bool
    {
        return $this->to === $id;
    }

    public function jsonSerialize(): mixed
    {
        return array_filter([
            'CorrelationId' => $this->correlationId,
            'SessionId' => $this->sessionId,
            'Label' => $this->label,
            'ReplyTo' => $this->replyTo,
            'TimeToLive' => $this->timeToLive,
            'To' => $this->to,
            'ScheduledEnqueueTimeUtc' => $this->scheduledEnqueueTimeUtc?->format(\DateTimeInterface::RFC7231),
            'ReplyToSessionId' => $this->replyToSessionId,
            'PartitionKey' => $this->partitionKey,
        ]);
    }

    public function setDelay(int $value): void
    {
        $this->scheduledEnqueueTimeUtc = Carbon::now('UTC')->addSeconds($value);
    }
}
