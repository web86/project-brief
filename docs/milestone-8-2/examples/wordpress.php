<?php
// Include in existing trusted server code, before output. No plugin/nopriv route.
// Existing account template: POST to admin_url('admin-post.php'), hidden action
// "project_brief_open", wp_nonce_field('project_brief_open'), Open project button.
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
    $projectId = $config['projects'][(int) $user->ID] ?? null;
    if (!$projectId || !$verifiedAt || !$email || !hash_equals($verifiedEmail, $email)) {
        wp_die('Use the normal project email sign-in.', '', ['response' => 403]);
    }
    try {
        $api = new ProjectBriefClientSso($config['origin'], $config['token']);
        $api->transferPage($api->issue($projectId, 'wordpress-user:'.$user->ID, $email, true));
        exit;
    } catch (Throwable $error) {
        wp_die('Sign-in is unavailable. Use the normal project email sign-in.', '', ['response' => 503]);
    }
});
