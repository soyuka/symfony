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

use Symfony\Component\HttpClient\AsyncDecoratorTrait;
use Symfony\Component\HttpClient\Response\AsyncContext;
use Symfony\Component\HttpClient\Response\AsyncResponse;
use Symfony\Contracts\HttpClient\ChunkInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

class AsyncDecoratorTraitTest extends NativeHttpClientTest
{
    protected function getHttpClient(string $testCase, \Closure $chunkFilter = null): HttpClientInterface
    {
        $chunkFilter = $chunkFilter ?? static function (ChunkInterface $chunk, AsyncContext $context) { yield $chunk; };

        return new class(parent::getHttpClient($testCase), $chunkFilter) implements HttpClientInterface {
            use AsyncDecoratorTrait;

            private $chunkFilter;

            public function __construct(HttpClientInterface $client, \Closure $chunkFilter = null)
            {
                $this->chunkFilter = $chunkFilter;
                $this->client = $client;
            }

            public function request(string $method, string $url, array $options = []): ResponseInterface
            {
                return new AsyncResponse($this->client, $method, $url, $options, $this->chunkFilter);
            }
        };
    }

    public function testRetry404()
    {
        $client = $this->getHttpClient(__FUNCTION__, function (ChunkInterface $chunk, AsyncContext $context) {
            $this->assertTrue($chunk->isFirst());
            $this->assertSame(404, $context->getStatusCode());
            $context->getResponse()->cancel();
            $context->replaceRequest('GET', 'http://localhost:8057/');
            $context->passthru();
        });

        $response = $client->request('GET', 'http://localhost:8057/404');

        foreach ($client->stream($response) as $chunk) {
        }
        $this->assertTrue($chunk->isLast());
        $this->assertSame(200, $response->getStatusCode());
    }

    public function testRetryTransportError()
    {
        $client = $this->getHttpClient(__FUNCTION__, function (ChunkInterface $chunk, AsyncContext $context) {
            try {
                if ($chunk->isFirst()) {
                    $this->assertSame(200, $context->getStatusCode());
                }

                yield $chunk;
            } catch (TransportExceptionInterface $e) {
                $context->getResponse()->cancel();
                $context->replaceRequest('GET', 'http://localhost:8057/');
            }
        });

        $response = $client->request('GET', 'http://localhost:8057/chunked-broken');

        $this->assertSame(200, $response->getStatusCode());
    }

    public function testJsonTransclusion()
    {
        $client = $this->getHttpClient(__FUNCTION__, function (ChunkInterface $chunk, AsyncContext $context) {
            if ('' === $content = $chunk->getContent()) {
                yield $chunk;

                return;
            }

            $this->assertSame('{"documents":[{"id":"\/json\/1"},{"id":"\/json\/2"},{"id":"\/json\/3"}]}', $content);

            $steps = preg_split('{\{"id":"\\\/json\\\/(\d)"\}}', $content, -1, PREG_SPLIT_DELIM_CAPTURE);
            $steps[7] = $context->getResponse();
            $steps[1] = $context->replaceRequest('GET', 'http://localhost:8057/json/1');
            $steps[3] = $context->replaceRequest('GET', 'http://localhost:8057/json/2');
            $steps[5] = $context->replaceRequest('GET', 'http://localhost:8057/json/3');

            yield $context->createChunk(array_shift($steps));

            $context->replaceResponse(array_shift($steps));
            $context->passthru(static function (ChunkInterface $chunk, AsyncContext $context) use (&$steps) {
                if ($chunk->isFirst()) {
                    return;
                }

                if ($steps && $chunk->isLast()) {
                    $chunk = $context->createChunk(array_shift($steps));
                    $context->replaceResponse(array_shift($steps));
                }

                yield $chunk;
            });
        });

        $response = $client->request('GET', 'http://localhost:8057/json');

        $this->assertSame('{"documents":[{"title":"\/json\/1"},{"title":"\/json\/2"},{"title":"\/json\/3"}]}', $response->getContent());
    }

    public function testPreflightRequest()
    {
        $client = new class(parent::getHttpClient(__FUNCTION__)) implements HttpClientInterface {
            use AsyncDecoratorTrait;

            public function request(string $method, string $url, array $options = []): ResponseInterface
            {
                $chunkFilter = static function (ChunkInterface $chunk, AsyncContext $context) use ($method, $url, $options) {
                    $context->replaceRequest($method, $url, $options);
                    $context->passthru();
                };

                return new AsyncResponse($this->client, 'GET', 'http://localhost:8057', $options, $chunkFilter);
            }
        };

        $response = $client->request('GET', 'http://localhost:8057/json');

        $this->assertSame('{"documents":[{"id":"\/json\/1"},{"id":"\/json\/2"},{"id":"\/json\/3"}]}', $response->getContent());
        $this->assertSame('http://localhost:8057/', $response->getInfo('previous_info')[0]['url']);
    }

    public function testProcessingHappensOnce()
    {
        $lastChunks = 0;
        $client = $this->getHttpClient(__FUNCTION__, function (ChunkInterface $chunk, AsyncContext $context) use (&$lastChunks) {
            $lastChunks += $chunk->isLast();

            yield $chunk;
        });

        $response = $client->request('GET', 'http://localhost:8057/');

        foreach ($client->stream($response) as $chunk) {
        }
        $this->assertTrue($chunk->isLast());
        $this->assertSame(1, $lastChunks);

        $chunk = null;
        foreach ($client->stream($response) as $chunk) {
        }
        $this->assertTrue($chunk->isLast());
        $this->assertSame(1, $lastChunks);
    }
}
