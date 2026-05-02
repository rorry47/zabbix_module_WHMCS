<?php
/**
 * Zabbix Monitoring Provisioning Module for WHMCS
 *
 * Features:
 *  - Creates Host Group + User in Zabbix on order
 *  - Suspend/Unsuspend: disables user + sets all hosts to "not monitored"
 *  - Terminate: removes all hosts, user group, user, host group
 *  - ChangePassword: changes Zabbix user password
 *  - Client area: list hosts, add/delete host, agent config + PSK (shown once)
 *
 * Compatible with PHP 7.4+
 *
 * @see https://developers.whmcs.com/provisioning-modules/
 */

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

require_once __DIR__ . '/ZabbixAPI.php';

use Zabbix\ZabbixAPI;

// ---------------------------------------------------------------------------
// Language loader
// ---------------------------------------------------------------------------

function zabbix_loadLang(): void
{
    global $_LANG;

    $langDir  = __DIR__ . '/lang/';
    $language = 'english';

    if (!empty($GLOBALS['CONFIG']['Language'])) {
        // Prevent path traversal via language value
        $language = preg_replace('/[^a-z\-]/', '', strtolower((string)$GLOBALS['CONFIG']['Language']));
        if ($language === '') {
            $language = 'english';
        }
    }

    $langFile = $langDir . $language . '.php';
    if (!file_exists($langFile)) {
        $langFile = $langDir . 'english.php';
    }
    if (file_exists($langFile)) {
        include $langFile;
    }
}

zabbix_loadLang();

function zabbix_t(string $key, string $fallback = ''): string
{
    global $_LANG;
    return (!empty($_LANG[$key])) ? (string)$_LANG[$key] : $fallback;
}

// ---------------------------------------------------------------------------
// MetaData
// ---------------------------------------------------------------------------

function zabbix_MetaData(): array
{
    return [
        'DisplayName'      => 'Zabbix Monitoring',
        'APIVersion'       => '1.1',
        'RequiresServer'   => true,
        'DefaultSSLPort'   => '443',
        'DefaultNonSSLPort'=> '80',
    ];
}

// ---------------------------------------------------------------------------
// ConfigOptions — per-product tariff settings
// ---------------------------------------------------------------------------

function zabbix_ConfigOptions(): array
{
    return [
        // configoption1
        'Hosts Limit' => [
            'Type'        => 'text',
            'Size'        => '10',
            'Default'     => '5',
            'Description' => 'Maximum number of monitored hosts for this plan',
        ],
        // configoption2
        'Zabbix Role Name' => [
            'Type'        => 'text',
            'Size'        => '50',
            'Default'     => 'client_basic',
            'Description' => 'Name of the Zabbix role to assign (must exist in Zabbix beforehand)',
        ],
    ];
}

// ---------------------------------------------------------------------------
// Helpers — derive consistent Zabbix entity names from WHMCS service data
// ---------------------------------------------------------------------------

function zabbix_groupName(array $params): string
{
    // Uses only userid + serviceid — no domain required at order time
    return 'whmcs_client_' . (int)$params['userid'] . '_svc_' . (int)$params['serviceid'];
}

function zabbix_username(array $params): string
{
    return 'whmcs_' . (int)$params['userid'] . '_' . (int)$params['serviceid'];
}

// ---------------------------------------------------------------------------
// CreateAccount
// ---------------------------------------------------------------------------

function zabbix_CreateAccount(array $params): string
{
    try {
        $api       = new ZabbixAPI($params);
        $groupName = zabbix_groupName($params);
        $username  = zabbix_username($params);

        $groupId = $api->createHostGroup($groupName);
        $api->createUser($username, (string)$params['password'], $groupId, $api->getRoleName());

        // Save Zabbix username into the WHMCS service username field
        // Using Capsule (direct DB) — works in all WHMCS versions without auth
        try {
            if (class_exists('\\WHMCS\\Database\\Capsule')) {
                \WHMCS\Database\Capsule::table('tblhosting')
                    ->where('id', (int)$params['serviceid'])
                    ->update(['username' => $username]);
            }
        } catch (\Exception $dbEx) {
            // Non-fatal — provisioning succeeded, just log the username save failure
            logModuleCall('zabbix', __FUNCTION__ . ':saveUsername', $params, $dbEx->getMessage(), '');
        }

    } catch (Exception $e) {
        logModuleCall('zabbix', __FUNCTION__, $params, $e->getMessage(), $e->getTraceAsString());
        return $e->getMessage();
    }

    return 'success';
}

