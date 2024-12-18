<?php

namespace sagacorp\queue\azure\service;

use Carbon\Carbon;
use JsonException;
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
    // region Constants
    public const  PEEK_LOCK = 'POST';
    private const HEADER_AUTHENTICATION = 'authorization';
    private const SAS_AUTHORIZATION_FORMAT = 'SharedAccessSignature sig=%s&se=%s&skn=%s&sr=%s';
    // endregion Constants

    // region Public Properties
    public bool $acceptMessage = true;
    public string $environment = 'servicebus.windows.net';
    public string $queue;
    public int $requestMaxRetries = 10;
    public string $serviceBusNamespace;
    public string $sharedAccessKey;
    public string $sharedAccessKeyName;
    public string $to;
    public int $tokenDuration = 3600;
    // endregion Public Properties

    // region Private Properties
    private Client $httpClient;
    // endregion Private Properties

    // region Initialization
    public function init(): void
    {
        parent::init();

        $this->httpClient = new Client([
            'baseUrl' => sprintf('https://%s.%s', $this->serviceBusNamespace, $this->environment),
            'transport' => CurlTransport::class,
            'requestConfig' => [
                'class' => Request::class,
                'maxRetries' => $this->requestMaxRetries,
            ],
        ]);

        $this->httpClient->on(Request::EVENT_BEFORE_SEND, fn (RequestEvent $requestEvent) => $this->authorizationHeaderHandler($requestEvent));
    }
    // endregion Initialization

    // region Public Methods
    /**
     * Deletes a brokered message.
     *
     * @param Message $message The brokered message
     *
     * @throws Exception
     */
    public function deleteMessage(Message $message): void
    {
        $lockLocationPath = parse_url($message->location, PHP_URL_PATH);

        $request = $this->httpClient->delete($lockLocationPath);

        $request->sendAndRetryOnFailure(['200']);
    }

    /**
     * Receives a message.
     *
     * @throws InvalidConfigException
     */
    public function receiveMessage(string $peekMethod, int $timeout = null): ?Message
    {
        $url = [sprintf('%s/messages/head', $this->queue)];

        if (null !== $timeout) {
            $url['timeout'] = $timeout;
        }

        $request = $this->httpClient->createRequest()->setUrl($url)->setMethod($peekMethod);

        $request->headers->add('content-length', 0);

        $response = $request->sendAndRetryOnFailure(['204', '200', '201']);
        $message = null;

        if (\in_array($response->statusCode, ['200', '201'], true)) {
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
        }

        return $message;
    }

    /**
     * Sends a brokered message.
     *
     * @throws JsonException
     * @throws Exception|Exception
     */
    public function sendMessage(Message $message, ?string $queue = null): Response
    {
        $path = sprintf('%s/messages', $queue ?? $this->queue);

        $request = $this->httpClient->post($path, $message->body);

        if ($message->contentType !== null) {
            $request->headers->set('content-type', $message->contentType);
        }

        if ($message->brokerProperties !== null) {
            $request->headers->set('BrokerProperties', json_encode($message->brokerProperties, JSON_THROW_ON_ERROR));
        }

        foreach ($message->properties as $key => $value) {
            $value = json_encode($value, JSON_THROW_ON_ERROR);
            $request->headers->set($key, $value);
        }

        return $request->sendAndRetryOnFailure(['201']);
    }
    // endregion Public Methods

    // region Protected Methods
    protected function authorizationHeaderHandler(RequestEvent $requestEvent): void
    {
        $requestEvent->request->headers->set(self::HEADER_AUTHENTICATION, $this->generateAuthorizationToken($requestEvent->request->getFullUrl()));
    }

    /**
     * @param string $url
     *
     * @return string
     */
    protected function generateAuthorizationToken(string $url): string
    {
        $expiry = time() + $this->tokenDuration;
        $encodedUrl = $this->lowerUrlEncode($url);
        $scope = $encodedUrl . "\n" . $expiry;
        $signature = base64_encode(hash_hmac('sha256', $scope, $this->sharedAccessKey, true));

        return sprintf(
            self::SAS_AUTHORIZATION_FORMAT,
            $this->lowerUrlEncode($signature),
            $expiry,
            $this->sharedAccessKeyName,
            $encodedUrl
        );
    }

    protected function lowerUrlEncode($str): ?string
    {
        return preg_replace_callback('/%[0-9A-F]{2}/', static fn (array $matches) => strtolower($matches[0]), urlencode($str));
    }
    // endregion Protected Methods
}
