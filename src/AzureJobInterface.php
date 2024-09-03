<?php

namespace sagacorp\queue\azure;

interface AzureJobInterface
{
    // region Getters/Setters
    public function getQueue(): ?string;
    // endregion Getters/Setters
}