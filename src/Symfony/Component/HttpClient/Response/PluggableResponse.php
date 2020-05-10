<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\HttpClient\Response;

use Symfony\Component\HttpClient\Chunk\ErrorChunk;
use Symfony\Component\HttpClient\Exception\ClientException;
use Symfony\Component\HttpClient\Exception\JsonException;
use Symfony\Component\HttpClient\Exception\RedirectionException;
use Symfony\Component\HttpClient\Exception\ServerException;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\PluggableHttpClient;
use Symfony\Contracts\HttpClient\Exception\ClientExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\RedirectionExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\ServerExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\ChunkInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Provides a single extension point to process a response's content stream.
 *
 * @author Nicolas Grekas <p@tchwork.com>
 */
class PluggableResponse implements ResponseInterface
{
    protected $client;
    protected $response;
    protected $info = [];
    protected $offset = 0;

    private $chunkFactory;
    private $shouldBuffer;
    private $content;
    private $jsonData;

    public function __construct(HttpClientInterface $client, string $method, string $url, array $options, \Closure $chunkFactory = null)
    {
        $this->client = $client;
        $this->shouldBuffer = $options['buffer'] ?? true;
        $this->response = $client->request($method, $url, ['buffer' => false] + $options);
        $this->chunkFactory = $chunkFactory;
    }

    protected function streamChunk(ChunkInterface $chunk)
    {
        throw new \LogicException(sprintf('You must override the "%s()" method or define a chunk factory.', __METHOD__));
    }

    final public function getStatusCode(): int
    {
        if (null !== $this->shouldBuffer) {
            $this->getHeaders(false);
        }

        return $this->response->getStatusCode();
    }

    final public function getHeaders(bool $throw = true): array
    {
        if (null !== $this->shouldBuffer) {
            foreach (self::stream([$this]) as $chunk) {
                if (null === $this->shouldBuffer) {
                    break;
                }
            }
        }

        $headers = $this->response->getHeaders(false);

        if ($throw) {
            $this->checkStatusCode($this->getInfo('http_code'));
        }

        return $headers;
    }

    final public function getContent(bool $throw = true): string
    {
        if (null !== $this->shouldBuffer || $throw) {
            $this->getHeaders($throw);
        }

        if (null === $this->content) {
            $content = null;

            foreach (self::stream([$this]) as $chunk) {
                if (!$chunk->isLast()) {
                    $content .= $chunk->getContent();
                }
            }

            if (null !== $content) {
                return $content;
            }

            if ('HEAD' === $this->getInfo('http_method') || \in_array($this->getInfo('http_code'), [204, 304], true)) {
                return '';
            }

            throw new TransportException('Cannot get the content of the response twice: buffering is disabled.');
        }

        foreach (self::stream([$this]) as $chunk) {
            // Chunks are buffered in $this->content already
        }

        rewind($this->content);

        return stream_get_contents($this->content);
    }

    final public function toArray(bool $throw = true): array
    {
        if ('' === $content = $this->getContent($throw)) {
            throw new TransportException('Response body is empty.');
        }

        if (null !== $this->jsonData) {
            return $this->jsonData;
        }

        $contentType = $this->getHeaders(false)['content-type'][0] ?? 'application/json';

        if (!preg_match('/\bjson\b/i', $contentType)) {
            throw new JsonException(sprintf('Response content-type is "%s" while a JSON-compatible one was expected for "%s".', $contentType, $this->getInfo('url')));
        }

        try {
            $content = json_decode($content, true, 512, JSON_BIGINT_AS_STRING | (\PHP_VERSION_ID >= 70300 ? JSON_THROW_ON_ERROR : 0));
        } catch (\JsonException $e) {
            throw new JsonException($e->getMessage().sprintf(' for "%s".', $this->getInfo('url')), $e->getCode());
        }

        if (\PHP_VERSION_ID < 70300 && JSON_ERROR_NONE !== json_last_error()) {
            throw new JsonException(json_last_error_msg().sprintf(' for "%s".', $this->getInfo('url')), json_last_error());
        }

        if (!\is_array($content)) {
            throw new JsonException(sprintf('JSON content was expected to decode to an array, "%s" returned for "%s".', get_debug_type($content), $this->getInfo('url')));
        }

        if (null !== $this->content) {
            // Option "buffer" is true
            return $this->jsonData = $content;
        }

        return $content;
    }

    final public function cancel(): void
    {
        $this->response->cancel();
    }

