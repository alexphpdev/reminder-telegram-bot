<?php

return [
    'daily_report' => [
        'time' => env('WEATHER_DAILY_REPORT_TIME', '07:00'),
        'disable_notification' => filter_var(
            env('WEATHER_DAILY_REPORT_DISABLE_NOTIFICATION', false),
            FILTER_VALIDATE_BOOLEAN,
        ),
    ],
];
