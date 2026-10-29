<?php

return [
    // A checkout whose rules add up to at least this much is held for a person to look at...
    'review_at' => (int) env('FRAUD_REVIEW_AT', 50),
    // ...and at this much it is refused outright.
    'block_at' => (int) env('FRAUD_BLOCK_AT', 80),
];
