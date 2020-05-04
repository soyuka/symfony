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

require __DIR__.'/../../../../../../vendor/autoload.php';

use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Chunk\DataChunk;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpClient\ServerSentEvents\EventSource;
use Symfony\Component\HttpClient\ServerSentEvents\MessageEvent;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseStreamInterface;

class EventSourceTest extends TestCase
{
    public function testMessageClient()
    {
        $chunk = $this->createMock(DataChunk::class);
        $chunk->method('getContent')->willReturn(<<<TXT
:ok

event: builderror
id: 46
data: {"foo": "bar"}

event: reload
id: 47
data: {}

event: reload
id: 48
data: {}

data: test
data:test
id: 49
event: testEvent


id: 50
data: <tag>
data
data:   <foo />
data
data: </tag>

id: 60
data
TXT);
        $response = new MockResponse('', ['canceled' => false, 'http_method' => 'GET', 'url' => 'http://localhost:8080/events']);
        $canceledResponse = new MockResponse('', ['canceled' => true, 'http_method' => 'GET', 'url' => 'http://localhost:8080/events']);
        $stream = new \ArrayIterator([$chunk]);
        $responseStream = $this->createMock(ResponseStreamInterface::class);
        $responseStream->method('next')->willReturnCallback(function () use ($stream) { return $stream->next(); });
        $responseStream->method('current')->willReturnCallback(function () use ($stream) { return $stream->current(); });
        $responseStream->method('valid')->willReturnCallback(function () use ($stream) { return $stream->valid(); });
        $responseStream->method('rewind')->willReturnCallback(function () use ($stream) { return $stream->rewind(); });
        $responseStream->method('key')->willReturnCallback(function () use ($response) { return $response; });

        $httpClient = $this->createMock(HttpClientInterface::class);
        $httpClient->expects($this->exactly(2))->method('request')

                   ->withConsecutive(
                       ['GET', 'http://localhost:8080/events', ['headers' => ['Accept' => 'text/event-stream', 'Cache-Control' => 'no-store']]],
                       ['GET', 'http://localhost:8080/events', ['headers' => ['Accept' => 'text/event-stream', 'Cache-Control' => 'no-store', 'Last-Event-ID' => '50']]],
                   )->will($this->onConsecutiveCalls($response, $canceledResponse));

        $httpClient->method('stream')->willReturn($responseStream);

        $es = new EventSource($httpClient);
        $res = $es->request('GET', 'http://localhost:8080/events');

        $expected = [
            new MessageEvent('{"foo": "bar"}', '46', 'builderror'),
            new MessageEvent('{}', '47', 'reload'),
            new MessageEvent('{}', '48', 'reload'),
            new MessageEvent("test\ntest", '49', 'testEvent'),
            new MessageEvent("<tag>\n\n  <foo />\n\n</tag>", '50', 'message'),
        ];
        $i = 0;

        foreach ($es->messages($res) as $message) {
            $this->assertEquals($expected[$i++], $message);
        }
    }
}
