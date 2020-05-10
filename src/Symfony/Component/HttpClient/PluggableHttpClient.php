<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\HttpClient;

use Symfony\Component\HttpClient\Response\PluggableResponse;
use Symfony\Component\HttpClient\Response\ResponseStream;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Symfony\Contracts\HttpClient\ResponseStreamInterface;

/**
 * Allows processing responses while streaming them.
 *
 * @author Nicolas Grekas <p@tchwork.com>
 */
class PluggableHttpClient implements HttpClientInterface
{
    private $client;
    private $pluggableResponseFactory;

    public function __construct(HttpClientInterface $client, callable $pluggableResponseFactory = null)
    {
        $this->client = $client;
        $this->pluggableResponseFactory = $pluggableResponseFactory;
    }

    /**
     * {@inheritdoc}
     */
    public function request(string $method, string $url, array $options = []): ResponseInterface
    {
        if (null === $this->pluggableResponseFactory) {
            return new PluggableResponse($this->client, $method, $url, $options);
        }

        $response = ($this->pluggableResponseFactory)($this->client, $method, $url, $options);

        if (!$response instanceof PluggableResponse) {
            throw new \TypeError(sprintf('The response factory passed to "%s" must return a "%s", "%s" found.', self::class, PluggableResponse::class, get_debug_type($response)));
        }

        return $response;
    }

    /**
     * {@inheritdoc}
     */
    public function stream($responses, float $timeout = null): ResponseStreamInterface
    {
        if ($responses instanceof PluggableResponse) {
            $responses = [$responses];
        } elseif (!is_iterable($responses)) {
            throw new \TypeError(sprintf('"%s()" expects parameter 1 to be an iterable of PluggableResponse objects, "%s" given.', __METHOD__, get_debug_type($responses)));
        }

        return new ResponseStream(PluggableResponse::stream($responses, $timeout));
    }
}
