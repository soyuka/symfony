<?php

namespace Symfony\Component\Serializer\Normalizer;

use Symfony\Component\Serializer\Exception\CircularReferenceException;
use Symfony\Component\Serializer\Normalizer\ObjectNormalizer;

trait NormalizerDumperTrait
{
    private function checkCircularReference($object, &$context)
     {
        $objectHash = spl_object_hash($object);
        if (isset($context[ObjectNormalizer::CIRCULAR_REFERENCE_LIMIT][$objectHash])) {
            throw new CircularReferenceException('A circular reference has been detected (configured limit: 1).');
        } else {
            $context[ObjectNormalizer::CIRCULAR_REFERENCE_LIMIT][$objectHash] = 1;
        }
    }

    private function isAllowedAttribute($property, $attributeGroups, &$context) {
        $groups = isset($context[ObjectNormalizer::GROUPS]) && is_array($context[ObjectNormalizer::GROUPS]) ? $context[ObjectNormalizer::GROUPS] : null;

        if ((null === $groups || count(array_intersect($groups, $attributeGroups))) && (
            !isset($context['attributes']) ||
            isset($context['attributes'][$property]) ||
            (is_array($context['attributes']) && in_array($property, $context['attributes'], true)))) {
            return true;
        }

        return false;
    }

    private function getValue($value, $property, $format, &$context) {
        if (is_scalar($value)) {
            return $value;
        } else {
            return $this->normalizer->normalize($value, $format, $context);
        }
    }
}

