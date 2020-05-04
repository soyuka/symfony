<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\HttpClient\Tests;

use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\ServerSentEvents\MessageEvent;

class MessageEventTest extends TestCase
{
    public function testParse()
    {
        $rawData = <<<STR
data: test
data:test
id: 12
event: testEvent

STR;
        $this->assertEquals(new MessageEvent("test\ntest", 12, 'testEvent'), MessageEvent::parse($rawData));
    }

    public function testParseValid()
    {
        $rawData = <<<STR
event: testEvent
data

STR;
        $this->assertEquals(new MessageEvent('', null, 'testEvent'), MessageEvent::parse($rawData));
    }

    public function testParseRetry()
    {
        $rawData = <<<STR
retry: 12
STR;
        $this->assertEquals(new MessageEvent('', null, 'message', 12), MessageEvent::parse($rawData));
    }

    public function testParseNewLine()
    {
        $rawData = <<<STR


data: <tag>
data
data:   <foo />
data
data: </tag>
STR;
        $this->assertEquals(new MessageEvent("<tag>\n\n  <foo />\n\n</tag>"), MessageEvent::parse($rawData));
    }
}
