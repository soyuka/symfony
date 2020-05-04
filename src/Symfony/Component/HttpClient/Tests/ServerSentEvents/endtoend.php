<?php

namespace Symfony\Component\HttpClient\Tests;

require __DIR__.'/../../../../../../vendor/autoload.php';

use Exception;
use Symfony\Component\HttpClient\EventSourceHttpClient;
use Symfony\Contracts\HttpClient\ServerSentEvents\MessageEventInterface;

$client = new EventSourceHttpClient();
$response = $client->connect('GET', 'http://localhost:8080/events');
foreach ($client->stream($response) as $chunk) {
    if (!$chunk instanceof MessageEventInterface) {
        continue;
    }

    var_dump($chunk);
}