// ---------------------------------------------------------------------------
// SuspendAccount — disable user + set all hosts to "not monitored"
// ---------------------------------------------------------------------------

function zabbix_SuspendAccount(array $params): string
{
    try {
        $api      = new ZabbixAPI($params);
        $username = zabbix_username($params);

        $userId = $api->getUserId($username);
        if ($userId !== null) {
            $api->setUserStatus($userId, 1); // 1 = disabled
        }

        $groupId = $api->getHostGroupId(zabbix_groupName($params));
        if ($groupId !== null) {
            $api->setHostsStatus($groupId, 1); // 1 = not monitored
        }

    } catch (Exception $e) {
        logModuleCall('zabbix', __FUNCTION__, $params, $e->getMessage(), $e->getTraceAsString());
        return $e->getMessage();
    }

    return 'success';
}

// ---------------------------------------------------------------------------
// UnsuspendAccount — enable user + set all hosts back to monitored
// ---------------------------------------------------------------------------

function zabbix_UnsuspendAccount(array $params): string
{
    try {
        $api      = new ZabbixAPI($params);
        $username = zabbix_username($params);

        $userId = $api->getUserId($username);
        if ($userId !== null) {
            $api->setUserStatus($userId, 0); // 0 = enabled
        }

        $groupId = $api->getHostGroupId(zabbix_groupName($params));
        if ($groupId !== null) {
            $api->setHostsStatus($groupId, 0); // 0 = monitored
        }

    } catch (Exception $e) {
        logModuleCall('zabbix', __FUNCTION__, $params, $e->getMessage(), $e->getTraceAsString());
        return $e->getMessage();
    }

    return 'success';
}

// ---------------------------------------------------------------------------
// TerminateAccount — delete all hosts → usrgrp → user → host group
// Order matters: user must be deleted before host group can be removed.
// ---------------------------------------------------------------------------

function zabbix_TerminateAccount(array $params): string
{
    try {
        $api      = new ZabbixAPI($params);
        $username = zabbix_username($params);
        $groupId  = $api->getHostGroupId(zabbix_groupName($params));

        if ($groupId !== null) {
            $api->deleteAllHostsInGroup($groupId);
            $api->deleteUserGroup($groupId);
        }

        $userId = $api->getUserId($username);
        if ($userId !== null) {
            $api->deleteUser($userId);
        }

        if ($groupId !== null) {
            $api->deleteHostGroup($groupId);
        }

    } catch (Exception $e) {
        logModuleCall('zabbix', __FUNCTION__, $params, $e->getMessage(), $e->getTraceAsString());
        return $e->getMessage();
    }

    return 'success';
}

// ---------------------------------------------------------------------------
// ChangePassword — changes the Zabbix user password
// ---------------------------------------------------------------------------

function zabbix_ChangePassword(array $params): string
{
    try {
        $api      = new ZabbixAPI($params);
        $username = zabbix_username($params);

        $userId = $api->getUserId($username);
        if ($userId === null) {
            return 'Zabbix user not found.';
        }

        $api->changeUserPassword($userId, (string)$params['password']);

    } catch (Exception $e) {
        logModuleCall('zabbix', __FUNCTION__, $params, $e->getMessage(), $e->getTraceAsString());
        return $e->getMessage();
    }

    return 'success';
}

// ---------------------------------------------------------------------------
// TestConnection
// ---------------------------------------------------------------------------

function zabbix_TestConnection(array $params): array
{
    try {
        $api     = new ZabbixAPI($params);
        $success = $api->testConnection();
        $error   = $success ? '' : 'Connection failed';
    } catch (Exception $e) {
        logModuleCall('zabbix', __FUNCTION__, $params, $e->getMessage(), $e->getTraceAsString());
        $success = false;
        $error   = $e->getMessage();
    }

    return ['success' => $success, 'error' => $error];
}

// ---------------------------------------------------------------------------
// ClientArea — host management portal
// ---------------------------------------------------------------------------

