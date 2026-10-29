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
$config = require '/srv/private/project-brief/config.php';
$table = $modx->getTableName($config['orderClass']);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $query = $modx->prepare('SELECT id, title FROM '.$table.' WHERE user_id = ? ORDER BY id');
    $query->execute([$modx->user->get('id')]);
    $html = '';
    foreach ($query->fetchAll(PDO::FETCH_ASSOC) as $order) {
        $html .= '<form method="post"><input type="hidden" name="csrf" value="'.htmlspecialchars($_SESSION['pb_sso_csrf'], ENT_QUOTES, 'UTF-8').'"><input type="hidden" name="order_id" value="'.(int) $order['id'].'"><span>'.htmlspecialchars($order['title'], ENT_QUOTES, 'UTF-8').'</span> <button>Open project</button></form>';
    }
    return $html;
}
if (!is_string($_POST['csrf'] ?? null) || !hash_equals($_SESSION['pb_sso_csrf'], $_POST['csrf'])) {
    http_response_code(403); return '';
}
require_once $config['helper'];
$profile = $modx->user->getOne('Profile');
$extended = $profile ? $profile->get('extended') : [];
if (!$profile || $profile->get('blocked')) { http_response_code(403); return ''; }
$email = $profile ? strtolower(trim((string) $profile->get('email'))) : '';
// Provision these two fields ONLY through the site's existing verified-mail flow.
// Exclude both from all public profile update allowlists. A login alone is NOT proof.
$verified = $email !== '' && !empty($extended['pb_verified_at']) &&
    hash_equals((string) ($extended['pb_verified_email'] ?? ''), $email);
if (!$verified) { http_response_code(403); return 'Use the normal project email sign-in.'; }
$inTransaction = false;
try {
    require_once $config['orderHelper'];
    $orderId = filter_var($_POST['order_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if (!$orderId) { http_response_code(404); return ''; }
    // Existing xPDO order model, not a new package/table. Adapt field names.
    if ($modx->exec('START TRANSACTION') === false) { throw new RuntimeException('Order lock failed.'); }
    $inTransaction = true;
    $query = $modx->prepare('SELECT * FROM '.$table.' WHERE id = ? AND user_id = ? FOR UPDATE');
    $query->execute([$orderId, $modx->user->get('id')]);
    $order = $query->fetch(PDO::FETCH_ASSOC);
    if (!$order) { $modx->exec('ROLLBACK'); $inTransaction = false; http_response_code(404); return ''; }
    $projectId = projectBriefOrderProject($order, $config);
    $query = $modx->prepare('UPDATE '.$table.' SET pb_project_id = ? WHERE id = ? AND user_id = ?');
    if (!$query->execute([$projectId, $orderId, $modx->user->get('id')]) || $modx->exec('COMMIT') === false) { throw new RuntimeException('Save failed.'); }
    $inTransaction = false;
    $api = new ProjectBriefClientSso($config['origin'], $config['token']);
    $api->transferPage($api->issue($projectId, 'modx-user:'.$modx->user->get('id'), $email, true));
    exit;
} catch (Throwable $error) {
    if ($inTransaction) { $modx->exec('ROLLBACK'); }
    http_response_code(503); return 'Sign-in is unavailable. Use the normal project email sign-in.';
}
