<?php

declare(strict_types=1);

return [
    // A market only sells from its own warehouses (and shared ones with no market). Off = the old behaviour: the market's warehouses
    // first, then any other warehouse. Leave it on when each country keeps its own stock, taxes and shipping.
    'market_isolation' => (bool) env('INVENTORY_MARKET_ISOLATION', true),
];
