<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Storage driver
    |--------------------------------------------------------------------------
    |
    | auto:   native storage on a device; a JSON file off the device (an
    |         in-memory array while running unit tests). A device never falls
    |         back to another store.
    | native: always use the NativePHP bridge (UserDefaults / SharedPreferences).
    | file:   always use the JSON file below.
    | array:  in-memory storage for the current PHP process.
    |
    */

    'driver' => env('NATIVE_SETTINGS_DRIVER', 'auto'),

    /*
    |--------------------------------------------------------------------------
    | File driver path
    |--------------------------------------------------------------------------
    */

    'file_path' => env('NATIVE_SETTINGS_FILE_PATH', storage_path('app/nativephp-settings.json')),

    /*
    |--------------------------------------------------------------------------
    | Strict typed getters
    |--------------------------------------------------------------------------
    |
    | When false, typed getters such as Settings::getBool() return the default
    | and log a warning when the stored value has another type. When true,
    | they throw SettingTypeMismatch instead.
    |
    */

    'strict' => (bool) env('NATIVE_SETTINGS_STRICT', false),

];
