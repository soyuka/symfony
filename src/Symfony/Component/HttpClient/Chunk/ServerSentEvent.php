<?php

namespace Symfony\Component\HttpClient\Chunk;

use Symfony\Component\HttpClient\Chunk\DataChunk;
use Symfony\Contracts\HttpClient\ChunkInterface;
use Symfony\Contracts\HttpClient\ServerSentEvents\MessageEventInterface;

final class ServerSentEvent extends DataChunk implements ChunkInterface, MessageEventInterface
{
    private $rawData;
    private $data = '';
    private $id = '';
    private $type = 'message';
    private $retry = 0;

    public function __construct(string $rawData)
    {
        $this->rawData = preg_split("/(?:\r\n|[\r\n])/", $rawData);

        foreach ($this->rawData as $line) {
            if (\in_array($i = strpos($line, ':'), [false, 0, \strlen($line) - 1], true)) {
                continue;
            }

            $field = substr($line, 0, $i);
            $i += 1 + (' ' === $line[1 + $i]);

            switch ($field) {
                case 'id': $this->id = substr($line, $i); break;
                case 'event': $this->type = substr($line, $i); break;
                case 'data': $this->data = substr($line, $i); break;
                case 'retry':
                    $retry = substr($line, $i);

                    if ('' !== $retry && \strlen($retry) === strspn($retry, '0123456789')) {
                        $this->retry = $retry / 1000.0;
                    }
                    break;
            }
        }
    }

    public function getRawData(): array
    {
        return $this->rawData;
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function getData(): string
    {
        return $this->data;
    }

    public function getRetry(): float
    {
        return $this->retry;
    }
}
