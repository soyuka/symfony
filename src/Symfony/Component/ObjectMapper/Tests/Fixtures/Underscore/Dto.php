<?php

namespace Symfony\Component\ObjectMapper\Tests\Fixtures\Underscore;

use Symfony\Component\ObjectMapper\Attribute\Map;

class Dto
{
    #[Map(target: 'snake_case_property')]
    public string $camelCasedProperty;
}
