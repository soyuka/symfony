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
        $class = new \ReflectionClass($class);
        if (!isset($context['name'])) {
            $context['name'] = $class->getShortName().'Normalizer';
        }

        return <<<EOL
use Symfony\Component\Serializer\Exception\CircularReferenceException;
use Symfony\Component\Serializer\Normalizer\NormalizerAwareInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerAwareTrait;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;
use Symfony\Component\Serializer\Normalizer\ObjectNormalizer;

/**
 * This class is generated.
 * Please do not update it manually.
 */
class {$context['name']} implements NormalizerInterface, NormalizerAwareInterface
{
    use NormalizerAwareTrait;

{$this->createNormalizeMethod($class)}

{$this->createSupportsNormalizationMethod($class)}
}
EOL;
    }

    /**
     * Create the normalization method.
     *
     * @param string $class   Class to create normalization from
     * @param array  $context Context of generation
     *
     * @return Stmt\ClassMethod
     */
    private function createNormalizeMethod(\ReflectionClass $class)
    {
        return <<<EOL
    public function normalize(\$object, \$format = null, array \$context = array())
    {
{$this->generateNormalizeMethodInner($class)}
    }
EOL;
    }

    private function generateNormalizeMethodInner(\ReflectionClass $class)
    {
        $metadata = $this->classMetadataFactory->getMetadataFor($class->name);
        $code = <<<EOL

        \$objectHash = spl_object_hash(\$object);
        if (isset(\$context[ObjectNormalizer::CIRCULAR_REFERENCE_LIMIT][\$objectHash])) {
            throw new CircularReferenceException('A circular reference has been detected (configured limit: 1).');
        } else {
            \$context[ObjectNormalizer::CIRCULAR_REFERENCE_LIMIT][\$objectHash] = 1;
        }

        \$groups = isset(\$context[ObjectNormalizer::GROUPS]) && is_array(\$context[ObjectNormalizer::GROUPS]) ? \$context[ObjectNormalizer::GROUPS] : null;

        \$output = array();
EOL;

        $maxDepthCode = '';
        foreach ($metadata->attributesMetadata as $attribute) {
            if (null === $maxDepth = $attribute->getMaxDepth()) {
                continue;
            }

            $key = sprintf(ObjectNormalizer::DEPTH_KEY_PATTERN, $class->name, $attribute->name);
            $maxDepthCode .= <<<EOL

            if (!isset(\$context['{$key}'])) {
                \$context['{$key}'] = 1;
            } elseif ({$maxDepth} !== \$context['{$key}']) {
                ++\$context['{$key}'];
            }
EOL;
        }

        if ($maxDepthCode) {
            $code .= <<<EOL

        if (isset(\$context[ObjectNormalizer::ENABLE_MAX_DEPTH])) {{$maxDepthCode}
        }

EOL;
        }

        foreach ($metadata->attributesMetadata as $attribute) {
            $code .= <<<EOL

        if ((null === \$groups
EOL;

            if ($attribute->groups) {
                $code .= sprintf(" || count(array_intersect(\$groups, array('%s')))", implode("', '", $attribute->groups));
            }
            $code .= ')';

            $code .= " && (!isset(\$context['attributes']) || isset(\$context['attributes']['{$attribute->name}']) || (is_array(\$context['attributes']) && in_array('{$attribute->name}', \$context['attributes'], true)))";

            if (null !== $maxDepth = $attribute->getMaxDepth()) {
                $key = sprintf(ObjectNormalizer::DEPTH_KEY_PATTERN, $class->name, $attribute->name);
                $code .= " && (!isset(\$context['{$key}']) || {$maxDepth} !== \$context['{$key}'])";
            }

            $code .= ') {';

            $value = $this->getAttributeValue($attribute->name, $class);
            $code .= <<<EOL

            if (is_scalar({$value})) {
                \$output['{$attribute->name}'] = {$value};
            } else {
                \$subContext = \$context;
                if (isset(\$context['attributes']['{$attribute->name}'])) {
                    \$subContext['attributes'] = \$context['attributes']['{$attribute->name}'];
                }

                \$this->normalizer->normalize({$value}, \$format, \$context);
            }
        }
EOL;
        }

        $code .= <<<EOL


        return \$output;
EOL;

        return $code;
    }

    private function getAttributeValue($property, \ReflectionClass $class)
    {
        $camelProp = $this->camelize($property);

        foreach ($methods = array('get'.$camelProp, lcfirst($camelProp), 'is'.$camelProp, 'has'.$camelProp) as $method) {
            if ($class->hasMethod($method) && $class->getMethod($method)) {
                return sprintf('$object->%s()', $method);
            }
        }

        if ($class->hasProperty($property) && $class->getProperty($property)->isPublic()) {
            return sprintf('$object->%s', $property);
        }

        if ($class->hasMethod('__get') && $class->getMethod('__get')) {
            return sprintf("$object->__get('%s')", $property);
        }

        throw new \LogicException(sprintf('Neither the property "%s" nor one of the methods "%s()", "__get()" exist and have public access in class "%s".', $property, implode('()", "', $methods), $class->name));
    }

    /**
     * Create method to check if normalization is supported.
     *
     * @return Stmt\ClassMethod
     */
    private function createSupportsNormalizationMethod($class)
    {
        $instanceof = '\\'.$class->name;

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
