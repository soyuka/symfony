<?php

namespace Symfony\Component\HttpClient\Tests;

require __DIR__.'/../../../../../../vendor/autoload.php';

use Exception;
use Symfony\Component\HttpClient\EventSourceHttpClient;
use Symfony\Contracts\HttpClient\ServerSentEvents\MessageEventInterface;

$client = new EventSourceHttpClient();
$response = $client->connect('GET', 'http://localhost:8080/events');

//while(true) {
    foreach ($client->stream($response, 0) as $chunk) {
        // if ($chunk->isLast()) break 2;
        // elseif ($chunk->isTimeout()) { 
        //     var_dump('test');
        //     usleep(500000);
        //     continue;
        // }

        if (!$chunk instanceof MessageEventInterface) {
            continue;
        }

    }
    var_dump($chunk);
// }
