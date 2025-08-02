<?php

namespace Symfony\Component\ObjectMapper\Tests\Fixtures\ConstructorCalled;

use Symfony\Component\ObjectMapper\Attribute\Map;

#[Map(target: TargetWithConstructor::class)]
class SourceConstructor
{
}
