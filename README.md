<h1>Azure service bus driver for Yii2 Queue</h1>

This extension is a [Yii2 Queue](https://github.com/yiisoft/yii2-queue) driver for queues based on [Microsoft Azure Service Bus](https://docs.microsoft.com/en-us/azure/service-bus-messaging/service-bus-messaging-overview).

It uses the [Azure Service Bus REST API](https://docs.microsoft.com/en-us/rest/api/servicebus)

<h2>Installation</h2>

Install this extension with [composer](http://getcomposer.org/download/).

Either run

```
php composer.phar require --prefer-dist sagacorp/yii2-queue-azure-service-bus
```

or add the extension to your composer json.

```
"sagacorp/yii2-queue-azure-service-bus": "^5.0"
```

<h2>Basic Usage</h2>

First, you may configure your [Azure service Bus](https://docs.microsoft.com/en-us/azure/service-bus-messaging/service-bus-quickstart-portal#create-a-namespace-in-the-azure-portal).


Then, configure yii2 queue, and the service bus like the following:

```php
return [
    'components' => [
        'queue' => [
            'class' => \saga\queue\azure\Queue::class,
            'as log' => \yii\queue\LogBehavior,
            'serializer' => \yii\queue\serializers\JsonSerializer::class,
            'serviceBus' => [
                'class' => \saga\queue\azure\service\ServiceBus::class,

                // Where to connect. Either a connection string...
                'connectionString' => 'Endpoint=sb://(namespace).servicebus.windows.net/;EntityPath=(queue)',

                // ...or the namespace and queue directly.
                'namespace' => 'your service bus namespace',
                'queue' => 'the name of your Azure Service Bus queue (can be different than the name used as config key)',

                // Required: how to authenticate (see below).
                'tokenProvider' => [
                    'class' => \saga\queue\azure\service\SasTokenProvider::class,
                    'sharedAccessKeyName' => 'your shared access key name',
                    'sharedAccessKey' => 'your shared access key',
                ],
            ],
        ],
    ],
];
 ```

<h3>Authentication</h3>

Authentication is handled by a dedicated, **required** `tokenProvider` component, so the
`ServiceBus` component itself only carries the connection parameters. The `tokenProvider` accepts a
configuration array (as shown below), a shared application component id, or an already built
`TokenProvider` instance. Two providers are shipped:

**`SasTokenProvider`** — [Shared Access Signature](https://docs.microsoft.com/en-us/azure/service-bus-messaging/service-bus-sas)
authentication. Provide the key explicitly or derive it from a connection string:

```php
'tokenProvider' => [
    'class' => \saga\queue\azure\service\SasTokenProvider::class,
    'sharedAccessKeyName' => '...',
    'sharedAccessKey' => '...',
    // or, instead of the two keys above:
    'connectionString' => 'Endpoint=;SharedAccessKeyName=...;SharedAccessKey=...',
],
```

**`AzureAdTokenProvider`** — [Azure AD](https://learn.microsoft.com/azure/aks/workload-identity-overview)
authentication via [`azure-oss/identity`](https://github.com/Azure-OSS/azure-identity-php)'s
`DefaultAzureCredential` (environment variables, then workload identity). The acquired access token
is used as a `Bearer` token against the Service Bus REST API. When the Azure Workload Identity
mutating webhook is enabled, the credentials are injected automatically through the `AZURE_*`
environment variables, so no configuration is needed:

```php
'tokenProvider' => \saga\queue\azure\service\AzureAdTokenProvider::class,
```

Optionally tune the scope and token caching:

```php
'tokenProvider' => [
    'class' => \saga\queue\azure\service\AzureAdTokenProvider::class,
    'scope' => 'https://servicebus.azure.net/.default', // default
    // Shared token cache. Defaults to the application `cache` component when available, otherwise
    // the token is only kept in memory for the lifetime of the worker. Set to false to opt out, or
    // pass another cache component id / configuration / instance.
    'cache' => 'cache',
    'expiryLeeway' => 300, // seconds before expiry at which the cached token is refreshed
],
```

> This provider requires a [PSR-18](https://www.php-fig.org/psr/psr-18/) HTTP client and
> [PSR-17](https://www.php-fig.org/psr/psr-17/) factories to be installed, for example
> `composer require guzzlehttp/guzzle`.

The targeted identity must be granted a Service Bus data plane role (e.g. *Azure Service Bus Data
Sender* / *Data Receiver*) on the namespace or queue.

> **Upgrading from 4.x:** `tokenProvider` is now mandatory. Move the `sharedAccessKey`,
> `sharedAccessKeyName` and `tokenDuration` options off `ServiceBus` and into a `SasTokenProvider`
> under `tokenProvider` to keep the previous SAS behaviour.

Once configured,  you can send a task into the queue:

```php
Yii::$app->queue->push(new DownloadJob([
    'url' => 'http://example.com/image.jpg',
    'file' => '/tmp/image.jpg',
]));
```
