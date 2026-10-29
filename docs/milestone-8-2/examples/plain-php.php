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
    $csrf = htmlspecialchars($_SESSION['csrf'], ENT_QUOTES, 'UTF-8');
    $query = $pdo->prepare('SELECT id, title FROM orders WHERE user_id = ? ORDER BY id');
    $query->execute([$_SESSION['user_id']]);
    foreach ($query->fetchAll(PDO::FETCH_ASSOC) as $order) {
        echo '<form method="post"><input type="hidden" name="csrf" value="'.$csrf.'"><input type="hidden" name="order_id" value="'.(int) $order['id'].'"><span>'.htmlspecialchars($order['title'], ENT_QUOTES, 'UTF-8').'</span> <button>Open project</button></form>';
    }
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
if (!$user || empty($user['email_verified_at'])) {
    http_response_code(403); echo 'Use the normal project email sign-in.'; return;
}
try {
    require_once $config['orderHelper'];
    $orderId = filter_var($_POST['order_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if (!$orderId) { http_response_code(404); return; }
    if (!$pdo->beginTransaction()) { throw new RuntimeException('Order lock failed.'); }
    // Adapt existing order table/columns. The owner predicate is mandatory.
    $query = $pdo->prepare('SELECT * FROM orders WHERE id = ? AND user_id = ? FOR UPDATE');
    $query->execute([$orderId, $user['id']]);
    $order = $query->fetch(PDO::FETCH_ASSOC);
    if (!$order) { $pdo->rollBack(); http_response_code(404); return; }
    $projectId = projectBriefOrderProject($order, $config);
    if (!$pdo->prepare('UPDATE orders SET pb_project_id = ? WHERE id = ? AND user_id = ?')->execute([$projectId, $orderId, $user['id']]) || !$pdo->commit()) { throw new RuntimeException('Save failed.'); }
    $api = new ProjectBriefClientSso($config['origin'], $config['token']);
    $api->transferPage($api->issue($projectId, 'site-user:'.$user['id'], $user['email'], true));
} catch (Throwable $error) {
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
    http_response_code(503); echo 'Sign-in is unavailable. Use the normal project email sign-in.';
}
