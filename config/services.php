<?php

declare(strict_types=1);

use Stewart\Store\Redis\RedisStoreBackendFactory;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

return static function (ContainerConfigurator $container): void {
    $container->services()->set(RedisStoreBackendFactory::class)->autowire()->tag('stewart.store_backend');
};
