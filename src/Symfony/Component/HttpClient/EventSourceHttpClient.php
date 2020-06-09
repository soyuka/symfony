<?php

namespace Symfony\Component\HttpClient;

use Symfony\Component\HttpClient\AsyncDecoratorTrait;
use Symfony\Component\HttpClient\Exception\EventSourceException;
use Symfony\Component\HttpClient\Chunk\ServerSentEvent;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\HttpClient\HttpClientTrait;
use Symfony\Component\HttpClient\Response\AsyncContext;
use Symfony\Component\HttpClient\Response\AsyncResponse;
use Symfony\Contracts\HttpClient\ChunkInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

final class EventSourceHttpClient implements HttpClientInterface
{
    use AsyncDecoratorTrait;
    use HttpClientTrait;

    private $reconnectionTime;

    public function __construct(HttpClientInterface $client = null, float $reconnectionTime = 2.0)
    {
        $this->client = $client ?: HttpClient::create();
        $this->reconnectionTime = $reconnectionTime;
    }

    public function connect($method, string $url, array $options = []): ResponseInterface
    {
        return $this->request($method, $url, self::mergeDefaultOptions($options, [
            'buffer' => false,
            'headers' => [
                'Accept' => 'text/event-stream',
                'Cache-Control' => 'no-cache',
            ]
        ], true));
    }

    public function request(string $method, string $url, array $options = []): ResponseInterface
    {
        $state = new class() {
            public $buffer = null;
            public $lastEventId = null;
            public $reconnectionTime;
            public $lastError = null;
        };

        $state->reconnectionTime = $this->reconnectionTime;
        $state->buffer = ['text/event-stream'] === (self::normalizeHeaders($options['headers'] ?? [])['accept'] ?? []) ? '' : null;

        return new AsyncResponse($this->client, $method, $url, $options, static function (ChunkInterface $chunk, AsyncContext $context) use ($state, $method, $url, $options) {
            if (null !== $state->buffer) {
                $context->setInfo('reconnection_time', $state->reconnectionTime);
                $isTimeout = false;
            }

            try {
                $isTimeout = $chunk->isTimeout();

                if ($chunk->isLast()) {
                    yield $chunk;

                    return;
                }
            } catch (TransportExceptionInterface $e) {
                $state->lastError ?? $state->lastError = microtime(true);

                if (null === $state->buffer || ($isTimeout && microtime(true) - $state->lastError < $state->reconnectionTime)) {
                    yield $chunk;
                } else {
                    $options['headers']['Last-Event-ID'] = $state->lastEventId;
                    $state->buffer = '';
                    $state->lastError = microtime(true);
                    $context->getResponse()->cancel();
                    $context->replaceRequest($method, $url, $options);
                    $context->pause($state->reconnectionTime);
                }

                return;
            }

            if ($chunk->isFirst()) {
                if (0 === strpos($context->getHeaders()['content-type'][0] ?? null, 'text/event-stream')) {
                    $state->buffer = '';
                } elseif (null !== $state->lastError || (null !== $state->buffer && 200 === $context->getStatusCode())) {
                    throw new EventSourceException(sprintf('Response content-type is "%s" while "text/event-stream" was expected for "%s".', $context->getHeaders()['content-type'][0] ?? '', $context->getInfo('url')));
                } else {
                    $context->passthru();
                }

                if (null === $state->lastError) {
                    yield $chunk;
                }

                $state->lastError = null;
                return;
            }

            $content = $state->buffer.$chunk->getContent();
            $events = preg_split("/(?:\r\n|[\r\n]){2,}/", $content);
            $state->buffer = array_pop($events);

            foreach ($events as $event) {
                if (0 === strpos($event, "\xEF\xBB\xBF")) {
                    $event = substr($event, 3);
                }

                if ('' === $event || 0 === strpos($event, ':')) {
                    continue;
                }

                $event = new ServerSentEvent($event);

                if ('' !== $event->getId()) {
                    $context->setInfo('last_event_id', $state->lastEventId = $event->getId());
                }

                if ($event->getRetry()) {
                    $context->setInfo('reconnection_time', $state->reconnectionTime = $event->getRetry());
                }

                yield $event;
            }
        });
    }
}
