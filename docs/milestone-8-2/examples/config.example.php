<?php
// Store the real file outside the document root, readable only by the app user.
// Load environment variables in the existing site's protected server bootstrap.
return [
    'origin' => 'https://brief.web86.site',
    'token' => getenv('PROJECT_BRIEF_SSO_TOKEN'), // clients:sso only; never print it
    'helper' => '/srv/private/project-brief/ProjectBriefClientSso.php',
    'orderHelper' => '/srv/private/project-brief/order-project.php',
    // Optional: a SEPARATE projects:provision token of the SAME permanent source.
    // Omit it if projects are created earlier or require Project Request approval.
    'provisionToken' => getenv('PROJECT_BRIEF_PROVISION_TOKEN') ?: null,
    'provisionHelper' => '/srv/private/project-brief/provision-project.php',
    // MODX only: existing site's order model (MODX3 may use its namespaced class).
    'orderClass' => 'StudioOrder',
];
