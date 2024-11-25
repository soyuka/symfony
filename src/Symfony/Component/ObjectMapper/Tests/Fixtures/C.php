<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\ObjectMapper\Tests\Fixtures;

use AutoMapper\Attribute\MapTo;
use Symfony\Component\ObjectMapper\Attribute\Map;

#[Map(D::class)]
class C
{
    public function __construct(#[Map('baz'), MapTo(property: 'baz')] public readonly string $foo, #[Map('bat'), MapTo(property: 'bat')] public readonly string $bar)
    {
    }
}
