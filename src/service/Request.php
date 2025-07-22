<?php

namespace sagacorp\queue\azure\service;

use yii\httpclient\Exception as HttpClientException;
use yii\httpclient\Request as BaseRequest;
use yii\httpclient\Response;

class Request extends BaseRequest
{
    public int $maxRetries;

    protected int $attempts = 0;

    /**
     * @throws HttpClientException
     */
    public function sendAndRetryOnFailure(array $successStatusCodes): Response
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

    protected function canContinue(int $attempts): bool
    {
        return $attempts < $this->maxRetries;
    }

    protected function getRetryDelay(int $attempts): int
    {
        return 4 ** $attempts;
    }
}
