<?php

namespace Osiset\ShopifyApp\Data;

/**
 * Optional per-request signing credentials (e.g. one-off recovery); not persisted.
 */
readonly class ApplicationOnlyAccessCredentials
{
    public function __construct(
        public string $keyId,
        public string $privateKeyPem,
    ) {
    }
}
