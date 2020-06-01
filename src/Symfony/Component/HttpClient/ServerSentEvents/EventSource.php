<?php

namespace Symfony\Component\HttpClient\ServerSentEvents;

use Symfony\Component\HttpClient\AsyncDecoratorTrait;
use Symfony\Component\HttpClient\Response\AsyncContext;
use Symfony\Component\HttpClient\Response\AsyncResponse;
use Symfony\Contracts\HttpClient\ChunkInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\ResponseInterface;

final class EventSource implements HttpClientInterface
{
    use AsyncDecoratorTrait;

    private $reconnectionTime;

    public function __construct(HttpClientInterface $client = null, $reconnectionTime = 2) {
        $this->client = $client ?: HttpClient::create();
        $this->reconnectionTime = 2;
    }

    public function request(string $method, string $url, array $options = []): ResponseInterface
    {
      $content = '';
      $buffer = '';
      $bomRemoved = false;
      $lastEventId = null;
      $reconnectionTime = $this->reconnectionTime;

      $options['headers'] = array_merge($options['headers'] ?? [], $this->getHeaders());
      $options['timeout'] = $reconnectionTime;

      return new AsyncResponse($this->client, $method, $url, $options, static function (ChunkInterface $chunk, AsyncContext $context) use ($content, $buffer, $bomRemoved, $lastEventId, $reconnectionTime) {
        if ($chunk->isTimeout()) {
          // if (!$context->getInfo('canceled')) {
          //   $context->replaceRequest($context->getInfo('http_method'), $context->getInfo('url'), ['headers' => []]);
          // }
          yield $chunk;
          return;
        }

        if ($chunk->isFirst()) {
          yield $chunk;
          return;
        }

        // connection closed
        if ($chunk->isLast()) {
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
              if (false !== $pos = strpos($buffer, "\u{FEFF}"));
              $buffer = substr($buffer, $pos, 1);
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
}
