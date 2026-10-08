<?php

declare(strict_types=1);

/** Render gateway HTML as plain text; never pass notification markup to the browser. */
function notificationText(string $html): string
{
    $html = preg_replace('/<(script|style)\b[^>]*>.*?<\/\1>/is', '', $html) ?? '';
    $html = preg_replace('/<\s*br\s*\/?\s*>|<\/(?:p|div|tr|li|h[1-6])\s*>/i', "\n", $html) ?? '';
    return trim(html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
}

function notificationsResponse(int $status, array $body): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    echo json_encode($body, JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

/** Validate the gateway feed before rendering any message content. */
function parseNotifications(array $response): array
{
    $notifications = $response['json'];
    if ($response['error'] || !is_array($notifications) || !array_is_list($notifications)) {
        throw new RuntimeException('Notifications are unavailable. Check your gateway connection.');
    }
    foreach ($notifications as $item) {
        if (!is_array($item) || !is_string($item['ID'] ?? null)
            || !is_string($item['MS'] ?? null) || !is_string($item['MD'] ?? null)
            || !in_array($item['R'] ?? null, [0, 1, '0', '1'], true)) {
            throw new RuntimeException('The gateway returned an unexpected notification format.');
        }
    }
    usort($notifications, static fn(array $a, array $b): int => (float)($b['D'] ?? 0) <=> (float)($a['D'] ?? 0));
    return $notifications;
}

function handleNotificationsRequest(): never
{
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if ($method !== 'POST') {
        header('Allow: POST');
        notificationsResponse(405, ['error' => 'Method not allowed.']);
    }

    // A custom header requires a same-origin request (no CORS is enabled),
    // protecting the basic-authenticated mutation from cross-site forms.
    if (($_SERVER['HTTP_X_IBKR_NOTIFICATIONS'] ?? '') !== '1'
        || strtolower(trim(explode(';', $_SERVER['CONTENT_TYPE'] ?? '')[0])) !== 'application/json') {
        notificationsResponse(403, ['error' => 'Invalid notification request.']);
    }
    $input = json_decode((string)file_get_contents('php://input'), true);
    $id = is_array($input) ? ($input['id'] ?? null) : null;
    if (!is_string($id) || preg_match('/^[a-zA-Z0-9_-]{1,128}$/D', $id) !== 1) {
        notificationsResponse(400, ['error' => 'Invalid notification ID.']);
    }

    // The deployed gateway requires Content-Type and a non-empty JSON body.
    $response = apiRequest('PUT', '/fyi/notifications/' . rawurlencode($id), (object)[], true);
    $ack = $response['json'];
    if ($response['error'] || !is_array($ack) || (string)($ack['V'] ?? '') !== '1'
        || (string)($ack['P']['R'] ?? '') !== '1' || (string)($ack['P']['ID'] ?? '') !== $id) {
        notificationsResponse(502, ['error' => 'Could not mark this message as read. Close and reopen it to retry.']);
    }
    notificationsResponse(200, ['id' => $id, 'read' => true]);
}
