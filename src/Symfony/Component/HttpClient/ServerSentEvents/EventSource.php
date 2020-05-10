<?php

namespace Symfony\Component\HttpClient\ServerSentEvents;

use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\HttpClient\HttpClientTrait;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Symfony\Contracts\HttpClient\ResponseStreamInterface;

final class EventSource implements HttpClientInterface
{
    use HttpClientTrait;

    private $client;
    private $lastEventId = null;
    private $reconnectionTime = 10;

    public function __construct(HttpClientInterface $client = null, array $defaultOptions = [])
    {
        $this->client = $client ?? HttpClient::create($defaultOptions);
    }

    public function stream($responses, float $timeout = null): ResponseStreamInterface
    {
        return $this->client->stream($responses, $timeout);
    }

    public function request(string $method, string $url, array $options = []): ResponseInterface
    {
        $options['headers'] = array_merge($options['headers'] ?? [], [
            'Accept' => 'text/event-stream',
            'Cache-Control' => 'no-store',
        ]);

        if ($this->lastEventId) {
            $options['headers']['Last-Event-ID'] = $this->lastEventId;
        }

        return $this->client->request($method, $url, $options);
    }

    public function messages($response): \Iterator
    {
        while (true !== $response->getInfo('canceled')) {
            $bomRemoved = false;
            $buffer = '';
            $content = '';
            foreach ($this->client->stream($response, $this->reconnectionTime) as $chunk) {
                if ($chunk->isTimeout()) {
                    yield $chunk;
                    break;
                }

                if ($chunk->isFirst()) {
                    yield $chunk;
                    continue;
                }

                // connection closed
                if ($chunk->isLast() || !$chunk->getContent()) {
                    yield $chunk;

                    return null;
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
                            $this->lastEventId = $message->getId();
                        }

                        if (null !== $message->getRetry()) {
                            $this->reconnectionTime = $message->retry;
                        }

                        yield $message;

                        continue;
                    }

                    $buffer .= $line."\n";
                }

                // keep the current non-processed content
                $content = $buffer;
            }

						usleep($this->reconnectionTime * 1000);
            $response = $this->request($response->getInfo('http_method'), $response->getInfo('url'));
        }
    }
}
