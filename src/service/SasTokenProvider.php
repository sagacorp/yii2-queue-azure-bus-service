<?php

declare(strict_types=1);

namespace sagacorp\queue\azure\service;

use yii\base\BaseObject;
use yii\base\InvalidConfigException;

class SasTokenProvider extends BaseObject implements TokenProvider
{
    public string $sharedAccessKey;
    public string $sharedAccessKeyName;
    public int $tokenDuration = 3600;

    public function getAuthorizationHeader(string $url): string
    {
        return (new SasTokenGenerator($url, $this->sharedAccessKeyName, $this->sharedAccessKey, $this->tokenDuration))
            ->generateSharedAccessSignatureToken();
    }

    /**
     * @throws InvalidConfigException
     */
    public function init(): void
    {
        parent::init();

        if (!isset($this->sharedAccessKey)) {
            throw new InvalidConfigException('"sharedAccessKey" is required.');
        }

        if (!isset($this->sharedAccessKeyName)) {
            throw new InvalidConfigException('"sharedAccessKeyName" is required.');
        }
    }
}
