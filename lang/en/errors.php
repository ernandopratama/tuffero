<?php

// Storefront error page copy (404, 403, 419, 429, 500, 503). The admin area has its own Indonesian text in Vue.
return [
    'home' => 'Back to home',
    'products' => 'Browse products',
    'cart' => 'View cart',
    'reload' => 'Try again',
    'code' => 'Error code :code',
    'retry_in' => 'Try again in :seconds seconds.',

    '404' => [
        'title' => 'Page not found',
        'text' => 'The address you opened does not exist or has moved. Head back to the shop and keep browsing.',
    ],
    '403' => [
        'title' => 'Access denied',
        'text' => 'You do not have permission to open this page.',
    ],
    '419' => [
        'title' => 'Your session has expired',
        'text' => 'This page stayed open for too long and its security token expired. Open the page again, then retry.',
    ],
    '429' => [
        'title' => 'Too many requests',
        'text' => 'Please wait a moment before trying again.',
    ],
    '500' => [
        'title' => 'Something went wrong',
        'text' => 'Sorry, something broke on our side. Please try again in a moment.',
    ],
    '503' => [
        'title' => 'Getting a fresh coat of icing',
        'text' => 'Tuffero is down for a short maintenance break. We will be right back.',
    ],
];
