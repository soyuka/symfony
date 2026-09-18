<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\ObjectMapper;

/**
 * @internal
 */
trait ClassHierarchyTrait
{
    /**
     * @var array<class-string, \ReflectionProperty[]>
     */
    private array $propertiesCache = [];

    /**
     * @var array<string, ?\ReflectionProperty>
     */
    private array $propertyCache = [];

    /**
     * @var array<class-string, \ReflectionClass>
     */
    private array $reflectionClassCache = [];

    /**
     * Returns all properties from a class including private properties from parent classes.
     *
     * @return \ReflectionProperty[]
     */
    private function getAllProperties(\ReflectionClass $refl): array
    {
        if (isset($this->propertiesCache[$refl->name])) {
            return $this->propertiesCache[$refl->name];
        }

        $properties = [];
        $seenNames = [];
        $current = $refl;

        do {
            foreach ($current->getProperties() as $property) {
                $name = $property->getName();
                if (isset($seenNames[$name])) {
                    continue;
                }
                $seenNames[$name] = true;
                $properties[] = $property;
            }
        } while ($current = $current->getParentClass());

        return $this->propertiesCache[$refl->name] = $properties;
    }

    /**
     * Gets a property from a class or its parent hierarchy.
     */
    private function getPropertyFromHierarchy(\ReflectionClass $refl, string $propertyName): ?\ReflectionProperty
    {
        $key = $refl->name.'::'.$propertyName;
        if (\array_key_exists($key, $this->propertyCache)) {
            return $this->propertyCache[$key];
        }

        $current = $refl;
        do {
            if ($current->hasProperty($propertyName)) {
                return $this->propertyCache[$key] = $current->getProperty($propertyName);
            }
        } while ($current = $current->getParentClass());

        return $this->propertyCache[$key] = null;
    }

    /**
     * @return \ReflectionClass<object>
     */
    private function getReflectionClass(object|string $objectOrClass): \ReflectionClass
    {
        $class = \is_object($objectOrClass) ? $objectOrClass::class : $objectOrClass;

        return $this->reflectionClassCache[$class] ??= new \ReflectionClass($class);
    }
}
