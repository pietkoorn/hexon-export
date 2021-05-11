<?php

return [

    /*
    |--------------------------------------------------------------------------
    | HTTP Basic Auth Credentials
    |--------------------------------------------------------------------------
    |
    | The array of users with hashed username and password credentials which are
    | used when logging in with HTTP basic authentication.
    |
    */

    'auth' => [
        'enabled' => env('HEXON_AUTH'),
        'username' => env('HEXON_USERNAME'),
        'password' => env('HEXON_PASSWORD')
    ],

    /*
     |--------------------------------------------------------------------------
     | Url Endpoint
     |--------------------------------------------------------------------------
     |
     | The url where the POST requests from Hexon are routed to.
     |
     */
    'url_endpoint' => '/hexon-export',

    /*
     |--------------------------------------------------------------------------
     | Images Storage Path
     |--------------------------------------------------------------------------
     |
     | The path where occasion images, relative to your 'public' storage disk.
     |
     */
    'images_storage_path' => 'occasions/images/',

    /*
     |--------------------------------------------------------------------------
     | XML Storage Path
     |--------------------------------------------------------------------------
     |
     | The path where incoming XML files are stored, relative to
     | your 'default' storage disk.
     |
     */
    'xml_storage_path' => 'hexon-export/xml/',
];
