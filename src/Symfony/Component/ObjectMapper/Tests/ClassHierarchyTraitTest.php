<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\ObjectMapper\Tests;

use PHPUnit\Framework\TestCase;
use Symfony\Component\ObjectMapper\ClassHierarchyTrait;
use Symfony\Component\ObjectMapper\Tests\Fixtures\PrivateParentProperty\ChildEntity;

final class ClassHierarchyTraitTest extends TestCase
{
    public function testGetAllPropertiesReturnsSameReflectionPropertyInstancesAcrossCalls(): void
    {
        $classHierarchy = $this->createClassHierarchy();
        $refl = new \ReflectionClass(ChildEntity::class);

        $first = $classHierarchy->publicGetAllProperties($refl);
        $second = $classHierarchy->publicGetAllProperties($refl);

        $this->assertSame($first, $second);
    }

    public function testGetPropertyFromHierarchyReturnsSameReflectionPropertyInstanceForInheritedPrivateProperty(): void
    {
        $classHierarchy = $this->createClassHierarchy();
        $refl = new \ReflectionClass(ChildEntity::class);

        $first = $classHierarchy->publicGetPropertyFromHierarchy($refl, 'id');
        $second = $classHierarchy->publicGetPropertyFromHierarchy($refl, 'id');

        $this->assertNotNull($first);
        $this->assertSame($first, $second);
    }

    public function testGetPropertyFromHierarchyReturnsNullTwiceForUnknownPropertyWithoutThrowing(): void
    {
        $classHierarchy = $this->createClassHierarchy();
        $refl = new \ReflectionClass(ChildEntity::class);

        $this->assertNull($classHierarchy->publicGetPropertyFromHierarchy($refl, 'doesNotExist'));
        $this->assertNull($classHierarchy->publicGetPropertyFromHierarchy($refl, 'doesNotExist'));
    }

    private function createClassHierarchy(): object
    {
        return new class {
            use ClassHierarchyTrait;

            public function publicGetAllProperties(\ReflectionClass $refl): array
            {
                return $this->getAllProperties($refl);
            }

            public function publicGetPropertyFromHierarchy(\ReflectionClass $refl, string $propertyName): ?\ReflectionProperty
            {
                return $this->getPropertyFromHierarchy($refl, $propertyName);
            }
        };
    }
}