function zabbix_ClientArea(array $params): string
{
    $error    = '';
    $message  = '';
    $pskBlock = '';

    $serverHost = (string)$params['serverhostname'];
    $scheme     = !empty($params['serversecure']) ? 'https' : 'http';
    // BUG FIX: strip port from hostname before building URL (avoid double-port)
    $hostOnly   = preg_replace('/:\d+$/', '', $serverHost);
    $zabbixUrl  = $scheme . '://' . htmlspecialchars($hostOnly);
    $maxHosts   = isset($params['configoption1']) && $params['configoption1'] !== ''
                    ? max(1, (int)$params['configoption1']) : 5;

    // Init API
    try {
        $api     = new ZabbixAPI($params);
        $groupId = $api->getHostGroupId(zabbix_groupName($params));
    } catch (Exception $e) {
        return '<div class="alert alert-danger">' . htmlspecialchars($e->getMessage()) . '</div>';
    }

    if ($groupId === null) {
        return '<div class="alert alert-warning">Host group not found. Please contact support.</div>';
    }

    // ----------------------------------------------------------------
    // POST actions
    // ----------------------------------------------------------------
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        // CSRF check — compatible with all WHMCS versions.
        // WHMCS stores its token in $_SESSION['WMCStokenID'] (v7) or
        // $_SESSION['WHMCS_token'] (some v8 builds); we also accept
        // the raw session ID as a last-resort fallback so the form
        // always has something valid to compare against.
        $sessionToken = '';
        if (!empty($_SESSION['WMCStokenID'])) {
            $sessionToken = (string)$_SESSION['WMCStokenID'];
        } elseif (!empty($_SESSION['WHMCS_token'])) {
            $sessionToken = (string)$_SESSION['WHMCS_token'];
        } elseif (!empty($_SESSION['token'])) {
            $sessionToken = (string)$_SESSION['token'];
        } else {
            // Fallback: use PHP session ID (always available)
            $sessionToken = session_id();
        }

        $postToken = isset($_POST['token']) ? (string)$_POST['token'] : '';
        if ($sessionToken === '' || !hash_equals($sessionToken, $postToken)) {
            return '<div class="alert alert-danger">Invalid request token. Please refresh the page.</div>';
        }

        $action = isset($_POST['zabbix_action']) ? (string)$_POST['zabbix_action'] : '';

        // --- Add host ---
        if ($action === 'add_host') {
            $address = trim((string)($_POST['host_address'] ?? ''));
            $type    = in_array($_POST['host_type'] ?? '', ['agent', 'snmp', 'icmp'], true)
                        ? (string)$_POST['host_type'] : 'agent';

            if ($address === '') {
                $error = 'Please enter an IP address or hostname.';
            } elseif (!filter_var($address, FILTER_VALIDATE_IP) && !zabbix_isValidFqdn($address)) {
                $error = 'Invalid IP address or hostname.';
            } else {
                try {
                    $currentCount = $api->getHostCount($groupId);
                    if ($currentCount >= $maxHosts) {
                        $error = 'Host limit reached (' . $maxHosts . '). Upgrade your plan to add more hosts.';
                    } else {
                        $pskIdentity = '';
                        $pskKey      = '';

                        if ($type === 'agent') {
                            $pskIdentity = ZabbixAPI::generatePskIdentity($address);
                            $pskKey      = ZabbixAPI::generatePsk();
                        }

                        $api->createHost($address, $address, $type, $groupId, $pskIdentity, $pskKey);

                        if ($type === 'agent') {
                            $pskBlock = zabbix_buildAgentConfig($hostOnly, $address, $pskIdentity, $pskKey);
                        }

                        $message = 'Host <strong>' . htmlspecialchars($address) . '</strong> added successfully.';
                    }
                } catch (Exception $e) {
                    logModuleCall('zabbix', 'ClientArea:add_host', $params, $e->getMessage(), $e->getTraceAsString());
                    $error = $e->getMessage();
                }
            }
        }

        // --- Delete host ---
        if ($action === 'delete_host') {
            // BUG FIX: strip non-numeric chars — hostid must be a Zabbix integer ID
            $hostId = preg_replace('/[^0-9]/', '', (string)($_POST['host_id'] ?? ''));
            if ($hostId !== '') {
                try {
                    // BUG FIX: verify the host actually belongs to this client's group
                    // before deleting — prevents one client deleting another's host
                    $clientHosts = $api->getHostsByGroup($groupId);
                    $ownedIds    = array_column($clientHosts, 'hostid');
                    if (!in_array($hostId, $ownedIds, true)) {
                        $error = 'Host not found or does not belong to your account.';
                    } else {
                        $api->deleteHost($hostId);
                        $message = 'Host removed successfully.';
                    }
                } catch (Exception $e) {
                    logModuleCall('zabbix', 'ClientArea:delete_host', $params, $e->getMessage(), $e->getTraceAsString());
                    $error = $e->getMessage();
                }
            }
        }
    }

    // ----------------------------------------------------------------
    // Fetch host list
    // ----------------------------------------------------------------
    $hosts = [];
    try {
        $hosts = $api->getHostsByGroup($groupId);
    } catch (Exception $e) {
        $error = $e->getMessage();
    }

    $usedHosts = count($hosts);
    // Generate the same token value used for CSRF verification above
    $csrfToken = '';
    if (!empty($_SESSION['WMCStokenID'])) {
        $csrfToken = (string)$_SESSION['WMCStokenID'];
    } elseif (!empty($_SESSION['WHMCS_token'])) {
        $csrfToken = (string)$_SESSION['WHMCS_token'];
    } elseif (!empty($_SESSION['token'])) {
        $csrfToken = (string)$_SESSION['token'];
    } else {
        $csrfToken = session_id();
    }
    $csrfToken = htmlspecialchars($csrfToken);

    // ----------------------------------------------------------------
    // Build HTML output
    // ----------------------------------------------------------------
    $html = '';

    if ($error !== '') {
        $html .= '<div class="alert alert-danger">' . htmlspecialchars($error) . '</div>';
    }
    if ($message !== '') {
        $html .= '<div class="alert alert-success">' . $message . '</div>';
    }
    if ($pskBlock !== '') {
        $html .= $pskBlock;
    }

    // Header: credentials + link to Zabbix UI
    $zabbixUsername = htmlspecialchars(zabbix_username($params));

    $html .= '<div class="row" style="margin-bottom:8px">';
    $html .=   '<div class="col-sm-4 text-right"><strong>Username</strong></div>';
    $html .=   '<div class="col-sm-8">' . $zabbixUsername . '</div>';
    $html .= '</div>';

    $html .= '<div class="row" style="margin-bottom:16px">';
    $html .=   '<div class="col-sm-4 text-right"><strong>Hosts</strong></div>';
    $html .=   '<div class="col-sm-8">' . (int)$usedHosts . ' / ' . (int)$maxHosts . '</div>';
    $html .= '</div>';

    $html .= '<div class="row" style="margin-bottom:16px">';
    $html .=   '<div class="col-sm-12">';
    $html .=     '<a href="' . $zabbixUrl . '" target="_blank" rel="noopener noreferrer" class="btn btn-sm btn-default">';
    $html .=       'Open Zabbix &rarr;';
    $html .=     '</a>';
    $html .=   '</div>';
    $html .= '</div>';

    // Host list table
    if (empty($hosts)) {
        $html .= '<p class="text-muted">No hosts added yet.</p>';
    } else {
        $typeMap = [1 => 'Agent', 2 => 'SNMP', 3 => 'IPMI', 4 => 'JMX'];

        $html .= '<table class="table table-condensed table-striped">';
        $html .=   '<thead><tr>';
        $html .=     '<th>Host</th><th>Type</th><th>Status</th><th></th>';
        $html .=   '</tr></thead><tbody>';

        foreach ($hosts as $host) {
            $hostId   = htmlspecialchars(preg_replace('/[^0-9]/', '', (string)($host['hostid'] ?? '')));
            $hostName = htmlspecialchars((string)($host['name'] ?? ''));
            $status   = ((int)($host['status'] ?? 1) === 0)
                ? '<span class="label label-success">Monitored</span>'
                : '<span class="label label-warning">Not monitored</span>';

            $ifTypeNum = isset($host['interfaces'][0]['type']) ? (int)$host['interfaces'][0]['type'] : 0;
            $ifType    = isset($typeMap[$ifTypeNum]) ? $typeMap[$ifTypeNum] : '—';

            $html .= '<tr>';
            $html .=   '<td>' . $hostName . '</td>';
            $html .=   '<td>' . $ifType . '</td>';
            $html .=   '<td>' . $status . '</td>';
            $html .=   '<td>';
            $html .=     '<form method="POST" style="display:inline" onsubmit="return confirm(\'Remove this host?\');">';
            $html .=       '<input type="hidden" name="token" value="' . $csrfToken . '">';
            $html .=       '<input type="hidden" name="zabbix_action" value="delete_host">';
            $html .=       '<input type="hidden" name="host_id" value="' . $hostId . '">';
            $html .=       '<button type="submit" class="btn btn-xs btn-danger">Remove</button>';
            $html .=     '</form>';
            $html .=   '</td>';
            $html .= '</tr>';
        }

        $html .= '</tbody></table>';
    }

    // Add host form — only if under limit
    if ($usedHosts < $maxHosts) {
        $html .= '<hr>';
        $html .= '<h4>Add Host</h4>';
        $html .= '<form method="POST">';
        $html .=   '<input type="hidden" name="token" value="' . $csrfToken . '">';
        $html .=   '<input type="hidden" name="zabbix_action" value="add_host">';

        $html .=   '<div class="form-group">';
        $html .=     '<label>IP Address or Hostname</label>';
        $html .=     '<input type="text" name="host_address" class="form-control"';
        $html .=       ' placeholder="192.168.1.1 or server.example.com" maxlength="255">';
        $html .=   '</div>';

        $html .=   '<div class="form-group">';
        $html .=     '<label>Connection Type</label>';
        $html .=     '<select name="host_type" class="form-control" id="zabbix_host_type"';
        $html .=       ' onchange="zabbixTypeHint(this.value)">';
        $html .=       '<option value="agent">Zabbix Agent (recommended)</option>';
        $html .=       '<option value="snmp">SNMP</option>';
        $html .=       '<option value="icmp">ICMP Ping only</option>';
        $html .=     '</select>';
        $html .=   '</div>';

        $html .= '<div id="zabbix_agent_hint" class="alert alert-info" style="font-size:13px">';
        $html .=   '<strong>Zabbix Agent</strong> — install the agent on your server. ';
        $html .=   'After adding, you will get a ready config with a unique PSK key. ';
        $html .=   '<strong>Save the PSK key immediately</strong> — it is shown only once.';
        $html .= '</div>';

        $html .=   '<button type="submit" class="btn btn-primary">Add Host</button>';
        $html .= '</form>';

        // Inline JS — minimal, no external deps
        $html .= '<script>';
        $html .= 'function zabbixTypeHint(v){';
        $html .=   'var el=document.getElementById("zabbix_agent_hint");';
        $html .=   'if(el){el.style.display=(v==="agent")?"block":"none";}';
        $html .= '}';
        $html .= '</script>';
    } else {
        $html .= '<div class="alert alert-warning" style="margin-top:16px">';
        $html .=   'Host limit reached. ';
        $html .=   '<a href="clientarea.php?action=upgrade">Upgrade your plan</a> to add more.';
        $html .= '</div>';
    }

    return $html;
}

