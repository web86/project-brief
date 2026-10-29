<?php
// A trusted server-side CRM/Manager handler, after its own authorization. Never expose PII publicly.
if (!isset($modx) || !($modx instanceof modX) || !$modx->user->isAuthenticated('mgr') || !$modx->hasPermission('access_permissions')) { http_response_code(403); return; }
require_once MODX_CORE_PATH.'components/projectbrief/ProjectBriefRequests.php';
$config = require MODX_CORE_PATH.'config/project-brief.private.php';
try {
    $api = new ProjectBriefRequests($config['origin'], $config['readToken']);
    $result = $api->listing(['status' => 'pending', 'page' => 1, 'perPage' => 25, 'sort' => 'desc']);
    header('Content-Type: application/json; charset=utf-8'); header('Cache-Control: no-store');
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
} catch (Throwable $error) { http_response_code(502); }
