<?php
namespace ScriptFUSIONTest\Unit\Porter\Net\Http;

use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use ScriptFUSION\Porter\Net\Http\HttpConnector;

final class HttpConnectorTest extends \PHPUnit_Framework_TestCase
{
    use MockeryPHPUnitIntegration;

    public function testBaseUrl()
    {
        self::assertSame('foo', (new HttpConnector)->setBaseUrl('foo')->getBaseUrl());
    }
}
