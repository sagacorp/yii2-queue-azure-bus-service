<?php

namespace sagacorp\queue\azure;

use sagacorp\queue\azure\service\BrokerProperties;
use sagacorp\queue\azure\service\Message;
use sagacorp\queue\azure\service\ServiceBus;
use sagacorp\queue\contracts\BatchableQueueInterface;
use yii\base\InvalidConfigException;
use yii\base\NotSupportedException;
use yii\di\Instance;
use yii\httpclient\Exception;

class Queue extends \yii\queue\cli\Queue implements BatchableQueueInterface
{
    public $commandClass = Command::class;

    /**
     * use this property to filter job execution on a specific id
     * You can use this property when you need to run multiple environments with the same queue at the same time, multiple locals environments for example.
     *
     * @see BrokerProperties::$to
     */
    public ?string $id = null;

    /** @var array|ServiceBus|string */
    public $serviceBus = 'serviceBus';

    public function init()
    {
        parent::init();

        $this->serviceBus = Instance::ensure($this->serviceBus, ServiceBus::class);
    }

    public function pushBatch(array $messages): array
    {
        $azureMessages = array_map(
            fn (array $entry) => $this->buildMessage($entry['message'], $entry['ttr'], $entry['delay']),
            $messages,
        );

        $this->serviceBus->sendMessages($azureMessages);

        return array_map(fn (Message $m) => $m->brokerProperties->messageId, $azureMessages);
    }

    /**
     * Listens queue and runs each job.
     *
     * @param bool $repeat  whether to continue listening when queue is empty
     * @param int  $timeout number of seconds to wait for next message
     *
     * @return null|int exit code
     *
     * @internal for worker command only
     *
     * @since    2.0.2
     */
    public function run(bool $repeat, int $timeout = 10): ?int
    {
        return $this->runWorker(fn (callable $canContinue) => $this->processWorker($canContinue, $repeat, $timeout));
    }

    /**
     * @param string $id of a job message
     *
     * @throws NotSupportedException
     */
    public function status($id): void
    {
        throw new NotSupportedException('Status is not supported in the driver.');
    }

    protected function buildMessage(string $message, int $ttr, int $delay): Message
    {
        $brokerProperties = new BrokerProperties(timeToLive: $ttr, to: $this->id);
        $brokerProperties->setDelay($delay);

        return new Message($message, brokerProperties: $brokerProperties);
    }

    /**
     * @throws Exception
     * @throws InvalidConfigException
     * @throws \JsonException
     */
    protected function processWorker(callable $canContinue, bool $repeat, int $timeout = 10): void
    {
        while ($canContinue()) {
            $message = $this->serviceBus->receiveMessage($timeout);

            if (null !== $message && null !== $message->brokerProperties) {
                if ($message->brokerProperties->to && !$message->brokerProperties->isTo($this->id)) {
                    continue;
                }
                if ($this->handleMessage($message->brokerProperties->messageId, $message->body, $message->brokerProperties->timeToLive, $message->brokerProperties->deliveryCount)) {
                    $this->serviceBus->deleteMessage($message);
                }
            } elseif (!$repeat) {
                break;
            }
        }
    }

    protected function pushMessage($message, $ttr, $delay, $priority): string
    {
        $azureMessage = $this->buildMessage($message, $ttr, $delay);

        $this->serviceBus->sendMessages([$azureMessage]);

        return $azureMessage->brokerProperties->messageId;
    }
}
