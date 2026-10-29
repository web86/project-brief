<?php
// Store the real file outside the document root, readable only by the app user.
// Load environment variables in the existing site's protected server bootstrap.
return [
    'origin' => 'https://brief.web86.site',
    'token' => getenv('PROJECT_BRIEF_SSO_TOKEN'), // clients:sso only; never print it
    'helper' => '/srv/private/project-brief/ProjectBriefClientSso.php',
    // Persist the UUID returned by provisioning/approval against an immutable user ID.
    'projects' => [42 => 'REPLACE_WITH_SAVED_PROJECT_UUID'],
];
