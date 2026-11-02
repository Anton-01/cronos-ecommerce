<?php

declare(strict_types=1);

namespace Cronos\Identity\Interfaces\GraphQL;

use Cronos\Identity\Application\AddressBook;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

final class AddressBookResolver
{
    private const MAP = ['recipientName' => 'recipient_name', 'exteriorNo' => 'exterior_no', 'interiorNo' => 'interior_no', 'countryCode' => 'country_code', 'taxId' => 'tax_id', 'businessName' => 'business_name'];

    public function __construct(private readonly AddressBook $book) {}

    /** @param array<string, mixed> $args */
    public function mine(mixed $root, array $args, GraphQLContext $context): array
    {
        return array_map($this->present(...), $this->book->list($this->uid($context), $args['type'] ?? null));
    }

    /** @param array<string, mixed> $args */
    public function add(mixed $root, array $args, GraphQLContext $context): array
    {
        return $this->present($this->book->add($this->uid($context), $this->snake($args['input'])));
    }

    /** @param array<string, mixed> $args */
    public function update(mixed $root, array $args, GraphQLContext $context): array
    {
        return $this->present($this->book->update($this->uid($context), $args['id'], $this->snake($args['input'])));
    }

    /** @param array<string, mixed> $args */
    public function delete(mixed $root, array $args, GraphQLContext $context): bool
    {
        $this->book->delete($this->uid($context), $args['id']);

        return true;
    }

    /** @param array<string, mixed> $args */
    public function makeDefault(mixed $root, array $args, GraphQLContext $context): array
    {
        return $this->present($this->book->makeDefault($this->uid($context), $args['id']));
    }

    /** @param array<string, mixed> $input @return array<string, mixed> */
    private function snake(array $input): array
    {
        $out = [];
        foreach ($input as $k => $v) {
            $out[self::MAP[$k] ?? $k] = $v;
        }

        return $out;
    }

    /** @param array<string, mixed> $a @return array<string, mixed> */
    private function present(array $a): array
    {
        return [
            'id' => $a['id'], 'type' => $a['type'], 'label' => $a['label'], 'recipientName' => $a['recipient_name'], 'phone' => $a['phone'], 'street' => $a['street'], 'exteriorNo' => $a['exterior_no'],
            'interiorNo' => $a['interior_no'], 'neighborhood' => $a['neighborhood'], 'city' => $a['city'], 'state' => $a['state'], 'countryCode' => $a['country_code'], 'zip' => $a['zip'],
            'references' => $a['references'], 'taxId' => $a['tax_id'], 'businessName' => $a['business_name'], 'isDefault' => $a['is_default'],
        ];
    }

    private function uid(GraphQLContext $context): string
    {
        return (string) $context->user()->getAuthIdentifier();
    }
}
