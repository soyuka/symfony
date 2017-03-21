<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\Serializer\Dumper;

use Symfony\Component\Serializer\NormalizerInterface;
use Symfony\Component\Serializer\Normalizer\ObjectNormalizer;
use Symfony\Component\Serializer\Mapping\Factory\ClassMetadataFactoryInterface;

/**
 * @author Guilhem Niot <guilhem.niot@gmail.com>
 */
final class NormalizerDumper
{
    private $classMetadataFactory;

    public function __construct(ClassMetadataFactoryInterface $classMetadataFactory)
    {
        $this->classMetadataFactory = $classMetadataFactory;
    }

    public function dump($class, array $context = array())
    {
        $reflectionClass = new \ReflectionClass($class);
        if (!isset($context['class'])) {
            $context['class'] = $reflectionClass->getShortName().'Normalizer';
        }

        $namespaceLine = isset($context['namespace']) ? "\nnamespace {$context['namespace']};\n" : '';

        return <<<EOL
<?php
$namespaceLine
use Symfony\Component\Serializer\Exception\CircularReferenceException;
use Symfony\Component\Serializer\Normalizer\NormalizerAwareInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerAwareTrait;
use Symfony\Component\Serializer\Normalizer\NormalizerDumperTrait;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;
use Symfony\Component\Serializer\Normalizer\ObjectNormalizer;

/**
 * This class is generated.
 * Please do not update it manually.
 */
class {$context['class']} implements NormalizerInterface, NormalizerAwareInterface
{
    use NormalizerAwareTrait;
    use NormalizerDumperTrait;

{$this->generateNormalizeMethod($reflectionClass)}

{$this->generateSupportsNormalizationMethod($reflectionClass)}
}
EOL;
    }

    /**
     * Generates the {@see NormalizerInterface::normalize} method.
     *
     * @param \ReflectionClass $reflectionClass
     *
     * @return string
     */
    private function generateNormalizeMethod(\ReflectionClass $reflectionClass)
    {
        return <<<EOL
    public function normalize(\$object, \$format = null, array \$context = array())
    {
{$this->generateNormalizeMethodInner($reflectionClass)}
    }
EOL;
    }

    private function generateNormalizeMethodInner(\ReflectionClass $reflectionClass)
    {
        $code = <<<EOL
        \$this->checkCircularReference(\$object, \$context);

        \$output = array();

EOL;

        $attributesMetadata = $this->classMetadataFactory->getMetadataFor($reflectionClass->name)->getAttributesMetadata();

        foreach ($attributesMetadata as $attributeMetadata) {

            $groups = sprintf("array('%s')", implode("', '", $attributeMetadata->groups));

            $value = $this->generateGetAttributeValueExpression($attributeMetadata->name, $reflectionClass);

            $code .= <<<EOL

        if (\$this->isAllowedAttribute('{$attributeMetadata->name}', $groups, \$context)) {
            \$output['{$attributeMetadata->name}'] = \$this->getValue({$value}, '{$attributeMetadata->name}', \$format, \$context);
        }

EOL;
        }

        $code .= <<<EOL

        return \$output;
EOL;

        return $code;
    }

    /**
     * Generates an expression to get the value of an attribute.
     *
     * @param string           $property
     * @param \ReflectionClass $reflectionClass
     *
     * @return string
     */
    private function generateGetAttributeValueExpression($property, \ReflectionClass $reflectionClass)
    {
        $camelProp = $this->camelize($property);

        foreach ($methods = array('get'.$camelProp, lcfirst($camelProp), 'is'.$camelProp, 'has'.$camelProp) as $method) {
            if ($reflectionClass->hasMethod($method) && $reflectionClass->getMethod($method)) {
                return sprintf('$object->%s()', $method);
            }
        }

        if ($reflectionClass->hasProperty($property) && $reflectionClass->getProperty($property)->isPublic()) {
            return sprintf('$object->%s', $property);
        }

        if ($reflectionClass->hasMethod('__get') && $reflectionClass->getMethod('__get')) {
            return sprintf('$object->__get(\'%s\')', $property);
        }

        throw new \LogicException(sprintf('Neither the property "%s" nor one of the methods "%s()", "__get()" exist and have public access in class "%s".', $property, implode('()", "', $methods), $reflectionClass->name));
    }

    /**
     * Generates the {@see NormalizerInterface::supportsNormalization()} method.
     *
     * @param \ReflectionClass $reflectionClass
     *
     * @return string
     */
    private function generateSupportsNormalizationMethod(\ReflectionClass $reflectionClass)
    {
        $instanceof = '\\'.$reflectionClass->name;

        return <<<EOL
    public function supportsNormalization(\$data, \$format = null, array \$context = array())
    {
        return \$data instanceof {$instanceof};
    }
EOL;
    }

    private function camelize($string)
    {
        return str_replace(' ', '', ucwords(str_replace('_', ' ', $string)));
    }
}
