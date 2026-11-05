<?php

declare(strict_types=1);

namespace Cronos\Inventory\Domain\ValueObjects;

/** Why stock changed inside the warehouse. The sign rule of each type is also a CHECK constraint in the database. */
final class LocationMovementType
{
    public const INITIAL = 'initial';

    public const RECEIVE = 'receive';

    public const MOVE_IN = 'move_in';

    public const MOVE_OUT = 'move_out';

    public const PICK = 'pick';

    public const ADJUST = 'adjust';

    public const COUNT_ADJUST = 'count_adjust';

    public const RETURN = 'return';

    public const WASTE = 'waste';

    public const COMPENSATION = 'compensation';

    public const ALL = [self::INITIAL, self::RECEIVE, self::MOVE_IN, self::MOVE_OUT, self::PICK, self::ADJUST, self::COUNT_ADJUST, self::RETURN, self::WASTE, self::COMPENSATION];

    /** Types that can only add. */
    public const ENTERS = [self::INITIAL, self::RECEIVE, self::MOVE_IN, self::RETURN];

    /** Types that can only take away. */
    public const LEAVES = [self::MOVE_OUT, self::PICK, self::WASTE];

    /** Types whose direction the caller chooses (a count may find more or less than the books). */
    public const EITHER = [self::ADJUST, self::COUNT_ADJUST, self::COMPENSATION];
}
