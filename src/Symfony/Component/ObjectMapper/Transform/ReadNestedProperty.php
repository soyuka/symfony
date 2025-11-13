<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\ObjectMapper\Transform;

use Symfony\Component\ObjectMapper\TransformCallableInterface;

/**
 * @template T of object
 *
 * @implements TransformCallableInterface<object, T>
 */
class ReadNestedProperty implements TransformCallableInterface
{
    public function __construct(
        private readonly string $property,
    ) {
    }

    public function __invoke(mixed $value, object $source, ?object $target): mixed
    {
        return $value->{$this->property};
    }
}
