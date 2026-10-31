<?php

namespace Tests\Unit\Shared;

use Cronos\Shared\Domain\Geo\IpAddress;
use PHPUnit\Framework\TestCase;

final class IpAddressTest extends TestCase
{
    public function test_ipv4_and_ipv6_are_normalised_to_one_spelling(): void
    {
        $this->assertSame('187.190.10.20', IpAddress::normalize('187.190.10.20'));
        $this->assertSame('187.190.10.20', IpAddress::normalize('::ffff:187.190.10.20'), 'a mapped IPv4 is the same visitor');
        $this->assertSame('187.190.10.20', IpAddress::normalize('::FFFF:BBBE:A14'));
        $this->assertSame('2001:db8::1', IpAddress::normalize('2001:0DB8:0000:0000:0000:0000:0000:0001'));
        $this->assertSame('2001:db8::1', IpAddress::normalize('[2001:db8::1]'));
        $this->assertSame('fe80::1', IpAddress::normalize('fe80::1%eth0'));
        foreach ([null, '', 'garbage', '999.1.1.1', '2001:db8::zz', '1.2.3'] as $bad) {
            $this->assertNull(IpAddress::normalize($bad), (string) $bad);
        }
    }

    public function test_families_and_public_ranges(): void
    {
        $this->assertSame([4, 6, 4, null], [IpAddress::family('8.8.8.8'), IpAddress::family('2001:4860::8888'), IpAddress::family('::ffff:8.8.8.8'), IpAddress::family('x')]);
        foreach (['8.8.8.8', '2001:4860:4860::8888', '::ffff:8.8.8.8'] as $public) {
            $this->assertTrue(IpAddress::isPublic($public), $public);
        }
        foreach (['10.0.0.1', '192.168.1.1', '127.0.0.1', '169.254.1.1', '::1', 'fc00::1', 'fd12:3456::1', 'fe80::1', '::', '::ffff:10.0.0.1', 'nope'] as $private) {
            $this->assertFalse(IpAddress::isPublic($private), $private);
        }
    }

    public function test_an_ipv6_household_counts_as_one_but_ipv4_addresses_stay_separate(): void
    {
        $this->assertSame('2001:db8:1:2::/64', IpAddress::counterKey('2001:db8:1:2:aaaa:bbbb:cccc:dddd'));
        $this->assertSame(IpAddress::counterKey('2001:db8:1:2::1'), IpAddress::counterKey('2001:db8:1:2:ffff::9'));
        $this->assertNotSame(IpAddress::counterKey('2001:db8:1:2::1'), IpAddress::counterKey('2001:db8:1:3::1'));
        $this->assertSame('187.190.10.20', IpAddress::counterKey('::ffff:187.190.10.20'));
        $this->assertNotSame(IpAddress::counterKey('187.190.10.20'), IpAddress::counterKey('187.190.10.21'));
    }

    public function test_cidr_blocks_expand_to_their_first_and_last_address(): void
    {
        $this->assertSame(['187.190.0.0', '187.191.255.255'], IpAddress::cidrRange('187.190.0.0/15'));
        $this->assertSame(['8.8.8.8', '8.8.8.8'], IpAddress::cidrRange('8.8.8.8/32'));
        $this->assertSame(['0.0.0.0', '255.255.255.255'], IpAddress::cidrRange('1.2.3.4/0'));
        $this->assertSame(['10.1.2.0', '10.1.2.127'], IpAddress::cidrRange('10.1.2.77/25'));
        $this->assertSame(['2001:4860::', '2001:4860:ffff:ffff:ffff:ffff:ffff:ffff'], IpAddress::cidrRange('2001:4860::/32'));
        $this->assertSame(['2001:db8::1', '2001:db8::1'], IpAddress::cidrRange('2001:db8::1/128'));
        foreach (['1.2.3.4/33', '2001:db8::/129', '1.2.3.4', '1.2.3.4/x', 'x/8', '1.2.3.4/8/9'] as $bad) {
            $this->assertNull(IpAddress::cidrRange($bad), $bad);
        }
    }
}
