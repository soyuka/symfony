<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Contracts\HttpClient\ServerSentEvents;

/**
 * Todo: documentation.
 *
 * @author Antoine Bluchet
 */
interface MessageEventInterface
{
    public function getId(): string;

    public function getType(): string;

    public function getData(): string;

    public function getRetry(): int;
}
