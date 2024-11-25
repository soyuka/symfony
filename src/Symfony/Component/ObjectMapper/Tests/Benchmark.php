<?php

namespace Symfony\Component\ObjectMapper\Tests;

use AutoMapper\AutoMapper;
use AutoMapper\Configuration;
use AutoMapper\Loader\FileReloadStrategy;
use Symfony\Component\ObjectMapper\ObjectMapper;
use Symfony\Component\ObjectMapper\Tests\Fixtures\C;
use Symfony\Component\ObjectMapper\Tests\Fixtures\D;

class Benchmark
{
    public function benchAutoMapper(): void
    {

        $automapper = AutoMapper::create(new Configuration(reloadStrategy: FileReloadStrategy::NEVER), cacheDirectory: './cache');
        $source = new C('a', 'b');
        $automapper->map($source, D::class);
    }

    public function benchObjectMapper(): void
    {

        $automapper = new ObjectMapper();
        $source = new C('a', 'b');
        $automapper->map($source, D::class);
    }
}
