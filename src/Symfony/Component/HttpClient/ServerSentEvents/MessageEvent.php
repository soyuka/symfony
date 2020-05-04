<?php

namespace Symfony\Component\HttpClient\ServerSentEvents;

use Symfony\Component\HttpClient\Chunk\DataChunk;
use Symfony\Contracts\HttpClient\ChunkInterface;
use Symfony\Contracts\HttpClient\ServerSentEvents\MessageEventInterface;

final class MessageEvent extends DataChunk implements ChunkInterface, MessageEventInterface
{
    private $data;
    private $id;
    private $type;
    private $retry;

    public function __construct(string $data = '', string $id = null, string $type = 'message', int $retry = null)
    {
        $this->data = $data;
        $this->id = $id;
        $this->type = $type;
        $this->retry = $retry;
    }

    public static function parse(string $rawData): self
    {
        preg_match_all('/^([a-z]*)\:? ?(.*)/m', $rawData, $matches, PREG_SET_ORDER);
        $data = '';
        $retry = $id = null;
        $type = 'message';

        foreach ($matches as $match) {
            switch ($match[1]) {
                case 'id':
                    $id = $match[2];
                    break;
                case 'event':
                    $type = $match[2];
                    break;
                case 'data':
                    $data .= $match[2]."\n";
                    break;
                case 'retry':
                    $retry = ctype_digit($match[2]) ? (int) $match[2] : null;
                    break;
            }
        }

        if ("\n" === substr($data, -1)) {
            $data = substr($data, 0, -1);
        }

        return new self($data, $id, $type, $retry);
    }

    public function getId(): ?string
    {
        return $this->id;
    }

    public function getType(): ?string
    {
        return $this->type;
    }

    public function getData(): string
    {
        return $this->data;
    }

    public function getRetry(): ?int
    {
        return $this->retry;
    }
}