    final public function getInfo(string $type = null)
    {
        if (null !== $type) {
            return $this->info[$type] ?? $this->response->getInfo($type);
        }

        return $this->info + $this->response->getInfo();
    }

    /**
     * Casts the response to a PHP stream resource.
     *
     * @return resource
     *
     * @throws TransportExceptionInterface   When a network error occurs
     * @throws RedirectionExceptionInterface On a 3xx when $throw is true and the "max_redirects" option has been reached
     * @throws ClientExceptionInterface      On a 4xx when $throw is true
     * @throws ServerExceptionInterface      On a 5xx when $throw is true
     */
    final public function toStream(bool $throw = true)
    {
        if ($throw) {
            // Ensure headers arrived
            $this->getHeaders(true);
        }

        $handle = function () {
            $h = StreamWrapper::createResource($this->response);

            return stream_get_meta_data($h)['wrapper_data']->stream_cast(STREAM_CAST_FOR_SELECT);
        };

        $stream = StreamWrapper::createResource($this);
        stream_get_meta_data($stream)['wrapper_data']
            ->bindHandles($handle, $this->content);

        return $stream;
    }

    /**
     * @internal
     */
    public static function stream(iterable $responses, float $timeout = null)
    {
        while (true) {
            $wrappedResponses = [];
            $pluggableMap = new \SplObjectStorage();
            $client = null;

            foreach ($responses as $r) {
                if (!$r instanceof self) {
                    throw new \TypeError(sprintf('"%s::stream()" expects parameter 1 to be an iterable of PluggableResponse objects, "%s" given.', PluggableHttpClient::class, get_debug_type($r)));
                }

                if (null === $client) {
                    $client = $r->client;
                } elseif ($r->client !== $client) {
                    throw new TransportException('Cannot stream PluggableResponse objects with many clients.');
                }

                if (null !== $e = $r->info['error'] ?? null) {
                    yield $r => new ErrorChunk($r->offset, new TransportException($e));
                } else {
                    $pluggableMap[$r->response] = $r;
                    $wrappedResponses[] = $r->response;
                }
            }

            foreach ($client->stream($wrappedResponses, $timeout) as $r => $chunk) {
                $response = $pluggableMap[$r];

                if ($response->chunkFactory) {
                    $chunks = ($response->chunkFactory)($chunk);
                } else {
                    $chunks = $response->streamChunk($chunk);
                }

                foreach ($chunks as $chunk) {
                    if (null !== $chunk->getError()) {
                        yield $response => $chunk;
                        continue;
                    }

                    if ($chunk->isFirst()) {
                        $e = $response->openBuffer();

                        yield $response => $chunk;

                        if (null !== $e) {
                            yield $response => new ErrorChunk($response->offset, $e);
                            break;
                        }

                        continue;
                    }

                    $content = $chunk->getContent();

                    if ('' !== $content && null !== $response->content && \strlen($content) !== fwrite($response->content, $content)) {
                        $chunk = new ErrorChunk($response->offset, new TransportException(sprintf('Failed writing %d bytes to the response buffer.', \strlen($content))));
                        $response->info['error'] = $chunk->getError();
                        $response->response->cancel();

                        yield $response => $chunk;
                        break;
                    }

                    $response->offset += \strlen($content);

                    yield $response => $chunk;
                }

                if ($response->response !== $r) {
                    $responses = [];
                    foreach ($pluggableMap as $r) {
                        $responses[] = $pluggableMap[$r];
                    }

                    continue 2;
                }
            }

            break;
        }
    }

    private function openBuffer(): ?\Throwable
    {
        $shouldBuffer = $this->shouldBuffer;
        $e = $this->shouldBuffer = null;

        if ($shouldBuffer instanceof \Closure) {
            try {
                $shouldBuffer = $shouldBuffer($this->getHeaders(false));

                if (null !== $e = $this->response->getInfo('error')) {
                    throw new TransportException($e);
                }
            } catch (\Throwable $e) {
                $this->info['error'] = $e->getMessage();
                $this->response->cancel();
            }
        }

        if (true === $shouldBuffer) {
            $this->content = fopen('php://temp', 'w+');
        } elseif (\is_resource($shouldBuffer)) {
            $this->content = $shouldBuffer;
        }

        return $e;
    }

    private function checkStatusCode($code)
    {
        if (500 <= $code) {
            throw new ServerException($this);
        }

        if (400 <= $code) {
            throw new ClientException($this);
        }

        if (300 <= $code) {
            throw new RedirectionException($this);
        }
    }
}
