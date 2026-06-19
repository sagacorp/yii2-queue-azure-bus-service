<?php

namespace sagacorp\queue\azure\service;

use Carbon\Carbon;
use yii\base\Component;
use yii\base\InvalidConfigException;
use yii\di\Instance;
use yii\helpers\ArrayHelper;
use yii\httpclient\Client;
use yii\httpclient\CurlTransport;
use yii\httpclient\Exception;
use yii\httpclient\RequestEvent;
use yii\httpclient\Response;

class ServiceBus extends Component
{
    public const int BATCH_MAX_BYTES = 256000;
    public const string RECEIVE_MODE_PEEK_LOCK = 'peek-lock';
    public const string RECEIVE_MODE_RECEIVE_AND_DELETE = 'receive-and-delete';

    private const string HEADER_AUTHENTICATION = 'authorization';

    public string $connectionString;
    public string $namespace;
    public string $queue;
    public string $receiveMode = self::RECEIVE_MODE_PEEK_LOCK;
    public int $requestMaxRetries = 10;
    public string $to;

    /**
     * The token provider used to authenticate requests against the Service Bus REST API.
     *
     * Accepts a component id, a configuration array or a {@see TokenProvider} instance.
     * It is required: use a {@see SasTokenProvider} for Shared Access Signature authentication
     * or an {@see AzureAdTokenProvider} for Azure AD (workload identity).
     */
    public array|string|TokenProvider $tokenProvider;

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

    /**
     * @throws InvalidConfigException
     */
    public function init(): void
    {
        parent::init();

        if (isset($this->connectionString)) {
            $connectionString = (new ConnectionStringParser($this->connectionString))->parseConnectionString();

            $this->host = $connectionString['host'];
            $this->queue ??= $connectionString['EntityPath'] ?? '';

            if (isset($connectionString['SharedAccessKeyName'], $connectionString['SharedAccessKey']) && !isset($this->tokenProvider)) {
                $this->tokenProvider = [
                    'class' => SasTokenProvider::class,
                    'sharedAccessKeyName' => $connectionString['SharedAccessKeyName'],
                    'sharedAccessKey' => $connectionString['SharedAccessKey'],
                ];
            }
        } else {
            $this->host = "{$this->namespace}.servicebus.windows.net";
        }

        $this->tokenProvider = $this->resolveTokenProvider();

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
     * Sends one or more messages, splitting into sub-batches to respect the 256KB limit.
     *
     * @param Message[] $messages
     *
     * @return Response[]
     *
     * @throws \JsonException
     * @throws Exception
     */
    public function sendMessages(array $messages): array
    {
        $entries = array_map(
            fn (Message $message) => json_encode($this->buildBatchEntry($message), JSON_THROW_ON_ERROR),
            $messages,
        );

        return array_map(fn (array $batch) => $this->sendBatch($batch), $this->splitIntoBatches($entries));
    }

    protected function authorizationHeaderHandler(RequestEvent $requestEvent): void
    {
        $requestEvent->request->headers->set(
            self::HEADER_AUTHENTICATION,
            $this->tokenProvider->getAuthorizationHeader($requestEvent->request->getFullUrl()),
        );
    }

    /**
     * @throws InvalidConfigException
     */
    protected function resolveTokenProvider(): TokenProvider
    {
        if (!isset($this->tokenProvider)) {
            throw new InvalidConfigException(
                'The "tokenProvider" property must be set to a TokenProvider.'
            );
        }

        return Instance::ensure($this->tokenProvider, TokenProvider::class);
    }

    private function buildBatchEntry(Message $message): array
    {
        $entry = ['Body' => $message->body];

        if ($message->brokerProperties instanceof BrokerProperties) {
            $entry['BrokerProperties'] = $message->brokerProperties->jsonSerialize();
        }

        if (!empty($message->properties)) {
            $entry['UserProperties'] = $message->properties;
        }

        return $entry;
    }

    private function sendBatch(array $encodedEntries): Response
    {
        $request = $this->httpClient->post(['/messages']);
        $request->headers->set('content-type', 'application/vnd.microsoft.servicebus.json');
        $request->setContent('[' . implode(',', $encodedEntries) . ']');

        return $request->sendAndRetryOnFailure(['201']);
    }

    /**
     * Splits pre-encoded JSON entries into sub-batches respecting the 256KB limit.
     *
     * @param string[] $encodedEntries JSON-encoded entries
     *
     * @return string[][] batches of JSON-encoded entries
     */
    private function splitIntoBatches(array $encodedEntries): array
    {
        $batches = [];
        $currentBatch = [];
        $currentSize = 0;

        foreach ($encodedEntries as $entry) {
            $entrySize = strlen($entry) + 1;

            if (!empty($currentBatch) && ($currentSize + $entrySize) > self::BATCH_MAX_BYTES) {
                $batches[] = $currentBatch;
                $currentBatch = [];
                $currentSize = 0;
            }

            $currentBatch[] = $entry;
            $currentSize += $entrySize;
        }

        if (!empty($currentBatch)) {
            $batches[] = $currentBatch;
        }

        return $batches;
    }
}
