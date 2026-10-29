<?php
// Integrate into the EXISTING authenticated account controller, before output.
// $pdo and the authenticated session come from that site's normal bootstrap.
// Never expose this as a separate anonymous entry point.
if (session_status() !== PHP_SESSION_ACTIVE || empty($_SESSION['user_id'])) {
    http_response_code(401); exit;
}
$config = require '/srv/private/project-brief/config.php';
require_once $config['helper'];
if (empty($_SESSION['csrf'])) { $_SESSION['csrf'] = bin2hex(random_bytes(32)); }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    // The existing account layout can render this form instead.
    $csrf = htmlspecialchars((string) ($_SESSION['csrf'] ?? ''), ENT_QUOTES, 'UTF-8');
    echo '<form method="post"><input type="hidden" name="csrf" value="'.$csrf.'"><button>Open project</button></form>';
    return;
}
if (empty($_SESSION['csrf']) || !is_string($_POST['csrf'] ?? null) || !hash_equals($_SESSION['csrf'], $_POST['csrf'])) {
    http_response_code(403); exit;
}
// Adapt column names to the existing schema. email_verified_at must be cleared
// on every email change and set only by the existing mailbox verification flow.
$query = $pdo->prepare('SELECT id, email, email_verified_at FROM users WHERE id = ? AND active = 1');
$query->execute([$_SESSION['user_id']]);
$user = $query->fetch(PDO::FETCH_ASSOC);
$projectId = $user ? ($config['projects'][(int) $user['id']] ?? null) : null;
if (!$projectId || empty($user['email_verified_at'])) {
    http_response_code(403); echo 'Use the normal project email sign-in.'; return;
}
try {
    $api = new ProjectBriefClientSso($config['origin'], $config['token']);
    $api->transferPage($api->issue($projectId, 'site-user:'.$user['id'], $user['email'], true));
} catch (Throwable $error) {
    http_response_code(503); echo 'Sign-in is unavailable. Use the normal project email sign-in.';
}
