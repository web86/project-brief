<?php
// Include in existing trusted server code, before output. No plugin/nopriv route.
// Existing account template: POST to admin_url('admin-post.php'), hidden action
// "project_brief_open", order_id from the OWNED order list,
// wp_nonce_field('project_brief_open'), Open project button for each order.
function project_brief_order_buttons(): void
{
    if (!is_user_logged_in()) { return; }
    global $wpdb;
    $orders = $wpdb->get_results($wpdb->prepare("SELECT id, title FROM {$wpdb->prefix}orders WHERE user_id = %d ORDER BY id", get_current_user_id()), ARRAY_A);
    foreach ($orders as $order) {
        echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="project_brief_open"><input type="hidden" name="order_id" value="'.(int) $order['id'].'">'.wp_nonce_field('project_brief_open', '_wpnonce', true, false).'<span>'.esc_html($order['title']).'</span> <button>Open project</button></form>';
    }
}
add_action('admin_post_project_brief_open', static function (): void {
    if (!is_user_logged_in() || $_SERVER['REQUEST_METHOD'] !== 'POST') { wp_die('Sign in first.', '', ['response' => 403]); }
    check_admin_referer('project_brief_open');
    $user = wp_get_current_user();
    $email = strtolower(trim($user->user_email));
    // WordPress login/account registration does not guarantee verified email.
    // These protected meta values must be written only by the site's existing
    // mailbox verification process, never by a public profile/REST update.
    $verifiedEmail = (string) get_user_meta($user->ID, '_pb_verified_email', true);
    $verifiedAt = get_user_meta($user->ID, '_pb_verified_at', true);
    $config = require '/srv/private/project-brief/config.php';
    require_once $config['helper'];
    if (!$verifiedAt || !$email || !hash_equals($verifiedEmail, $email)) {
        wp_die('Use the normal project email sign-in.', '', ['response' => 403]);
    }
    global $wpdb;
    $inTransaction = false;
    try {
        require_once $config['orderHelper'];
        $orderId = filter_var($_POST['order_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if (!$orderId) { wp_die('Order unavailable.', '', ['response' => 404]); }
        // Adapt this to your EXISTING order repository/table. Use transactional
        // storage and lock the owned order; never look up arbitrary project IDs.
        $table = $wpdb->prefix.'orders';
        if ($wpdb->query('START TRANSACTION') === false) { throw new RuntimeException('Order lock failed.'); }
        $inTransaction = true;
        $order = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id = %d AND user_id = %d FOR UPDATE", $orderId, $user->ID), ARRAY_A);
        if (!$order) { throw new RuntimeException('Order unavailable.'); }
        $projectId = projectBriefOrderProject($order, $config);
        if ($wpdb->update($table, ['pb_project_id' => $projectId], ['id' => $orderId, 'user_id' => $user->ID]) === false || $wpdb->query('COMMIT') === false) { throw new RuntimeException('Save the project result before sign-in.'); }
        $inTransaction = false;
        $api = new ProjectBriefClientSso($config['origin'], $config['token']);
        $api->transferPage($api->issue($projectId, 'wordpress-user:'.$user->ID, $email, true));
        exit;
    } catch (Throwable $error) {
        if ($inTransaction) { $wpdb->query('ROLLBACK'); }
        wp_die('Sign-in is unavailable. Use the normal project email sign-in.', '', ['response' => 503]);
    }
});
