<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Beam's user-facing strings
|--------------------------------------------------------------------------
|
| The push notification is the one Beam surface an end user reads, on their
| phone, at the moment it matters. Publish this file to change the wording —
| or to say it in their language:
|
|   php artisan vendor:publish --tag=beam-lang
|
| Everything else Beam says (exceptions, beam:doctor) is for whoever is
| building the application, and stays in English.
|
*/

return [

    'notification' => [
        'calling' => ':name is calling',
        'video' => 'Video call',
        'audio' => 'Voice call',
        'answer' => 'Answer',
        'decline' => 'Decline',
    ],

];
