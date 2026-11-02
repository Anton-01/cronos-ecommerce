<?php

declare(strict_types=1);

namespace Cronos\Identity\Application;

use Cronos\Identity\Domain\Exceptions\AddressError;
use Cronos\Identity\Domain\Ports\AddressBookRepository;
use Cronos\Identity\Domain\Services\AddressRules;
use Illuminate\Support\Facades\Cache;

/**
 * A person's saved addresses. The list is read on every checkout and account visit but changes rarely, so it is kept in the cache per
 * person and thrown away only when that person changes it: after the first read the database is not asked again.
 */
final class AddressBook
{
    public function __construct(private readonly AddressBookRepository $addresses) {}

    /** @return list<array<string, mixed>> */
    public function list(string $userId, ?string $type = null): array
    {
        $all = Cache::remember($this->key($userId), 86400, fn () => $this->addresses->all($userId));

        return $type === null ? $all : array_values(array_filter($all, static fn (array $a) => $a['type'] === $type));
    }

    /** @param array<string, mixed> $input snake_case */
    public function add(string $userId, array $input): array
    {
        $data = AddressRules::normalize($input);
        $this->addresses->count($userId) < AddressRules::MAX_PER_PERSON || throw new AddressError('You can keep up to '.AddressRules::MAX_PER_PERSON.' addresses.', 'ADDRESS_LIMIT');
        $data['type'] === 'billing' || $data = array_diff_key($data, ['tax_id' => 1, 'business_name' => 1]);
        $first = $this->list($userId, $data['type']) === [];
        $id = $this->addresses->create($userId, $data + ['is_default' => $first]);
        $this->forget($userId);

        return $this->addresses->find($userId, $id);
    }

    /** @param array<string, mixed> $input snake_case, only the fields to change */
    public function update(string $userId, string $id, array $input): array
    {
        $current = $this->addresses->find($userId, $id) ?? throw new AddressError('That address does not exist.', 'ADDRESS_NOT_FOUND');
        unset($input['type']); // an address does not change kind: delete it and add another
        $data = AddressRules::normalize($input, partial: true);
        $current['type'] === 'billing' || $data = array_diff_key($data, ['tax_id' => 1, 'business_name' => 1]);
        $this->addresses->update($userId, $id, $data);
        $this->forget($userId);

        return $this->addresses->find($userId, $id);
    }

    public function delete(string $userId, string $id): void
    {
        $current = $this->addresses->find($userId, $id) ?? throw new AddressError('That address does not exist.', 'ADDRESS_NOT_FOUND');
        $this->addresses->delete($userId, $id);
        // The default goes to the newest one left of the same kind, so there is always one when there is any.
        if ($current['is_default']) {
            $next = collect($this->addresses->all($userId))->firstWhere('type', $current['type']);
            $next === null || $this->addresses->makeDefault($userId, $next['id']);
        }
        $this->forget($userId);
    }

    public function makeDefault(string $userId, string $id): array
    {
        $this->addresses->find($userId, $id) ?? throw new AddressError('That address does not exist.', 'ADDRESS_NOT_FOUND');
        $this->addresses->makeDefault($userId, $id);
        $this->forget($userId);

        return $this->addresses->find($userId, $id);
    }

    private function forget(string $userId): void
    {
        Cache::forget($this->key($userId));
    }

    private function key(string $userId): string
    {
        return "identity:addresses:{$userId}";
    }
}
