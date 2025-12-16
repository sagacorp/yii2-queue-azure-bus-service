<?php

namespace sagacorp\queue\azure\service;

use Carbon\Carbon;
use yii\base\Component;
use yii\base\InvalidConfigException;
use yii\helpers\ArrayHelper;
use yii\httpclient\Client;
use yii\httpclient\CurlTransport;
use yii\httpclient\Exception;
use yii\httpclient\RequestEvent;
use yii\httpclient\Response;

class ServiceBus extends Component
{
    public const string RECEIVE_MODE_PEEK_LOCK = 'peek-lock';
    public const string RECEIVE_MODE_RECEIVE_AND_DELETE = 'receive-and-delete';

    private const string HEADER_AUTHENTICATION = 'authorization';

    public string $connectionString;
    public string $namespace;
    public string $queue;
    public string $receiveMode = self::RECEIVE_MODE_PEEK_LOCK;
    public int $requestMaxRetries = 10;
    public string $sharedAccessKey;
    public string $sharedAccessKeyName;
    public string $to;
    public int $tokenDuration = 3600;

    private string $host;
    private Client $httpClient;

    /**
     * Deletes a brokered message.
     *
     * @param Message $message The brokered message
     *
     * @throws Exception
     */
    public function deleteMessage(Message $message): void
    {
        // Messages are already deleted in the "Receive And Delete" receive mode
        if (self::RECEIVE_MODE_RECEIVE_AND_DELETE === $this->receiveMode) {
            return;
        }

        $location = empty($message->location)
            ? "/messages/{$message->brokerProperties->sequenceNumber}/{$message->brokerProperties->lockToken}"
            : $message->location;

        $request = $this->httpClient->delete($location);

        $request->sendAndRetryOnFailure(['200']);
    }

    public function init(): void
    {
        parent::init();

        if (isset($this->connectionString)) {
            $connectionString = (new ConnectionStringParser($this->connectionString))->parseConnectionString();

            $this->host = $connectionString['host'];
            $this->sharedAccessKeyName ??= $connectionString['SharedAccessKeyName'] ?? '';
            $this->sharedAccessKey ??= $connectionString['SharedAccessKey'] ?? '';
            $this->queue ??= $connectionString['EntityPath'] ?? '';
        } else {
            $this->host = "{$this->namespace}.servicebus.windows.net";
        }
        $this->httpClient = new Client([
            'baseUrl' => sprintf('https://%s/%s', $this->host, $this->queue),
            'transport' => CurlTransport::class,
            'requestConfig' => [
                'class' => Request::class,
                'maxRetries' => $this->requestMaxRetries,
            ],
        ]);

        $this->httpClient->on(Request::EVENT_BEFORE_SEND, fn (RequestEvent $requestEvent) => $this->authorizationHeaderHandler($requestEvent));
    }

    /**
     * Receives a message.
     *
     * @throws InvalidConfigException
     */
    public function receiveMessage(?int $timeout = null): ?Message
    {
        $url = ['/messages/head'];

        if (null !== $timeout) {
            $url['timeout'] = $timeout;
        }

        $method = self::RECEIVE_MODE_PEEK_LOCK === $this->receiveMode ? 'POST' : 'DELETE';
        $request = $this->httpClient->createRequest()->setUrl($url)->setMethod($method);

        $request->headers->add('content-length', 0);

        $expectedStatusCode = self::RECEIVE_MODE_PEEK_LOCK === $this->receiveMode ? '201' : '200';

        $response = $request->sendAndRetryOnFailure(['204', $expectedStatusCode]);

        if ('204' === $response->statusCode) {
            return null;
        }

        $headers = [];

        foreach ($response->headers as $key => $value) {
            if (!empty($value)) {
                $headers[$key] = reset($value);
            }
        }

        $message = new Message(
            $response->getContent(),
            ArrayHelper::remove($headers, 'content-type'),
            Carbon::parse(ArrayHelper::remove($headers, 'date')) ?? null,
            ArrayHelper::remove($headers, 'location'),
        );

        $headerBrokerProperties = ArrayHelper::remove($headers, 'brokerproperties');

        if ($headerBrokerProperties) {
            try {
                $headerBrokerProperties = json_decode((string) $headerBrokerProperties, true, 512, JSON_THROW_ON_ERROR);

                $message->brokerProperties = new BrokerProperties(
                    $headerBrokerProperties['CorrelationId'] ?? null,
                    $headerBrokerProperties['SessionId'] ?? null,
                    $headerBrokerProperties['DeliveryCount'] ?? 1,
                    Carbon::parse($headerBrokerProperties['LockedUntil'] ?? null, 'UTC') ?? null,
                    $headerBrokerProperties['LockToken'] ?? null,
                    $headerBrokerProperties['MessageId'] ?? null,
                    $headerBrokerProperties['Label'] ?? null,
                    $headerBrokerProperties['ReplyTo'] ?? null,
                    $headerBrokerProperties['SequenceNumber'] ?? null,
                    $headerBrokerProperties['TimeToLive'] ?? 1,
                    $headerBrokerProperties['To'] ?? null,
                    Carbon::parse($headerBrokerProperties['ScheduledEnqueueTimeUtc'] ?? null, 'UTC') ?? null,
                    $headerBrokerProperties['ReplyToSessionId'] ?? null,
                    $headerBrokerProperties['PartitionKey'] ?? null,
                );
            } catch (\JsonException $e) {
                \Yii::error($e);
            }
        }

        foreach ($headers as $headerKey => $value) {
            if (is_scalar($value)) {
                $message->setProperty($headerKey, $value);
            }
        }

        return $message;
    }

    /**
     * Sends a brokered message.
     *
     * @throws \JsonException
     * @throws Exception|Exception
     */
    public function sendMessage(Message $message): Response
    {
        $path = ['/messages'];

        $request = $this->httpClient->post($path, $message->body);

        if (null !== $message->contentType) {
            $request->headers->set('content-type', $message->contentType);
        }

        if ($message->brokerProperties instanceof BrokerProperties) {
            $request->headers->set('BrokerProperties', json_encode($message->brokerProperties, JSON_THROW_ON_ERROR));
        }

        foreach ($message->properties as $key => $value) {
            $value = json_encode($value, JSON_THROW_ON_ERROR);
            $request->headers->set($key, $value);
        }

        return $request->sendAndRetryOnFailure(['201']);
    }

    protected function authorizationHeaderHandler(RequestEvent $requestEvent): void
    {
        $authToken = (new SasTokenGenerator(
            $requestEvent->request->getFullUrl(),
            $this->sharedAccessKeyName,
            $this->sharedAccessKey,
            $this->tokenDuration
        ))->generateSharedAccessSignatureToken();

        $requestEvent->request->headers->set(self::HEADER_AUTHENTICATION, $authToken);
    }
}
