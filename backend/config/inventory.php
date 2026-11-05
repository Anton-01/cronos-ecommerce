<?php

declare(strict_types=1);

return [
    // A market only sells from its own warehouses (and shared ones with no market). Off = the old behaviour: the market's warehouses
    // first, then any other warehouse. Leave it on when each country keeps its own stock, taxes and shipping.
    'market_isolation' => (bool) env('INVENTORY_MARKET_ISOLATION', true),

    // How many levels the shelf tree of a warehouse may have (zone > aisle > shelf > level > bin ...). Raising it later is always safe.
    'max_location_depth' => (int) env('INVENTORY_MAX_LOCATION_DEPTH', 6),
];
