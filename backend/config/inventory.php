<?php

declare(strict_types=1);

return [
    // A market only sells from its own warehouses (and shared ones with no market). Off = the old behaviour: the market's warehouses
    // first, then any other warehouse. Leave it on when each country keeps its own stock, taxes and shipping.
    'market_isolation' => (bool) env('INVENTORY_MARKET_ISOLATION', true),

    // How many levels the shelf tree of a warehouse may have (zone > aisle > shelf > level > bin ...). Raising it later is always safe.
    'max_location_depth' => (int) env('INVENTORY_MAX_LOCATION_DEPTH', 6),

    // Starting values of the rules an administrator can change from the panel (the panel's value, once set, wins over these).
    // A shelf correction needs a second person only above these limits; 0 = that limit is not used.
    'approval_units' => (int) env('INVENTORY_APPROVAL_UNITS', 5),
    'approval_percent' => (int) env('INVENTORY_APPROVAL_PERCENT', 0),
    'approval_amount' => (int) env('INVENTORY_APPROVAL_AMOUNT', 0),
    // A low stock is only called recovered once it is this many percent above its threshold.
    'recovery_margin_percent' => (int) env('INVENTORY_RECOVERY_MARGIN', 10),
];
