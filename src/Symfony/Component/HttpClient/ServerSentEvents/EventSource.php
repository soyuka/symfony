<?php

namespace Symfony\Component\HttpClient\ServerSentEvents;

use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\HttpClient\PluggableHttpClient;
use Symfony\Component\HttpClient\Response\PluggableResponse;
use Symfony\Component\HttpClient\HttpClientTrait;
use Symfony\Contracts\HttpClient\ChunkInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Symfony\Contracts\HttpClient\ResponseStreamInterface;

final class EventSource implements HttpClientInterface
{
    private $client;
    private $lastEventId;
    private $reconnectionTime;

    public function __construct(HttpClientInterface $client = null, array $defaultOptions = [])
    {
        $client = $client ?? HttpClient::create();
        $self = $this;
        $this->client = new PluggableHttpClient($client, function (HttpClientInterface $client, string $method, string $url, array $options) use ($self) {
          $bomRemoved = false;
          $buffer = '';
          $content = '';

          return new PluggableResponse($client, $method, $url, $options, function (ChunkInterface $chunk) use ($content, $buffer, $bomRemoved, $self) {
              if ($chunk->isTimeout() || $chunk->isFirst() || $chunk->isLast() || !$chunk->getContent()) {
                  yield $chunk;
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
                          $buffer = preg_replace('/\x{FEFF}/u', '', $buffer);
                          $bomRemoved = true;
                      }

                      if (!$buffer || 0 === strpos($buffer, ':')) {
                          $buffer = '';
                          continue;
                      }

                      $message = MessageEvent::parse($buffer);
                      $buffer = '';

                      if (null !== $message->getId()) {
                          $self->lastEventId = $message->getId();
                      }

                      if (null !== $message->getRetry()) {
                          $self->reconnectionTime = $message->retry;
                      }

                      yield $message;
                      continue;
                  }

                  $buffer .= $line."\n";
              }

              // keep the current non-processed content
              $content = $buffer;
          });
        });
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
}
