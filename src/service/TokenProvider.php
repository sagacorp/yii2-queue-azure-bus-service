<?php

declare(strict_types=1);

namespace sagacorp\queue\azure\service;

interface TokenProvider
{
    /**
     * Build the value of the "Authorization" header for the given request URL.
     *
     * @param string $url the full URL of the request being authenticated
     */
    public function getAuthorizationHeader(string $url): string;
}
