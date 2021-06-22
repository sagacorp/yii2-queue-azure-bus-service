<?php

namespace saga\queue\azure;

use Carbon\Carbon;
use saga\queue\azure\service\BrokerProperties;
use saga\queue\azure\service\Message;
use saga\queue\azure\service\ServiceBus;
use yii\base\NotSupportedException;
use yii\di\Instance;

/**
 * Azure bus Queue.
 */
class Queue extends \yii\queue\cli\Queue
{
    //region Constants
    public const PRIORITY = 'yii-priority';
    //endregion Constants

    //region Public Properties
    /**
     * @var ServiceBus
     */
    public $serviceBus = 'serviceBus';
    //endregion Public Properties

    //region Initialization
    /**
     * @throws \yii\base\InvalidConfigException
     */
    public function init(): void
    {
        parent::init();

        $this->commandClass = Command::class;

        $this->serviceBus = Instance::ensure($this->serviceBus, ServiceBus::class);
    }
    //endregion Initialization

    //region Public Methods

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
    public function run(bool $repeat, int $timeout = 30): ?int
    {
        return $this->runWorker(
            function (callable $canContinue) use ($repeat, $timeout) {
                while ($canContinue()) {
                    $message = $this->serviceBus->receiveMessage(ServiceBus::PEEK_LOCK, $timeout);

                    if ($message !== null) {
                        if ($this->handleMessage($message->getMessageId(), $message->getBody(), $message->getTimeToLive(), $message->getDeliveryCount())) {
                            $this->serviceBus->deleteMessage($message);
                        }
                    } elseif (!$repeat) {
                        break;
                    }
                }
            }
        );
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
    //endregion Public Methods

    //region Protected Methods
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
        $azureMessage = new Message(
            [
                'body'             => $message,
                'contentType'      => 'application/vnd.microsoft.servicebus.yml',
                'brokerProperties' => new BrokerProperties(
                    [
                        'timeToLive'              => $ttr,
                        'scheduledEnqueueTimeUtc' => Carbon::now()->addSeconds($delay),
                    ]
                ),
                'properties'       => [
                    self::PRIORITY => $priority ?? 'low',
                ],
            ]
        );

        $this->serviceBus->sendMessage($azureMessage);

        return $azureMessage->brokerProperties->messageId;
    }
    //endregion Protected Methods
}
