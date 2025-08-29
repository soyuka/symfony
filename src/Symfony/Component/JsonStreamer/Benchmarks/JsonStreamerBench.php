<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\JsonStreamer\Benchmarks;

use PhpBench\Attributes as Bench;
use Symfony\Component\JsonStreamer\JsonStreamWriter;
use Symfony\Component\Serializer\Encoder\JsonEncoder;
use Symfony\Component\Serializer\Normalizer\ObjectNormalizer;
use Symfony\Component\Serializer\Serializer;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\TypeInfo\Type;

#[Bench\BeforeMethods('setUp')]
class JsonStreamerBench
{
    private JsonStreamWriter $jsonStreamWriter;
    private SerializerInterface $serializer;
    private DataObject $dataObject;
    private Type $dataObjectType;

    public function setUp(): void
    {
        $this->jsonStreamWriter = JsonStreamWriter::create();

        $encoders = [new JsonEncoder()];
        $normalizers = [new ObjectNormalizer()];
        $this->serializer = new Serializer($normalizers, $encoders);

        $this->dataObject = new DataObject(
            'test string',
            123,
            123.45,
            true,
            ['a' => 1, 'b' => 'two'],
            new NestedObject('nested value'),
            new \DateTimeImmutable('2025-01-01 10:00:00')
        );
        $this->dataObjectType = Type::object(DataObject::class);
    }

    #[Bench\Revs(100)]
    #[Bench\Iterations(5)]
    public function benchJsonStreamWriter(): void
    {
        $v = (string) $this->jsonStreamWriter->write($this->dataObject, $this->dataObjectType);
    }

    #[Bench\Revs(100)]
    #[Bench\Iterations(5)]
    public function benchSymfonySerializer(): void
    {
        $v = $this->serializer->serialize($this->dataObject, 'json');
    }
}

class DataObject
{
    public function __construct(
        public string $stringProperty,
        public int $intProperty,
        public float $floatProperty,
        public bool $boolProperty,
        public array $arrayProperty,
        public NestedObject $objectProperty,
        public \DateTimeImmutable $dateTimeProperty,
    ) {
    }
}

class NestedObject
{
    public function __construct(
        public string $nestedString,
    ) {
    }
}
