<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\Serializer\AstGenerator;

use PhpParser\Comment;
use PhpParser\Node\Arg;
use PhpParser\Node\Name;
use PhpParser\Node\Param;
use PhpParser\Node\Stmt;
use PhpParser\Node\Expr;
use PhpParser\Node\Scalar;
use Symfony\Component\Serializer\Exception\CircularReferenceException;
use Symfony\Component\Serializer\Normalizer\NormalizerAwareInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerAwareTrait;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;
use Symfony\Component\Serializer\Normalizer\ObjectNormalizer;
use Symfony\Component\Serializer\Mapping\Factory\ClassMetadataFactoryInterface;

final class NormalizerGenerator
{
    private $classMetadataFactory;

    public function __construct(ClassMetadataFactoryInterface $classMetadataFactory)
    {
        $this->classMetadataFactory = $classMetadataFactory;
    }

    public function generate($class, array $context = array())
    {
        $class = new \ReflectionClass($class);
        if (!isset($context['name'])) {
            $context['name'] = $class->getShortName().'Normalizer';
        }

        return array(new Stmt\Class_(
            new Name($context['name']),
            array(
                'type' => Stmt\Class_::MODIFIER_FINAL,
                'stmts' => array(
                    new Stmt\Use_(array(
                        new Name(NormalizerAwareTrait::class),
                    )),
                    $this->createNormalizeMethod($class),
                    $this->createSupportsNormalizationMethod($class),
                ),
                'implements' => array(
                    new Name(NormalizerInterface::class),
                    new Name(NormalizerAwareInterface::class),
                ),
            ),
            array(
                'comments' => array(new Comment("/**\n * This class is generated.\n * Please do not update it manually.\n */")),
            )
        ));
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
        return new Stmt\ClassMethod('normalize', array(
            'type' => Stmt\Class_::MODIFIER_PUBLIC,
            'params' => array(
                new Param('object'),
                new Param('format', new Expr\ConstFetch(new Name('null'))),
                new Param('context', new Expr\Array_(), 'array'),
            ),
            'stmts' => array_merge(
                array(new Expr\Assign(new Expr\Variable('output'), new Expr\Array_())),
                $this->generateNormalizeMethodInner($class),
                array(new Stmt\Return_(new Expr\Variable('output')))
            ),
        ));
    }

