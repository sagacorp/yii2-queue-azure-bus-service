<?php

namespace sagacorp\queue\azure;

interface AzureJobInterface
{
    public function getQueue(): ?string;
}
