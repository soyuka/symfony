<?php

namespace Symfony\Component\HttpClient\ServerSentEvents;

use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\HttpClient\HttpClientTrait;
use Symfony\Component\HttpClient\PluggableHttpClient;
use Symfony\Component\HttpClient\Response\PluggableContext;
use Symfony\Component\HttpClient\Response\PluggableResponse;
use Symfony\Contracts\HttpClient\ChunkInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Symfony\Contracts\HttpClient\ResponseStreamInterface;

final class EventSource implements HttpClientInterface
{
    use HttpClientTrait;

    private $client;

    public function __construct(HttpClientInterface $client = null, array $defaultOptions = [])
    {
        if (null === $client) {
            $client = HttpClient::create($defaultOptions);
        }

        $this->client = new PluggableHttpClient($client, [$this, 'pluggableResponseFactory']);
    }

    public function pluggableResponseFactory(HttpClientInterface $client, string $method, string $url, array $options): PluggableResponse
    {
        $content = '';
        $buffer = '';
        $bomRemoved = false;
        $lastEventId = null;
        $reconnectionTime = 10;

        return new PluggableResponse($client, $method, $url, $options, function (ChunkInterface $chunk, PluggableContext $context) use ($content, $buffer, $bomRemoved, $lastEventId, $reconnectionTime) {
            if ($chunk->isTimeout()) {
                yield $chunk;

                return;
            }

            if ($chunk->isFirst()) {
                yield $chunk;

                return;
            }

            // connection closed
            if ($chunk->isLast() || !$chunk->getContent()) {
                yield $chunk;
                if (false === $context->getInfo('canceled')) {
                    $context->replaceRequest($context->getInfo('http_method'), $context->getInfo('url'), ['headers' => $this->getHeaders()]);
                }

                return;
            }

            $content .= $chunk->getContent();
            $lines = preg_split("/\n|\r|\r\n/", $content);
            $buffer = '';

            foreach ($lines as $line) {
                // end-of-line, parse full buffer
                if ('' === $line) {
                    if (!$bomRemoved) {
                        // replace BOM if it exists
                        $buffer = preg_replace('/^\x{FEFF}/u', '', $buffer);
                        $bomRemoved = true;
                    }

                    if ('' === $buffer || 0 === strpos($buffer, ':')) {
                        $buffer = '';
                        continue;
                    }

                    $message = MessageEvent::parse($buffer);
                    $buffer = '';

                    if (null !== $message->getId()) {
                        $lastEventId = $message->getId();
                    }

                    if (0 !== $message->getRetry()) {
                        $reconnectionTime = $message->retry;
                    }

                    yield $message;

                    continue;
                }

                $buffer .= $line."\n";
            }

            // keep the current non-processed content
            $content = $buffer;
        });
    }

    private function getHeaders($lastEventId = null): array
    {
        $headers = [
            'Accept' => 'text/event-stream',
            'Cache-Control' => 'no-store',
        ];

        if ($lastEventId) {
            $headers['Last-Event-ID'] = $lastEventId;
        }

        return $headers;
    }

    public function stream($responses, float $timeout = null): ResponseStreamInterface
    {
        return $this->client->stream($responses, $timeout);
    }

    public function request(string $method, string $url, array $options = []): ResponseInterface
    {
        $options['headers'] = array_merge($options['headers'] ?? [], $this->getHeaders());

        return $this->client->request($method, $url, $options);
    }
}
