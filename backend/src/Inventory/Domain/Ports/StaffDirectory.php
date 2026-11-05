<?php

declare(strict_types=1);

namespace Cronos\Inventory\Domain\Ports;

/** The people of the system (staff, not shoppers) that alerts and approvals may refer to. */
interface StaffDirectory
{
    /** @param list<string> $userIds @return list<array{id: string, email: string}> only the active staff among them */
    public function staff(array $userIds): array;

    public function isStaff(string $userId): bool;

    /** @return list<array{id: string, email: string, roles: list<string>}> everyone who may be picked in the admin */
    public function all(): array;
}
