<?php

declare(strict_types=1);

namespace Cronos\Identity\Infrastructure\Persistence;

use Cronos\Identity\Domain\Ports\AddressBookRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class DatabaseAddressBookRepository implements AddressBookRepository
{
    public function all(string $userId): array
    {
        return DB::table('user_addresses')->where('user_id', $userId)->whereNull('deleted_at')->orderByDesc('is_default')->orderByDesc('created_at')->orderByDesc('id')->get()->map(fn ($r) => $this->shape($r))->all();
    }

    public function find(string $userId, string $id): ?array
    {
        $row = DB::table('user_addresses')->where(['user_id' => $userId, 'id' => $id])->whereNull('deleted_at')->first();

        return $row === null ? null : $this->shape($row);
    }

    public function count(string $userId): int
    {
        return DB::table('user_addresses')->where('user_id', $userId)->whereNull('deleted_at')->count();
    }

    public function create(string $userId, array $data): string
    {
        $id = (string) Str::ulid();
        DB::table('user_addresses')->insert($data + ['id' => $id, 'user_id' => $userId, 'created_at' => now(), 'updated_at' => now()]);

        return $id;
    }

    public function update(string $userId, string $id, array $data): void
    {
        $data === [] || DB::table('user_addresses')->where(['user_id' => $userId, 'id' => $id])->whereNull('deleted_at')->update($data + ['updated_at' => now()]);
    }

    public function delete(string $userId, string $id): void
    {
        DB::table('user_addresses')->where(['user_id' => $userId, 'id' => $id])->whereNull('deleted_at')->update(['deleted_at' => now(), 'is_default' => false]);
    }

    public function makeDefault(string $userId, string $id): void
    {
        DB::transaction(function () use ($userId, $id): void {
            $type = DB::table('user_addresses')->where(['user_id' => $userId, 'id' => $id])->whereNull('deleted_at')->value('type');
            // Clear the old default first: the partial unique index allows only one at any moment.
            DB::table('user_addresses')->where(['user_id' => $userId, 'type' => $type, 'is_default' => true])->whereNull('deleted_at')->update(['is_default' => false]);
            DB::table('user_addresses')->where(['user_id' => $userId, 'id' => $id])->update(['is_default' => true, 'updated_at' => now()]);
        });
    }

    /** @return array<string, mixed> */
    private function shape(object $r): array
    {
        return [
            'id' => $r->id, 'type' => $r->type, 'label' => $r->label, 'recipient_name' => $r->recipient_name, 'phone' => $r->phone, 'street' => $r->street, 'exterior_no' => $r->exterior_no,
            'interior_no' => $r->interior_no, 'neighborhood' => $r->neighborhood, 'city' => $r->city, 'state' => $r->state, 'country_code' => $r->country_code, 'zip' => $r->zip,
            'references' => $r->references, 'tax_id' => $r->tax_id, 'business_name' => $r->business_name, 'is_default' => (bool) $r->is_default,
        ];
    }
}
