<?php

declare(strict_types=1);

use PHPStan\DependencyInjection\NeonAdapter;

$adapter = new NeonAdapter();

$config = [];


if (PHP_VERSION_ID >= 80408) {
    $config = array_merge_recursive($config, $adapter->load(__DIR__.'/phpstan-baseline-84.neon'));
}

if (PHP_VERSION_ID < 80408) {
    $config = array_merge_recursive($config, $adapter->load(__DIR__.'/phpstan-baseline-81.neon'));
}

return $config;
