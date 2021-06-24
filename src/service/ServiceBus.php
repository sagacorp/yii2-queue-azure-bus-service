<?php

namespace saga\queue\azure\service;

use JsonException;
use yii\base\Component;
use yii\base\InvalidConfigException;
use yii\helpers\ArrayHelper;
use yii\httpclient\Client;
use yii\httpclient\Exception;
use yii\httpclient\Request;
use yii\httpclient\RequestEvent;
use yii\httpclient\Response;

class ServiceBus extends Component
{
    //region Constants
    public const  PEEK_LOCK                = 'POST';
    public const  RECEIVE_DELETE           = 'DELETE';
    private const SAS_AUTHORIZATION_FORMAT = 'SharedAccessSignature sig=%s&se=%s&skn=%s&sr=%s';
    private const HEADER_AUTHENTICATION    = 'authorization';
    //endregion Constants

    //region Public Properties
    public string $environment   = 'servicebus.windows.net';
    public string $queue;
    public string $serviceBusNamespace;
    public string $sharedAccessKey;
    public string $sharedAccessKeyName;
    public int    $tokenDuration = 3600;
    //endregion Public Properties

    //region Private Properties
    private Client $httpClient;
    //endregion Private Properties

    //region Initialization
    /**
     * @throws InvalidConfigException
     */
    public function init(): void
    {
        parent::init();

        $this->httpClient = new Client(['baseUrl' => sprintf('https://%s.%s', $this->serviceBusNamespace, $this->environment)]);

        $this->httpClient->on(Request::EVENT_BEFORE_SEND, [$this, 'authorizationHeaderHandler']);
    }
    //endregion Initialization

    //region Public Methods
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

        $request->send();
    }

    /**
     * Receives a message.
     *
     * @param string   $peekMethod
     * @param int|null $timeout
     *
     * @throws Exception
     * @throws InvalidConfigException
     * @throws JsonException
     * @return Message|null
     */
    public function receiveMessage(string $peekMethod, int $timeout = null): ?Message
    {
        $url = [sprintf('%s/messages/head', $this->queue)];

        if (null !== $timeout) {
            $url['timeout'] = $timeout;
        }

        $request = $this->httpClient->createRequest()->setUrl($url)->setMethod($peekMethod);

        $request->headers->add('content-length', 0);

        $response = $request->send();

        if ($response->statusCode === '204') {
            $message = null;
        } elseif ($response->statusCode === '200' || $response->statusCode === '201') {
            $headers = [];

            foreach ($response->headers as $key => $value) {
                if (!empty($value)) {
                    $headers[$key] = reset($value);
                }
            }

            $message = new Message(
                [
                    'body'        => $response->getContent(),
                    'contentType' => ArrayHelper::remove($headers, 'content-type'),
                    'date'        => ArrayHelper::remove($headers, 'date'),
                    'location'    => ArrayHelper::remove($headers, 'location'),
                ]
            );

            $headerBrokerProperties = ArrayHelper::remove($headers, 'brokerproperties');

            if ($headerBrokerProperties) {
                try {
                    $headerBrokerProperties = json_decode((string) $headerBrokerProperties, true, 512, JSON_THROW_ON_ERROR);
                    $headerBrokerProperties = array_flip((array_map('lcfirst', array_flip($headerBrokerProperties))));

                    $message->brokerProperties = new BrokerProperties();
                    $message->brokerProperties->setAttributes($headerBrokerProperties, false);
                } catch (\JsonException $e) {
                    \Yii::error($e);
                }
            }

            foreach ($headers as $headerKey => $value) {
                if (is_scalar($value)) {
                    $message->setProperty($headerKey, $value);
                }
            }
        } else {
            throw new Exception($response->statusCode);
        }

        return $message;
    }

    /**
     * Sends a brokered message.
     *
     * @param Message $message The brokered message
     *
     * @throws JsonException
     * @throws Exception|Exception
     * @return Response
     */
    public function sendMessage(Message $message): Response
    {
        $path = sprintf('%s/messages', $this->queue);

        $request = $this->httpClient->post($path, $message->body);

        if ($message->contentType !== null) {
            $request->headers->set('content-type', $message->contentType);
        }

        if ($message->brokerProperties !== null) {
            $request->headers->set('BrokerProperties', (string) $message->brokerProperties);
        }

        if (!empty($message->properties)) {
            foreach ($message->properties as $key => $value) {
                $value = json_encode($value, JSON_THROW_ON_ERROR);
                $request->headers->set($key, $value);
            }
        }

        $response = $request->send();

        if ('201' !== $response->statusCode) {
            throw new Exception($response->toString());
        }

        return $response;
    }
    //endregion Public Methods

    //region Events Handler
    public function authorizationHeaderHandler(RequestEvent $requestEvent): void
    {
        $requestEvent->request->headers->add(self::HEADER_AUTHENTICATION, $this->generateAuthorizationToken($requestEvent->request->getFullUrl()));
    }
    //endregion Events Handler

    //region Protected Methods
    /**
     * @param string $url
     *
     * @return string
     */
    protected function generateAuthorizationToken(string $url): string
    {
        $expiry     = time() + $this->tokenDuration;
        $encodedUrl = $this->lowerUrlEncode($url);
        $scope      = $encodedUrl . "\n" . $expiry;
        $signature  = base64_encode(hash_hmac('sha256', $scope, $this->sharedAccessKey, true));

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
        return preg_replace_callback(
            '/%[0-9A-F]{2}/',
            static function (array $matches) {
                return strtolower($matches[0]);
            },
            urlencode($str)
        );
    }
    //endregion Protected Methods
}
