<?php

declare(strict_types=1);

namespace Cronos\Shared\Interfaces\GraphQL;

use GraphQL\Error\Error;
use GraphQL\Language\AST\BooleanValueNode;
use GraphQL\Language\AST\FloatValueNode;
use GraphQL\Language\AST\IntValueNode;
use GraphQL\Language\AST\ListValueNode;
use GraphQL\Language\AST\Node;
use GraphQL\Language\AST\NullValueNode;
use GraphQL\Language\AST\ObjectValueNode;
use GraphQL\Language\AST\StringValueNode;
use GraphQL\Type\Definition\ScalarType;

/** Arbitrary JSON value (scalar, list or object), used for settings whose shape depends on their type. */
final class JsonScalar extends ScalarType
{
    public string $name = 'JSON';

    public ?string $description = 'Any valid JSON value.';

    public function serialize($value): mixed
    {
        return $value;
    }

    public function parseValue($value): mixed
    {
        return $value;
    }

    public function parseLiteral(Node $valueNode, ?array $variables = null): mixed
    {
        return match (true) {
            $valueNode instanceof StringValueNode => $valueNode->value,
            $valueNode instanceof BooleanValueNode => $valueNode->value,
            $valueNode instanceof IntValueNode => (int) $valueNode->value,
            $valueNode instanceof FloatValueNode => (float) $valueNode->value,
            $valueNode instanceof NullValueNode => null,
            $valueNode instanceof ListValueNode => array_map(fn (Node $n) => $this->parseLiteral($n, $variables), iterator_to_array($valueNode->values)),
            $valueNode instanceof ObjectValueNode => collect(iterator_to_array($valueNode->fields))
                ->mapWithKeys(fn ($f) => [$f->name->value => $this->parseLiteral($f->value, $variables)])->all(),
            default => throw new Error('Cannot parse the literal as JSON.'),
        };
    }
}
