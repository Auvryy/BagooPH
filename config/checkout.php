<?php

return [
    // Enable only after every supported checkout client can collect and save a pin.
    'require_doorstep_pin' => env('CHECKOUT_REQUIRE_DOORSTEP_PIN', false),
];
