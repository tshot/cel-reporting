<?php
return [
    'api_url' => $_ENV['EMOLLIENT_REDCAP_URL']   ?? throw new RuntimeException(
        'EMOLLIENT_REDCAP_URL is not set — check .env'),
    'token'   => $_ENV['EMOLLIENT_REDCAP_TOKEN'] ?? throw new RuntimeException(
        'EMOLLIENT_REDCAP_TOKEN is not set — check .env'),
];
