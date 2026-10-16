<?php

declare(strict_types=1);

/**
 * VODNOX telephony provisioning agent — front controller.
 *
 * Served by php-fpm behind nginx (see deploy/telephony/nginx). Every request is
 * authenticated and signed; see src/Agent.php for the security model.
 */

require __DIR__.'/../src/Agent.php';

use Vodnox\Telephony\Agent;

$configFile = __DIR__.'/../config.php';

if (! is_file($configFile)) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'the provisioning agent is not configured']);

    return;
}

$agent = new Agent(require $configFile);

$headers = [];
foreach ($_SERVER as $name => $value) {
    if (str_starts_with($name, 'HTTP_')) {
        $headers[strtolower(str_replace('_', '-', substr($name, 5)))] = (string) $value;
    }
}

$result = $agent->handle(
    strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')),
    (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH),
    $headers,
    (string) file_get_contents('php://input'),
);

http_response_code($result['status']);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

echo json_encode($result['body'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
