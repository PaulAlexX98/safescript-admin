<?php

return [
    'account_id'     => env('ZOOM_ACCOUNT_ID'),
    'client_id'      => env('ZOOM_CLIENT_ID'),
    'client_secret'  => env('ZOOM_CLIENT_SECRET'),
    'default_user'   => env('ZOOM_DEFAULT_USER', 'me'),
    'base_oauth'     => 'https://zoom.us/oauth/token',
    'base_api'       => 'https://api.zoom.us/v2',
];