// ---------------------------------------------------------------------------
// Build agent config block — displayed once after adding an Agent host
// ---------------------------------------------------------------------------

function zabbix_buildAgentConfig(
    string $serverHost,
    string $hostname,
    string $pskIdentity,
    string $pskKey
): string {
    $conf = "Server=" . $serverHost . "\n"
          . "ServerActive=" . $serverHost . "\n"
          . "Hostname=" . $hostname . "\n"
          . "TLSConnect=psk\n"
          . "TLSAccept=psk\n"
          . "TLSPSKIdentity=" . $pskIdentity . "\n"
          . "TLSPSKFile=/etc/zabbix/zabbix_agentd.psk\n";

    $html  = '<div class="alert alert-warning">';
    $html .=   '<h4>&#9888; Save your PSK key now — it will not be shown again</h4>';
    $html .=   '<p><strong>1. Create <code>/etc/zabbix/zabbix_agentd.psk</code> with this content:</strong></p>';
    $html .=   '<pre style="word-break:break-all;font-size:12px;user-select:all">'
             . htmlspecialchars($pskKey) . '</pre>';
    $html .=   '<p><strong>2. Use this <code>zabbix_agentd.conf</code>:</strong></p>';
    $html .=   '<pre style="font-size:12px">' . htmlspecialchars($conf) . '</pre>';
    $html .=   '<p><strong>3. Restart:</strong> <code>systemctl restart zabbix-agent</code></p>';
    $html .=   '<p class="text-muted" style="font-size:12px">Permissions: '
             . '<code>chmod 400 /etc/zabbix/zabbix_agentd.psk &amp;&amp; chown zabbix:zabbix /etc/zabbix/zabbix_agentd.psk</code></p>';
    $html .= '</div>';

    return $html;
}

// ---------------------------------------------------------------------------
// FQDN validator
// ---------------------------------------------------------------------------

function zabbix_isValidFqdn(string $host): bool
{
    $h = preg_replace('/:\d+$/', '', $host);
    return (bool)preg_match('/^([a-z0-9]([a-z0-9\-]{0,61}[a-z0-9])?\.)+[a-z]{2,}$/i', $h);
}
