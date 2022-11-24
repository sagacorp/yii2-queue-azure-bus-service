<?php

namespace sagacorp\queue\azure\service;

use yii\httpclient\Exception as HttpClientException;

class Request extends \yii\httpclient\Request
{
    // region Public Properties
    public int $maxRetries;
    // endregion Public Properties

    // region Protected Properties
    protected int $attempts = 0;
    // endregion Protected Properties

    // region Public Methods
    /**
     * @throws HttpClientException
     */
    public function sendAndRetryOnFailure(array $successStatusCodes): \yii\httpclient\Response
    {
        try {
            $response = $this->send();

            if (!in_array($response->statusCode, $successStatusCodes, true)) {
                throw new HttpClientException($response->toString());
            }
        } catch (HttpClientException $e) {
            \Yii::error($e);

            if (!$this->canContinue($this->attempts)) {
                throw $e;
            }

            $delay = $this->getRetryDelay($this->attempts);

            \Yii::error('Retry request in ' . $delay . ' seconds');
            sleep($delay);

            ++$this->attempts;

            $response = $this->sendAndRetryOnFailure($successStatusCodes);
        }

        return $response;
    }

    // endregion Public Methods

    // region Protected Methods
    protected function canContinue(int $attempts): bool
    {
        return $attempts < $this->maxRetries;
    }

    protected function getRetryDelay(int $attempts): int
    {
        return 4 ** $attempts;
    }
    // endregion Protected Methods
}