    private function generateNormalizeMethodInner(\ReflectionClass $class)
    {
        $metadata = $this->classMetadataFactory->getMetadataFor($class->name);

        $groupsContext = new Expr\ArrayDimFetch(new Expr\Variable('context'), new Scalar\String_(ObjectNormalizer::GROUPS));
        $stmts = array();

        $stmts[] = new Expr\Assign(new Expr\Variable('objectHash'), new Expr\FuncCall(new Name('spl_object_hash'), array(new Expr\Variable('object'))));
        $stmts[] = new Stmt\If_(
            new Expr\Isset_(array($circularReferenceContext = new Expr\ArrayDimFetch(new Expr\ArrayDimFetch(new Expr\Variable('context'), new Scalar\String_(ObjectNormalizer::CIRCULAR_REFERENCE_LIMIT)), new Expr\Variable('objectHash')))),
            array(
                'stmts' => array(
                    new Stmt\Throw_(new Expr\New_(new Name(CircularReferenceException::class), array(new Arg(new Scalar\String_('A circular reference has been detected (configured limit: 1).'))))),
                ),
                'else' => new Stmt\Else_(array(
                    new Expr\Assign($circularReferenceContext, new Scalar\LNumber(1)),
                )),
            )
        );

        $stmts[] = new Expr\Assign(
            new Expr\Variable('groups'),
            new Expr\Ternary(
                new Expr\BinaryOp\BooleanAnd(
                    new Expr\Isset_(array($groupsContext)),
                    new Expr\FuncCall(new Name('is_array'), array(new Arg($groupsContext)))
                ),
                $groupsContext,
                new Expr\ConstFetch(new Name('null'))
            )
        );

        $maxDepthStmts = array();
        foreach ($metadata->attributesMetadata as $attribute) {
            if (null === $maxDepth = $attribute->getMaxDepth()) {
                continue;
            }

            $attributeMaxDepthContext = new Expr\ArrayDimFetch(new Expr\Variable('context'), new Scalar\String_(sprintf(ObjectNormalizer::DEPTH_KEY_PATTERN, $class->name, $attribute->name)));
            $maxDepthStmts[] = new Stmt\If_(
                new Expr\BooleanNot(new Expr\Isset_(array($attributeMaxDepthContext))),
                array(
                    'stmts' => array(new Expr\Assign($attributeMaxDepthContext, new Scalar\LNumber(1))),
                    'elseifs' => array(new Stmt\ElseIf_(
                        new Expr\BinaryOp\NotIdentical(new Scalar\LNumber($maxDepth), $attributeMaxDepthContext),
                        array(new Expr\PreInc($attributeMaxDepthContext))
                    )),
                )
            );
        }

        if (0 !== count($maxDepthStmts)) {
            $stmts[] = new Stmt\If_(
                new Expr\Isset_(array(new Expr\ArrayDimFetch(new Expr\Variable('context'), new Scalar\String_(ObjectNormalizer::ENABLE_MAX_DEPTH)))),
                array('stmts' => $maxDepthStmts)
            );
        }

        foreach ($metadata->attributesMetadata as $attribute) {
            $attributesContext = new Expr\ArrayDimFetch(new Expr\Variable('context'), new Scalar\String_('attributes'));
            $condition = new Expr\BinaryOp\BooleanOr(
                new Expr\BooleanNot(new Expr\Isset_(array(new Expr\ArrayDimFetch(new Expr\Variable('context'), new Scalar\String_('attributes'))))),
                new Expr\BinaryOp\BooleanOr(
                    new Expr\Isset_(array(new Expr\ArrayDimFetch($attributesContext, new Scalar\String_($attribute->name)))),
                    new Expr\BinaryOp\BooleanAnd(
                        new Expr\FuncCall(new Name('is_array'), array(new Arg($attributesContext))),
                        new Expr\FuncCall(new Name('in_array'), array(new Arg(new Scalar\String_($attribute->name)), new Arg($attributesContext), new Arg(new Expr\ConstFetch(new Name('true')))))
                    )
                )
            );

            $groupsCondition = new Expr\BinaryOp\Identical(new Expr\ConstFetch(new Name('null')), new Expr\Variable('groups'));
            if ($attribute->groups) {
                $groupsCondition = new Expr\BinaryOp\BooleanOr(
                    $groupsCondition,
                    new Expr\FuncCall(new Name('count'), array(new Arg(
                        new Expr\FuncCall(new Name('array_intersect'), array(
                            new Arg(new Expr\Array_(
                                array_map(function ($group) {
                                    return new Expr\ArrayItem(new Scalar\String_($group));
                                }, $attribute->groups)
                            )),
                            new Arg(new Expr\Variable('groups')),
                        ))
                    )))
                );
            }
            $condition = new Expr\BinaryOp\BooleanAnd($groupsCondition, $condition);

            if (null !== $maxDepth = $attribute->getMaxDepth()) {
                $attributeMaxDepthContext = new Expr\ArrayDimFetch(new Expr\Variable('context'), new Scalar\String_(sprintf(ObjectNormalizer::DEPTH_KEY_PATTERN, $class->name, $attribute->name)));

                $condition = new Expr\BinaryOp\BooleanAnd(
                    $condition,
                    new Expr\BinaryOp\BooleanOr(
                        new Expr\BooleanNot(new Expr\Isset_(array($attributeMaxDepthContext))),
                        new Expr\BinaryOp\NotIdentical(new Scalar\LNumber($maxDepth), $attributeMaxDepthContext)
                    )
                );
            }

            $value = $this->getAttributeValue($attribute->name, $class);
            $output = new Expr\ArrayDimFetch(new Expr\Variable('output'), new Scalar\String_($attribute->name));
            $ifUnknownType = new Stmt\If_(
                new Expr\FuncCall(new Name('is_scalar'), array(new Arg($value))),
                array(
                    'stmts' => array(new Expr\Assign($output, $value)),
                    'else' => new Stmt\Else_(array(
                        new Expr\Assign(new Expr\Variable('subContext'), new Expr\Variable('context')),
                        new Stmt\If_(
                            new Expr\Isset_(array($subAttributes = new Expr\ArrayDimFetch($attributesContext, new Scalar\String_($attribute->name)))),
                            array('stmts' => array(
                                new Expr\Assign(
                                    new Expr\ArrayDimFetch(new Expr\Variable('subContext'), new Scalar\String_('attributes')),
                                    $subAttributes
                                ),
                            ))
                        ),
                        new Expr\Assign($output, new Expr\MethodCall(new Expr\PropertyFetch(new Expr\Variable('this'), 'normalizer'), 'normalize', array(new Arg($value), new Expr\Variable('format'), new Expr\Variable('subContext')))),
                    )),
                )
            );

            $stmts[] = new Stmt\If_($condition, array(
                'stmts' => array($ifUnknownType),
            ));
        }

        return $stmts;
    }

    private function getAttributeValue($property, \ReflectionClass $class)
    {
        $camelProp = $this->camelize($property);

        foreach ($methods = array('get'.$camelProp, lcfirst($camelProp), 'is'.$camelProp, 'has'.$camelProp) as $method) {
            if ($class->hasMethod($method) && $class->getMethod($method)) {
                return new Expr\MethodCall(new Expr\Variable('object'), $method);
            }
        }

        if ($class->hasProperty($property) && $class->getProperty($property)->isPublic()) {
            return new Expr\PropertyFetch(new Expr\Variable('object'), $property);
        }

        if ($class->hasMethod('__get') && $class->getMethod('__get')) {
            return new Expr\MethodCall(new Expr\Variable('object'), '__get');
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
        return new Stmt\ClassMethod('supportsNormalization', array(
            'type' => Stmt\Class_::MODIFIER_PUBLIC,
            'params' => array(
                new Param('data'),
                new Param('format', new Expr\ConstFetch(new Name('null'))),
                new Param('context', new Expr\Array_(), 'array'),
            ),
            'stmts' => array(
                new Stmt\Return_(new Expr\Instanceof_(new Expr\Variable('data'), new Name('\\'.$class->name))),
            ),
        ));
    }

    private function camelize($string)
    {
        return str_replace(' ', '', ucwords(str_replace('_', ' ', $string)));
    }
}
