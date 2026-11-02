<?php

namespace Tests\Feature\Identity;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\GraphQLTestCase;

final class AddressBookTest extends GraphQLTestCase
{
    use RefreshDatabase;

    private const FIELDS = 'id type label recipientName street exteriorNo city state countryCode zip taxId businessName isDefault';

    private string $ana;

    private function customerToken(string $email): string
    {
        $this->makeUser($email);

        return $this->gql('mutation($i: LoginInput!) { login(input: $i) { token } }', ['i' => ['email' => $email, 'password' => 'correct-horse-battery']])->json('data.login.token');
    }

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->ana = $this->customerToken('ana@example.com');
    }

    private function add(array $over = [], ?string $token = null): TestResponse
    {
        return $this->gql('mutation($i: SavedAddressInput!) { addAddress(input: $i) { '.self::FIELDS.' } }', ['i' => $over + ['recipientName' => 'Ana Pérez', 'street' => 'Calle 1', 'exteriorNo' => '10', 'city' => 'CDMX', 'state' => 'CMX', 'zip' => '01000', 'countryCode' => 'mx']], $token ?? $this->ana);
    }

    private function mine(?string $type = null, ?string $token = null): array
    {
        return $this->gql('query($t: AddressKind) { myAddresses(type: $t) { '.self::FIELDS.' } }', ['t' => $type], $token ?? $this->ana)->assertJsonMissingPath('errors')->json('data.myAddresses');
    }

    public function test_the_first_address_of_a_kind_becomes_its_default_and_the_country_is_normalised(): void
    {
        $first = $this->add()->assertJsonMissingPath('errors')->json('data.addAddress');
        $this->assertTrue($first['isDefault']);
        $this->assertSame('MX', $first['countryCode']);
        $this->assertSame('SHIPPING', $first['type']);

        $second = $this->add(['street' => 'Calle 2'])->json('data.addAddress');
        $this->assertFalse($second['isDefault']);
        $billing = $this->add(['type' => 'BILLING', 'taxId' => 'xaxx 010101 000', 'businessName' => 'Ana SA'])->json('data.addAddress');
        $this->assertTrue($billing['isDefault'], 'each kind has its own default');
        $this->assertSame('XAXX010101000', $billing['taxId']);
        $this->assertSame(['Calle 1', 'Calle 2'], collect($this->mine('SHIPPING'))->pluck('street')->all());
    }

    public function test_there_is_always_exactly_one_default_per_kind(): void
    {
        $a = $this->add()->json('data.addAddress.id');
        $b = $this->add(['street' => 'Calle 2'])->json('data.addAddress.id');

        $this->gql('mutation($i: ID!) { setDefaultAddress(id: $i) { id isDefault } }', ['i' => $b], $this->ana)->assertJsonPath('data.setDefaultAddress.isDefault', true);
        $this->assertSame([$b], collect($this->mine())->where('isDefault', true)->pluck('id')->all());

        $this->gql('mutation($i: ID!) { deleteAddress(id: $i) }', ['i' => $b], $this->ana)->assertJsonPath('data.deleteAddress', true);
        $this->assertSame([$a], collect($this->mine())->where('isDefault', true)->pluck('id')->all(), 'deleting the default hands it to the next one');
        $this->assertSame(1, DB::table('user_addresses')->whereNull('deleted_at')->where('is_default', true)->count());
    }

    public function test_an_invalid_address_says_which_fields_are_wrong(): void
    {
        $r = $this->add(['street' => str_repeat('x', 200), 'countryCode' => 'Mexico', 'zip' => str_repeat('9', 30)]);
        $r->assertJsonPath('errors.0.extensions.code', 'INVALID_ADDRESS');
        $this->assertEqualsCanonicalizing(['street', 'country_code', 'zip'], array_keys($r->json('errors.0.extensions.fields')));
        $this->assertSame([], $this->mine());
    }

    public function test_nobody_can_read_or_change_another_persons_addresses(): void
    {
        $id = $this->add()->json('data.addAddress.id');
        $eve = $this->customerToken('eve@example.com');

        $this->assertSame([], $this->mine(null, $eve));
        $this->gql('mutation($i: ID!) { deleteAddress(id: $i) }', ['i' => $id], $eve)->assertJsonPath('errors.0.extensions.code', 'ADDRESS_NOT_FOUND');
        $this->gql('mutation($i: ID!) { updateAddress(id: $i, input: {recipientName: "X", street: "X", city: "X", state: "X", zip: "1", countryCode: "MX"}) { id } }', ['i' => $id], $eve)->assertJsonPath('errors.0.extensions.code', 'ADDRESS_NOT_FOUND');
        $this->assertCount(1, $this->mine());
        $this->gql('{ myAddresses { id } }')->assertJsonPath('errors.0.message', 'Unauthenticated.');
    }

    public function test_updating_keeps_the_kind_and_a_person_keeps_at_most_twenty(): void
    {
        $id = $this->add()->json('data.addAddress.id');
        $u = $this->gql('mutation($i: ID!) { updateAddress(id: $i, input: {type: BILLING, recipientName: "Ana P.", street: "Calle 9", city: "CDMX", state: "CMX", zip: "02000", countryCode: "MX"}) { type street recipientName } }', ['i' => $id], $this->ana)->json('data.updateAddress');
        $this->assertSame(['SHIPPING', 'Calle 9', 'Ana P.'], [$u['type'], $u['street'], $u['recipientName']]);

        for ($i = 0; $i < 19; $i++) {
            $this->add(['street' => "Calle {$i}"])->assertJsonMissingPath('errors');
        }
        $this->add()->assertJsonPath('errors.0.extensions.code', 'ADDRESS_LIMIT');
    }

    public function test_the_list_is_served_from_the_cache_until_the_person_changes_it(): void
    {
        $this->add();
        $this->mine();

        DB::enableQueryLog();
        $this->mine();
        $this->mine('SHIPPING');
        $reads = collect(DB::getQueryLog())->filter(fn ($q) => str_contains($q['query'], 'user_addresses'))->count();
        $this->assertSame(0, $reads);

        $this->add(['street' => 'Calle 2']);
        $this->assertCount(2, $this->mine(), 'a change is visible at once');
    }
}
