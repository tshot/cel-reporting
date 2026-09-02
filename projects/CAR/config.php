<?php
return [
    'api_url' => $_ENV['CAR_REDCAP_URL']   ?? throw new RuntimeException(
        'CAR_REDCAP_URL is not set — check .env'),
    'token'   => $_ENV['CAR_REDCAP_TOKEN'] ?? throw new RuntimeException(
        'CAR_REDCAP_TOKEN is not set — check .env'),
];
