<?php

use Eppitnic\Api\ClientIp;
use Eppitnic\Api\Json;
use Eppitnic\Config;
use Eppitnic\Service\PasswordReset;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

$app->get('/v1/network-check', function (Request $request, Response $response, array $args): Response {
    $safeNetwork = false;
    foreach (Config::get('safe_networks') as $cidr) {
        if (ClientIp::inCidr($cidr)) {
            $safeNetwork = true;
            break;
        }
    }

    return Json::response($response, [
        'safe_network'   => $safeNetwork,
        'client_ip'      => ClientIp::get(),
        'password_reset' => PasswordReset::available(),
    ]);
});
