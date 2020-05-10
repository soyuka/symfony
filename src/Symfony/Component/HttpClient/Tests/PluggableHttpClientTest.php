<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\HttpClient\Tests;

use Symfony\Component\HttpClient\NativeHttpClient;
use Symfony\Component\HttpClient\PluggableHttpClient;
use Symfony\Component\HttpClient\Response\PluggableResponse;
use Symfony\Contracts\HttpClient\ChunkInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class PluggableHttpClientTest extends HttpClientTestCase
{
    protected function getHttpClient(string $testCase): HttpClientInterface
    {
        return new PluggableHttpClient(new NativeHttpClient(), function (NativeHttpClient $client, string $method, string $url, array $options) {
            return new PluggableResponse($client, $method, $url, $options, function (ChunkInterface $chunk) {
                yield $chunk;
            });
        });
    }

    public function testInformationalResponseStream()
    {
        $this->markTestSkipped('NativeHttpClient doesn\'t support informational status codes.');
    }

    public function testHttp2PushVulcain()
    {
        $this->markTestSkipped('NativeHttpClient doesn\'t support HTTP/2.');
    }

    public function testHttp2PushVulcainWithUnusedResponse()
    {
        $this->markTestSkipped('NativeHttpClient doesn\'t support HTTP/2.');
    }
}
