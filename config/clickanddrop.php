<?php
// config/clickanddrop.php
return [
    'key'      => env('CLICK_AND_DROP_API_KEY'),
    'base'     => env('CLICK_AND_DROP_BASE', 'https://api.parcel.royalmail.com/api/v1'),
    'service'  => env('CLICK_AND_DROP_DEFAULT_SERVICE', 'RM24'),
    'package'  => env('CLICK_AND_DROP_DEFAULT_PACKAGE', 'Parcel'),
];