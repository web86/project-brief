<?php
// Copy to core/config/project-brief.private.php, outside the public document root.
// Read secrets from hosting environment or a protected non-public file; never commit actual values.
return [
    'origin' => 'https://brief.web86.site',
    'createToken' => (string) getenv('PROJECT_BRIEF_REQUEST_CREATE_TOKEN'),
    'readToken' => (string) getenv('PROJECT_BRIEF_REQUEST_READ_TOKEN'),
    'decisionToken' => (string) getenv('PROJECT_BRIEF_REQUEST_DECISION_TOKEN'),
];
