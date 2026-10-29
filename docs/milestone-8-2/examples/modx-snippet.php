<?php
// Raw snippet body (omit <?php when pasting). Shared by MODX 2 and MODX 3.
// Place uncached on an existing account resource with no output before execution.
// Uses the existing web-context login; no registration or Manager session is added.
$context = $modx->context->get('key');
if (!$modx->user->isAuthenticated($context) || !$modx->user->get('active')) {
    http_response_code(401); return 'Sign in to your existing account.';
}
if (session_status() !== PHP_SESSION_ACTIVE) { http_response_code(403); return ''; }
if (empty($_SESSION['pb_sso_csrf'])) { $_SESSION['pb_sso_csrf'] = bin2hex(random_bytes(32)); }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    return '<form method="post"><input type="hidden" name="csrf" value="'.htmlspecialchars($_SESSION['pb_sso_csrf'], ENT_QUOTES, 'UTF-8').'"><button>Open project</button></form>';
}
if (!is_string($_POST['csrf'] ?? null) || !hash_equals($_SESSION['pb_sso_csrf'], $_POST['csrf'])) {
    http_response_code(403); return '';
}
$config = require '/srv/private/project-brief/config.php';
require_once $config['helper'];
$profile = $modx->user->getOne('Profile');
$extended = $profile ? $profile->get('extended') : [];
if (!$profile || $profile->get('blocked')) { http_response_code(403); return ''; }
$email = $profile ? strtolower(trim((string) $profile->get('email'))) : '';
// Provision these two fields ONLY through the site's existing verified-mail flow.
// Exclude both from all public profile update allowlists. A login alone is NOT proof.
$verified = $email !== '' && !empty($extended['pb_verified_at']) &&
    hash_equals((string) ($extended['pb_verified_email'] ?? ''), $email);
$projectId = $config['projects'][(int) $modx->user->get('id')] ?? null;
if (!$verified || !$projectId) { http_response_code(403); return 'Use the normal project email sign-in.'; }
try {
    $api = new ProjectBriefClientSso($config['origin'], $config['token']);
    $api->transferPage($api->issue($projectId, 'modx-user:'.$modx->user->get('id'), $email, true));
    exit;
} catch (Throwable $error) {
    http_response_code(503); return 'Sign-in is unavailable. Use the normal project email sign-in.';
}
