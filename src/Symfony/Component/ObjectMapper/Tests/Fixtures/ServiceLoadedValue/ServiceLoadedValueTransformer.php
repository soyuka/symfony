<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\ObjectMapper\Tests\Fixtures\ServiceLoadedValue;

use Symfony\Component\ObjectMapper\TransformCallableInterface;

/**
 * @implements TransformCallableInterface<object,object>
 */
class ServiceLoadedValueTransformer implements TransformCallableInterface
{
    public function __construct(private readonly LoadedValueService $serviceLoadedValue)
    {
    }

    public function __invoke(mixed $value, object $source, ?object $target): mixed
    {
        if (!$target) {
            throw new \RuntimeException();
        }

        return $this->serviceLoadedValue->get();
    }
}
