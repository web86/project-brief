<?php
// FormIt post-validation server hook. Keep existing CSRF, anti-spam and form rate controls enabled.
if (!isset($modx, $hook) || !($modx instanceof modX)) { return false; }
require_once MODX_CORE_PATH.'components/projectbrief/ProjectBriefRequests.php';
$config = require MODX_CORE_PATH.'config/project-brief.private.php';
$values = $hook->getValues();
foreach (['name', 'email', 'company', 'website', 'project_name', 'description', 'pbSubmissionId'] as $field) {
    if (isset($values[$field]) && !is_string($values[$field])) {
        $hook->addError('form', 'Проверьте поля формы.');
        return false;
    }
}
$id = $values['pbSubmissionId'] ?? '';
if (!is_string($id) || !preg_match('/^[a-f0-9]{32}$/D', $id) || !isset($_SESSION['project_brief_submissions'][$id]) || $_SESSION['project_brief_submissions'][$id] < time() - 86400) {
    $hook->addError('form', 'Не удалось отправить заявку. Обновите страницу и попробуйте ещё раз.');
    return false;
}
$input = [
    'externalId' => 'web86-'.$id,
    'client' => ['name' => (string) ($values['name'] ?? ''), 'email' => (string) ($values['email'] ?? ''), 'company' => (string) ($values['company'] ?? ''), 'website' => (string) ($values['website'] ?? '')],
    'project' => ['name' => (string) ($values['project_name'] ?? 'Заявка с web86.site'), 'description' => (string) ($values['description'] ?? ''), 'language' => 'ru'],
];
try {
    $api = new ProjectBriefRequests($config['origin'], $config['createToken']);
    $result = $api->create($input, 'request-'.$id);
    // Retain the submission ID for safe repeats. Do not log input, bearer secrets or API response.
    $hook->setValue('projectBriefRequestId', $result['data']['requestId']);
    return true; // FormIt may redirect to a neutral receipt page: «Заявка принята на рассмотрение».
} catch (Throwable $error) {
    $hook->addError('form', 'Не удалось отправить заявку. Повторите попытку позже.');
    return false;
}
