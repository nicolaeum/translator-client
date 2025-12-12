<?php

namespace Headwires\TranslatorClient\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use Headwires\TranslatorClient\TranslatorClientServiceProvider;

class TestCase extends Orchestra
{
    protected function getPackageProviders($app)
    {
        return [
            TranslatorClientServiceProvider::class,
        ];
    }

    protected function getEnvironmentSetUp($app)
    {
        // Setup default configuration
        config()->set('translator-client.api_key', 'test-api-key');
        config()->set('translator-client.cdn_url', 'https://cdn.test.com');
        config()->set('translator-client.metadata_path', sys_get_temp_dir() . '/translator-client');
    }
}
