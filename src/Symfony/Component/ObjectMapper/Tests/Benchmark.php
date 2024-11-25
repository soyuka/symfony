<?php

namespace Symfony\Component\ObjectMapper\Tests;

use AutoMapper\AutoMapper;
use AutoMapper\Configuration;
use AutoMapper\Loader\FileReloadStrategy;
use Symfony\Component\ObjectMapper\ObjectMapper;
use Symfony\Component\ObjectMapper\Tests\Fixtures\C;
use Symfony\Component\ObjectMapper\Tests\Fixtures\D;

/**
 * @AfterMethods({"after"})
 */
class Benchmark
{
    private AutoMapper $automapper;
    private ObjectMapper $objectMapper;

    public function after()
    {
        unset($this->automapper);
        unset($this->objectMapper);
    }

    public function benchAutoMapper(): void
    {
        if (!isset($this->automapper)) {
            $this->automapper = AutoMapper::create(cacheDirectory: './cache');
        }

        $source = new C('a', 'b');
        $this->automapper->map($source, D::class);
    }

    public function benchObjectMapper(): void
    {
        if (!isset($this->objectMapper)) {
            $this->objectMapper = new ObjectMapper();
        }

        $source = new C('a', 'b');
        $this->objectMapper->map($source, D::class);
    }
}
