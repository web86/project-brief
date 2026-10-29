<?php
// MODX 2 uncached snippet, used inside a FormIt form after the site's normal session setup.
if (!isset($modx) || !($modx instanceof modX)) { return ''; }
$id = bin2hex(random_bytes(16));
$_SESSION['project_brief_submissions'][$id] = time();
foreach ($_SESSION['project_brief_submissions'] as $key => $at) {
    if ($at < time() - 86400) { unset($_SESSION['project_brief_submissions'][$key]); }
}
while (count($_SESSION['project_brief_submissions']) > 20) { array_shift($_SESSION['project_brief_submissions']); }
return '<input type="hidden" name="pbSubmissionId" value="'.htmlspecialchars($id, ENT_QUOTES, 'UTF-8').'">';
