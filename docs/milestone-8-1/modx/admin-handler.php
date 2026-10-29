<?php
// MODX Manager processor/connector body, never a public form action or unauthenticated snippet.
if (!isset($modx) || !($modx instanceof modX) || !$modx->user->isAuthenticated('mgr') || !$modx->hasPermission('access_permissions')) {
    http_response_code(403); return;
}
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || !is_string($_POST['csrf'] ?? null) || !is_string($_SESSION['project_brief_admin_csrf'] ?? null) || !hash_equals($_SESSION['project_brief_admin_csrf'], $_POST['csrf'])) {
    http_response_code(403); return;
}
foreach (['requestId', 'operationKey', 'action', 'reason', 'sendInvitation'] as $field) {
    if (isset($_POST[$field]) && !is_string($_POST[$field])) { http_response_code(422); return; }
}
require_once MODX_CORE_PATH.'components/projectbrief/ProjectBriefRequests.php';
$config = require MODX_CORE_PATH.'config/project-brief.private.php';
try {
    $id = (string) ($_POST['requestId'] ?? '');
    $key = (string) ($_POST['operationKey'] ?? ''); // Generate once per decision; retain across network retries.
    $api = new ProjectBriefRequests($config['origin'], $config['decisionToken']);
    $action = $_POST['action'] ?? '';
    if ($action === 'approve') {
        $result = $api->approve($id, ($_POST['sendInvitation'] ?? '1') === '1', $key);
    } elseif ($action === 'reject') {
        $result = $api->reject($id, isset($_POST['reason']) ? (string) $_POST['reason'] : null, $key);
    } else { http_response_code(422); return; }
    header('Content-Type: application/json; charset=utf-8'); header('Cache-Control: no-store');
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
} catch (Throwable $error) {
    http_response_code($error instanceof RuntimeException && in_array($error->getCode(), [401,403,404,409,422,429], true) ? $error->getCode() : 502);
    header('Content-Type: application/json; charset=utf-8'); header('Cache-Control: no-store');
    echo json_encode(['error' => 'Не удалось выполнить действие. Проверьте актуальный статус заявки перед повтором.']);
}
