<?php

namespace sagacorp\queue\azure;

use sagacorp\queue\azure\service\BrokerProperties;
use sagacorp\queue\azure\service\Message;
use sagacorp\queue\azure\service\ServiceBus;
use yii\base\InvalidConfigException;
use yii\base\NotSupportedException;
use yii\di\Instance;
use yii\httpclient\Exception;
use yii\queue\PushEvent;

class Queue extends \yii\queue\cli\Queue
{
    // region Public Properties
    /**
     * use this property to filter job execution on a specific id
     * You can use this property when you need to run multiple environments with the same queue at the same time, multiple locals environments for example.
     *
     * @see BrokerProperties::$to
     */
    public ?string $id = null;
    public string $queue = 'default';
    /** @var ServiceBus[] */
    public array $queues = [
        'default' => 'serviceBus'
    ];
    // endregion Public Properties

    // region Initialization
    /**
     * @throws \yii\base\InvalidConfigException
     */
    public function init(): void
    {
        parent::init();

        $this->commandClass = Command::class;

        foreach ($this->queues as $queue => $config) {
            $this->queues[$queue] = Instance::ensure($config, ServiceBus::class);
        }
    }
    // endregion Initialization

    // region Public Methods
    /**
     * Listens queue and runs each job.
     *
     * @param bool $repeat  whether to continue listening when queue is empty.
     * @param int  $timeout number of seconds to wait for next message.
     *
     * @return null|int exit code.
     * @internal for worker command only.
     * @since    2.0.2
     */
    public function run(bool $repeat, ?string $queue = null, int $timeout = 30): ?int
    {
        return $this->runWorker(fn (callable $canContinue) => $this->processWorker($canContinue, $repeat, $queue ?? $this->queue, $timeout));
    }

    public function push($job): ?string
    {
        $defaultQueue = $this->queue;

        if ($job instanceof AzureJobInterface && ($dedicatedQueue = $job->getQueue()) && isset($this->queues[$dedicatedQueue])) {
            $this->queue = $dedicatedQueue;
        }

        $result = parent::push($job);

        $this->queue = $defaultQueue;

        return $result;
    }

    /**
     * @param string $id of a job message
     *
     * @throws NotSupportedException
     * @return void status code
     */
    public function status($id): void
    {
        throw new NotSupportedException('Status is not supported in the driver.');
    }
    // endregion Public Methods

    // region Protected Methods
    /**
     * @throws Exception
     * @throws InvalidConfigException
     * @throws \JsonException
     */
    protected function processWorker(callable $canContinue, bool $repeat, string $queue, int $timeout = 30): void
    {
        while ($canContinue()) {
            $message = $this->queues[$queue]->receiveMessage(ServiceBus::PEEK_LOCK, $timeout);

            if ($message !== null && $message->brokerProperties !== null) {
                if ($message->brokerProperties->to && !$message->brokerProperties->isTo($this->id)) {
                    continue;
                }
                if ($this->handleMessage($message->brokerProperties->messageId, $message->body, $message->brokerProperties->timeToLive, $message->brokerProperties->deliveryCount)) {
                    $this->queues[$queue]->deleteMessage($message);
                }
            } elseif (!$repeat) {
                break;
            }
        }
    }

    /**
     * @param       $message
     * @param int   $ttr time to reserve in seconds
     * @param int   $delay
     * @param mixed $priority
     *
     * @throws \JsonException
     * @throws \yii\httpclient\Exception
     * @return string id of a job message
     */
    protected function pushMessage($message, $ttr, $delay, $priority): string
    {
        $brokerProperties = new BrokerProperties(timeToLive: $ttr, to: $this->id);

        $brokerProperties->setDelay($delay);

        $azureMessage = new Message($message, brokerProperties: $brokerProperties);

        $this->queues[$this->queue]->sendMessage($azureMessage);

        return $azureMessage->brokerProperties->messageId;
    }
    // endregion Protected Methods
}
