<?php
/**
 * Call with an OWNED, locked record from the existing order/account repository.
 * Map its saved immutable provisioning snapshot to the fields below. No new table.
 * Persist the returned UUID and commit BEFORE asking for an SSO code.
 */
function projectBriefOrderProject(array $order, array $config): string
{
    if (!empty($order['pb_project_id'])) {
        if (!is_string($order['pb_project_id']) || !preg_match('/^[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}$/Di', $order['pb_project_id'])) {
            throw new RuntimeException('Invalid saved project UUID.');
        }
        return $order['pb_project_id'];
    }
    // Never bypass a pending/rejected Project Request with direct provisioning.
    if (!empty($order['pb_request_id']) || empty($config['provisionToken'])) {
        throw new RuntimeException('Project is awaiting approval or provisioning.');
    }
    require_once $config['provisionHelper'];
    // provision_input is the immutable snapshot already stored by the order flow.
    // Keep the exact externalId/body/key and credential for uncertain retries.
    $input = json_decode($order['provision_input'], true, 32, JSON_THROW_ON_ERROR);
    $result = provisionProject($config['origin'], $config['provisionToken'],
        $input['externalId'], $input['client'], $input['project'], $input['sendInvitation'] ?? false, $config['ca'] ?? null);
    if (!preg_match('/^[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}$/Di', $result['projectId'] ?? '')) {
        throw new RuntimeException('Invalid provisioning result.');
    }
    return $result['projectId'];
}
