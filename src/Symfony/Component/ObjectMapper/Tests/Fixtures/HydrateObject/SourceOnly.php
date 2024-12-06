<?php

namespace Symfony\Component\ObjectMapper\Tests\Fixtures\HydrateObject;

use Symfony\Component\ObjectMapper\Attribute\Map;

class SourceOnly
{
    public function __construct(#[Map(source: 'name')] public string $mappedName) {}
}
