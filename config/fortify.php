<?php

use Laravel\Fortify\Features;

// Email-dependent features exist only when mail has been configured and
// switched on. With mail off the routes are not registered at all, so the
// application can never claim to have sent a message it could not send.
$mailEnabled = (bool) env('LIBRARY_MAIL_ENABLED', false);

return [

    'guard' => 'web',

    'passwords' => 'users',

    'username' => 'email',

    'email' => 'email',

    'lowercase_usernames' => true,

    'home' => '/',

    'prefix' => '',

    'domain' => null,

    'middleware' => ['web'],

    'limiters' => [
        'login' => 'login',
        'two-factor' => 'two-factor',
    ],

    'views' => true,

    'features' => array_values(array_filter([
        Features::registration(),
        $mailEnabled ? Features::resetPasswords() : null,
        $mailEnabled ? Features::emailVerification() : null,
        Features::updateProfileInformation(),
        Features::updatePasswords(),
        Features::twoFactorAuthentication([
            'confirm' => true,
            'confirmPassword' => true,
        ]),
    ])),

];
