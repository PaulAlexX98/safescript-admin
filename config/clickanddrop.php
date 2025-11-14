<?php

return [
    'base'            => env('CLICK_AND_DROP_BASE', 'https://api.parcel.royalmail.com/api/v1'),
    'key'             => env('CLICK_AND_DROP_API_KEY'),
    'default_service' => env('CLICK_AND_DROP_DEFAULT_SERVICE', 'RM24'),
    'default_package' => env('CLICK_AND_DROP_DEFAULT_PACKAGE', 'Parcel'),
];