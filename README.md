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
"sagacorp/yii2-queue-azure-service-bus": "~1.0.0"
```

<h2>Basic Usage</h2>

First, you may configure your [Azure service Bus](https://docs.microsoft.com/en-us/azure/service-bus-messaging/service-bus-quickstart-portal#create-a-namespace-in-the-azure-portal).


Then, configure yii2 queue, and the service bus like the following:

```php
return [
    'components' => [
        'queue'                   => [
            'class'      => \saga\queue\azure\Queue::class,
            'as log'     => \yii\queue\LogBehavior,
            'serializer' => \yii\queue\serializers\JsonSerializer::class,
        ],
        'serviceBus'              => [
            'class'               => \saga\queue\azure\service\ServiceBus::class,
            'serviceBusNamespace' => 'your service bus namespace',
            'sharedAccessKey'     => 'your shared access key to access the service bus queue',
            'sharedAccessKeyName' => 'your shared access key name',
            'queue'               => 'the name of your Azure Service Bus default queue',
        ],
    ]
];
 ```       
      
*Currently this extension supports the [Shared Access Signature authentication](https://docs.microsoft.com/en-us/azure/service-bus-messaging/service-bus-sas) only. It doesn't support Azure Active Directory.*
        
Once configured,  you can send a task into the queue:

```php
Yii::$app->queue->push(new DownloadJob([
    'url' => 'http://example.com/image.jpg',
    'file' => '/tmp/image.jpg',
]));
```



