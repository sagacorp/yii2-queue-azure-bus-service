<?php

declare(strict_types=1);

namespace sagacorp\queue\azure\service;

use AzureOss\Identity\AccessToken;
use AzureOss\Identity\DefaultAzureCredential;
use AzureOss\Identity\TokenCredential;
use AzureOss\Identity\TokenRequestContext;
use yii\base\Application;
use yii\base\BaseObject;
use yii\base\InvalidConfigException;
use yii\caching\CacheInterface;
use yii\caching\DummyCache;
use yii\di\Instance;

/**
 * Authenticates against the Service Bus REST API with an Azure AD access token, delegating the
 * token acquisition to azure-oss/identity's DefaultAzureCredential (environment variables, then
 * workload identity).
 *
 * On AKS the federated token file and the client/tenant identifiers are injected automatically
 * by the Azure Workload Identity mutating webhook through the AZURE_FEDERATED_TOKEN_FILE,
 * AZURE_CLIENT_ID and AZURE_TENANT_ID environment variables, so this provider works without any
 * explicit configuration.
 *
 * Requires a PSR-18 HTTP client and PSR-17 factories to be installed (e.g. guzzlehttp/guzzle).
 *
 * @see https://learn.microsoft.com/azure/aks/workload-identity-overview
 */
class AzureAdTokenProvider extends BaseObject implements TokenProvider
{
    /**
     * The cache used to share acquired access tokens across requests/workers.
     *
     * Accepts a component id, a configuration array or a {@see CacheInterface} instance, and
     * defaults to the application `cache` component when it is available. Set to `false` to disable
     * shared caching and only keep the token in memory for the lifetime of the worker.
     */
    public array|CacheInterface|false|string $cache = 'cache';

    /** Prefix for the cache key the access token is stored under. */
    public string $cacheKeyPrefix = 'azure-service-bus.azure-ad-token';

    /** Number of seconds before the real expiry at which the cached token is refreshed. */
    public int $expiryLeeway = 300;

    public string $scope = 'https://servicebus.azure.net/.default';

    private AccessToken $accessToken;
    private CacheInterface $cacheComponent;
    private TokenCredential $credential;

    public function getAuthorizationHeader(string $url): string
    {
        return 'Bearer ' . $this->getAccessToken();
    }

    /**
     * @throws InvalidConfigException
     */
    public function init(): void
    {
        parent::init();

        $this->credential = new DefaultAzureCredential();

        $this->cacheComponent = $this->resolveCache();
    }

    protected function getAccessToken(): string
    {
        if (isset($this->accessToken) && $this->isFresh($this->accessToken)) {
            return $this->accessToken->token;
        }

        $cacheKey = $this->cacheKey();

        $cached = $this->cacheComponent->get($cacheKey);

        if ($cached instanceof AccessToken && $this->isFresh($cached)) {
            return ($this->accessToken = $cached)->token;
        }

        $this->accessToken = $this->credential->getToken(new TokenRequestContext([$this->scope]));

        $ttl = $this->accessToken->expiresOn->getTimestamp() - time() - $this->expiryLeeway;

        if ($ttl > 0) {
            $this->cacheComponent->set($cacheKey, $this->accessToken, $ttl);
        }

        return $this->accessToken->token;
    }

    private function cacheKey(): string
    {
        return implode(':', [
            $this->cacheKeyPrefix,
            getenv('AZURE_TENANT_ID') ?: '',
            getenv('AZURE_CLIENT_ID') ?: '',
            $this->scope,
        ]);
    }

    private function isFresh(AccessToken $token): bool
    {
        return time() < $token->expiresOn->getTimestamp() - $this->expiryLeeway;
    }

    /**
     * @throws InvalidConfigException
     */
    private function resolveCache(): CacheInterface
    {
        if (false === $this->cache) {
            return new DummyCache();
        }

        // Fall back to in-memory caching when the default `cache` component is not configured.
        if ('cache' === $this->cache && (!\Yii::$app instanceof Application || !\Yii::$app->has('cache'))) {
            return new DummyCache();
        }

        return Instance::ensure($this->cache, CacheInterface::class);
    }
}
