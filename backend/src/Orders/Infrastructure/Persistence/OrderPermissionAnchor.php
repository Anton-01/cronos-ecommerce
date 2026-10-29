<?php

declare(strict_types=1);

namespace Cronos\Orders\Infrastructure\Persistence;

use Illuminate\Database\Eloquent\Model;

/** The GraphQL `@can` directive wants an Eloquent class to ask the gate about; the permissions are plain Spatie strings (orders.view...). Never persisted. */
final class OrderPermissionAnchor extends Model
{
    protected $table = 'orders';
}
