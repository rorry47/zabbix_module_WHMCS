<?php
namespace Zabbix;

/**
 * Zabbix JSON-RPC API Client
 *
 * Compatible with PHP 7.4+
 * Zabbix API v6.0+
 */
class ZabbixAPI
{
    /** @var string */
    private $url;

    /** @var string */
    private $apiToken;

    /** @var int */
    private $requestId = 1;

    /** @var int Max hosts allowed — from configoption1 */
    private $maxHosts;

    /** @var string Zabbix role name — from configoption2 */
    private $roleName;

    // -----------------------------------------------------------------------
    // Constructor
    // -----------------------------------------------------------------------
    public function __construct(array $params)
    {
        if (empty($params['serveraccesshash'])) {
            throw new \Exception('Zabbix API token is not provided.');
        }

        $hostname = isset($params['serverhostname']) ? trim((string)$params['serverhostname']) : '';
        if (empty($hostname) || !self::_isValidHostname($hostname)) {
            throw new \Exception('Invalid or missing server hostname.');
        }

        // BUG FIX: strip port from hostname before building URL to avoid
        // double-port like https://host:443:443/api_jsonrpc.php
        $hostOnly = preg_replace('/:\d+$/', '', $hostname);
        $scheme   = !empty($params['serversecure']) ? 'https' : 'http';

        // Respect custom port if present in hostname (e.g. host:8443)
        $port = '';
        if (preg_match('/:\d+$/', $hostname, $m)) {
            $port = $m[0]; // includes the colon, e.g. ":8443"
        }

        $this->url = $scheme . '://' . $hostOnly . $port . '/api_jsonrpc.php';

        $this->apiToken = trim((string)$params['serveraccesshash']);
        $this->maxHosts = self::_posInt($params, 'configoption1', 5);
        $this->roleName = isset($params['configoption2']) ? trim((string)$params['configoption2']) : '';
    }

    // -----------------------------------------------------------------------
    // Input helpers
    // -----------------------------------------------------------------------

    private static function _posInt(array $params, string $key, int $default): int
    {
        if (isset($params[$key]) && $params[$key] !== '') {
            $v = (int)$params[$key];
            return $v > 0 ? $v : $default;
        }
        return $default;
    }

    /**
     * Validates hostname or IPv4/IPv6 address.
     * Blocks: localhost, loopback, link-local, private ranges (SSRF protection).
     */
    private static function _isValidHostname(string $host): bool
    {
        $hostOnly = preg_replace('/:\d+$/', '', $host);

        // IPv4 validation + SSRF block
        if (filter_var($hostOnly, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            // Block loopback and private ranges
            if (filter_var($hostOnly, FILTER_VALIDATE_IP,
                FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                return true;
            }
            return false;
        }

        // IPv6
        if (filter_var($hostOnly, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            return true;
        }

        // Hostname
        if (preg_match('/^([a-z0-9]([a-z0-9\-]{0,61}[a-z0-9])?\.)+[a-z]{2,}$/i', $hostOnly)) {
            // Block localhost variants
            if (strtolower($hostOnly) === 'localhost') {
                return false;
            }
            return true;
        }

        return false;
    }

    // -----------------------------------------------------------------------
    // JSON-RPC request
    // -----------------------------------------------------------------------

    /**
     * @param string $method  Zabbix API method, e.g. "host.create"
     * @param array  $params  Method parameters
     * @return mixed          Decoded result
     * @throws \Exception     On transport error or Zabbix API error
     */
    public function call(string $method, array $params = [])
    {
        $payload = json_encode([
            'jsonrpc' => '2.0',
            'method'  => $method,
            'params'  => $params,
            'id'      => $this->requestId++,
        ]);

        if ($payload === false) {
            throw new \Exception('Failed to encode JSON payload: ' . json_last_error_msg());
        }

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $this->url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            // BUG FIX: API token must not contain newlines (header injection guard)
            'Authorization: Bearer ' . str_replace(["\r", "\n"], '', $this->apiToken),
        ]);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        // BUG FIX: limit redirects and block redirect to other hosts
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);

        $response = curl_exec($ch);

        // BUG FIX: curl_exec returns false on failure — check explicitly
        if ($response === false || curl_errno($ch)) {
            $err = curl_error($ch);
            curl_close($ch);
            throw new \Exception('cURL error: ' . $err);
        }

        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        // BUG FIX: check HTTP status before attempting JSON parse
        if ($httpCode === 401) {
            throw new \Exception('Zabbix API: authentication failed. Check your API token.');
        }
        if ($httpCode === 403) {
            throw new \Exception('Zabbix API: access forbidden. Token may lack required permissions.');
        }
        if ($httpCode >= 500) {
            throw new \Exception('Zabbix API: server error (HTTP ' . $httpCode . ').');
        }

        $decoded = json_decode($response, true);
        if ($decoded === null) {
            throw new \Exception('Invalid JSON response from Zabbix API.');
        }

        if (isset($decoded['error'])) {
            $code = isset($decoded['error']['code']) ? (int)$decoded['error']['code'] : 0;
            $data = isset($decoded['error']['data']) ? (string)$decoded['error']['data'] : 'unknown error';
            throw new \Exception('Zabbix API error [' . $code . ']: ' . $data);
        }

        // BUG FIX: 'result' key must exist — guard against unexpected responses
        if (!array_key_exists('result', $decoded)) {
            throw new \Exception('Zabbix API: unexpected response format (no result key).');
        }

        return $decoded['result'];
    }

    // -----------------------------------------------------------------------
    // Test connection
    // -----------------------------------------------------------------------

    /**
     * apiinfo.version must be called WITHOUT Authorization header.
     * Then we verify the token works by calling user.checkAuthentication.
     */
    public function testConnection(): bool
    {
        // Step 1: check API is reachable (no auth required)
        $payload = json_encode([
            'jsonrpc' => '2.0',
            'method'  => 'apiinfo.version',
            'params'  => [],
            'id'      => 1,
        ]);

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $this->url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);

        $response = curl_exec($ch);
        $errno    = curl_errno($ch);
        curl_close($ch);

        if ($response === false || $errno) {
            return false;
        }

        $decoded = json_decode($response, true);
        if (!isset($decoded['result'])) {
            return false;
        }

        // Step 2: verify the token is valid by making an authenticated call
        $this->call('user.get', ['output' => ['userid'], 'limit' => 1]);

        return true;
    }

    // -----------------------------------------------------------------------
    // Host Groups
    // -----------------------------------------------------------------------

    public function createHostGroup(string $name): string
    {
        $result = $this->call('hostgroup.create', ['name' => $name]);
        if (empty($result['groupids'][0])) {
            throw new \Exception('Failed to create host group: empty groupid in response.');
        }
        return (string)$result['groupids'][0];
    }

    public function getHostGroupId(string $name): ?string
    {
        $result = $this->call('hostgroup.get', [
            'filter' => ['name' => [$name]],
            'output' => ['groupid'],
        ]);
        return (!empty($result) && isset($result[0]['groupid']))
            ? (string)$result[0]['groupid']
            : null;
    }

    public function deleteHostGroup(string $groupId): void
    {
        $this->call('hostgroup.delete', [$groupId]);
    }

    // -----------------------------------------------------------------------
    // Users
    // -----------------------------------------------------------------------

    public function createUser(string $username, string $password, string $groupId, string $roleName): string
    {
        if (empty($roleName)) {
            throw new \Exception('Zabbix role name is not configured for this product.');
        }

        $roleId = $this->getRoleId($roleName);
        if ($roleId === null) {
            throw new \Exception("Zabbix role '{$roleName}' not found. Please create it in Zabbix first.");
        }

        $usrGrpId = $this->_getOrCreateUserGroup($groupId);

        $result = $this->call('user.create', [
            'username' => $username,
            'passwd'   => $password,
            'roleid'   => $roleId,
            'usrgrps'  => [['usrgrpid' => $usrGrpId]],
        ]);

        if (empty($result['userids'][0])) {
            throw new \Exception('Failed to create user: empty userid in response.');
        }

        return (string)$result['userids'][0];
    }

    public function getUserId(string $username): ?string
    {
        $result = $this->call('user.get', [
            'filter' => ['username' => [$username]],
            'output' => ['userid'],
        ]);
        return (!empty($result) && isset($result[0]['userid']))
            ? (string)$result[0]['userid']
            : null;
    }

    /**
     * Enable (0) or disable (1) a user.
     *
     * Zabbix 6.0+ removed 'status' from user.update — the correct way
     * to disable a user is to move them into a Disabled usergroup.
     * We keep a dedicated "whmcs_disabled" usergroup for this purpose.
     */
    public function setUserStatus(string $userId, int $status): void
    {
        // status: 0 = enabled, 1 = disabled
        if ($status === 1) {
            // Move user into the global disabled group
            $disabledGrpId = $this->_getOrCreateDisabledGroup();
            $this->call('user.update', [
                'userid'   => $userId,
                'usrgrps'  => [['usrgrpid' => $disabledGrpId]],
            ]);
        } else {
            // Re-enable: find the user's original usrgrp by looking up
            // which usergroup is tied to this user's host group and restore it.
            // We identify the correct usrgrp by the user's username pattern.
            $users = $this->call('user.get', [
                'userids'       => [$userId],
                'output'        => ['userid', 'username'],
                'selectUsrgrps' => ['usrgrpid'],
            ]);

            if (!empty($users[0])) {
                $username = (string)($users[0]['username'] ?? '');
                // username format: whmcs_{userid}_{serviceid}
                // Find the usrgrp named usrgrp_whmcs_client_{userid}_svc_{serviceid}
                $allGroups = $this->call('usergroup.get', [
                    'output'                => ['usrgrpid', 'name'],
                    'selectHostGroupRights' => 'extend',
                ]);

                $targetGrpId = null;
                $suffix      = str_replace('whmcs_', 'whmcs_client_', $username);
                $suffix      = str_replace($suffix . '_', '', $suffix); // normalise

                foreach ($allGroups as $ug) {
                    // Match by name pattern: usrgrp_whmcs_client_X_svc_Y
                    if (strpos((string)($ug['name'] ?? ''), 'usrgrp_whmcs_client_') === 0) {
                        // Verify it has host group rights (i.e. it's a real client group)
                        if (!empty($ug['hostgroup_rights'])) {
                            // Try to match by username embedded in group name
                            // Group name: usrgrp_whmcs_client_{uid}_svc_{sid}
                            // Username:   whmcs_{uid}_{sid}
                            // Extract uid+sid from both and compare
                            if (preg_match('/usrgrp_whmcs_client_(\d+)_svc_(\d+)/', (string)$ug['name'], $m)) {
                                $grpUser = 'whmcs_' . $m[1] . '_' . $m[2];
                                if ($grpUser === $username) {
                                    $targetGrpId = (string)$ug['usrgrpid'];
                                    break;
                                }
                            }
                        }
                    }
                }

                if ($targetGrpId !== null) {
                    $this->call('user.update', [
                        'userid'  => $userId,
                        'usrgrps' => [['usrgrpid' => $targetGrpId]],
                    ]);
                }
            }
        }
    }

    /**
     * Get or create the global "whmcs_disabled" usergroup.
     * This group has gui_access=2 (disabled) and users_status=1 (disabled).
     */
    private function _getOrCreateDisabledGroup(): string
    {
        $result = $this->call('usergroup.get', [
            'filter' => ['name' => ['whmcs_disabled']],
            'output' => ['usrgrpid'],
        ]);

        if (!empty($result[0]['usrgrpid'])) {
            return (string)$result[0]['usrgrpid'];
        }

        $created = $this->call('usergroup.create', [
            'name'         => 'whmcs_disabled',
            'gui_access'   => 2, // disabled
            'users_status' => 1, // disabled
        ]);

        if (empty($created['usrgrpids'][0])) {
            throw new \Exception('Failed to create whmcs_disabled user group.');
        }

        return (string)$created['usrgrpids'][0];
    }

    public function changeUserPassword(string $userId, string $newPassword): void
    {
        $this->call('user.update', [
            'userid' => $userId,
            'passwd' => $newPassword,
        ]);
    }

    public function deleteUser(string $userId): void
    {
        // Move user to disabled group first so Zabbix allows deletion
        // even if their original usergroup was already removed.
        try {
            $disabledGrpId = $this->_getOrCreateDisabledGroup();
            $this->call('user.update', [
                'userid'  => $userId,
                'usrgrps' => [['usrgrpid' => $disabledGrpId]],
            ]);
        } catch (\Exception $e) {
            // Non-fatal — attempt deletion anyway
        }
        $this->call('user.delete', [$userId]);
    }

    // -----------------------------------------------------------------------
    // Roles
    // -----------------------------------------------------------------------

    public function getRoleId(string $roleName): ?string
    {
        $result = $this->call('role.get', [
            'filter' => ['name' => [$roleName]],
            'output' => ['roleid'],
        ]);
        return (!empty($result) && isset($result[0]['roleid']))
            ? (string)$result[0]['roleid']
            : null;
    }

    // -----------------------------------------------------------------------
    // Zabbix user groups (usrgrp)
    // One usrgrp per client, tied to their host group with read-write access.
    // -----------------------------------------------------------------------

    private function _getOrCreateUserGroup(string $hostGroupId): string
    {
        // Look for existing usrgrp already tied to this host group
        $result = $this->call('usergroup.get', [
            'output'                => ['usrgrpid', 'name'],
            'selectHostGroupRights' => 'extend',
        ]);

        if (is_array($result)) {
            foreach ($result as $ug) {
                if (!empty($ug['hostgroup_rights']) && is_array($ug['hostgroup_rights'])) {
                    foreach ($ug['hostgroup_rights'] as $right) {
                        if (isset($right['id']) && (string)$right['id'] === (string)$hostGroupId) {
                            return (string)$ug['usrgrpid'];
                        }
                    }
                }
            }
        }

        // Fetch host group name for a readable usrgrp name
        $hg        = $this->call('hostgroup.get', [
            'groupids' => [$hostGroupId],
            'output'   => ['name'],
        ]);
        $groupName = (!empty($hg[0]['name'])) ? (string)$hg[0]['name'] : 'grp_' . $hostGroupId;

        $created = $this->call('usergroup.create', [
            'name'             => 'usrgrp_' . $groupName,
            'hostgroup_rights' => [[
                'id'         => $hostGroupId,
                'permission' => 3, // read-write
            ]],
        ]);

        if (empty($created['usrgrpids'][0])) {
            throw new \Exception('Failed to create user group: empty usrgrpid in response.');
        }

        return (string)$created['usrgrpids'][0];
    }

    public function deleteUserGroup(string $hostGroupId): void
    {
        $result = $this->call('usergroup.get', [
            'output'                => ['usrgrpid'],
            'selectHostGroupRights' => 'extend',
        ]);

        if (!is_array($result)) {
            return;
        }

        foreach ($result as $ug) {
            if (!empty($ug['hostgroup_rights']) && is_array($ug['hostgroup_rights'])) {
                foreach ($ug['hostgroup_rights'] as $right) {
                    if (isset($right['id']) && (string)$right['id'] === (string)$hostGroupId) {
                        $this->call('usergroup.delete', [(string)$ug['usrgrpid']]);
                        return;
                    }
                }
            }
        }
    }

    // -----------------------------------------------------------------------
    // Hosts
    // -----------------------------------------------------------------------

    /**
     * @param string $displayName  Human-readable name
     * @param string $address      IP or DNS hostname
     * @param string $type         'agent' | 'snmp' | 'icmp'
     * @param string $groupId      Zabbix Host Group ID
     * @param string $pskIdentity  PSK identity (agent only)
     * @param string $pskKey       PSK key hex string (agent only)
     * @return string              Created host ID
     */
    public function createHost(
        string $displayName,
        string $address,
        string $type,
        string $groupId,
        string $pskIdentity = '',
        string $pskKey = ''
    ): string {
        $interface = $this->_buildInterface($address, $type);
        $hostData  = [
            'host'       => $displayName,
            'name'       => $displayName,
            'groups'     => [['groupid' => $groupId]],
            'interfaces' => [$interface],
            'status'     => 0, // monitored
        ];

        if ($type === 'agent' && $pskIdentity !== '' && $pskKey !== '') {
            $hostData['tls_connect']      = 2; // PSK
            $hostData['tls_accept']       = 2; // PSK
            $hostData['tls_psk_identity'] = $pskIdentity;
            $hostData['tls_psk']          = $pskKey;
        }

        $result = $this->call('host.create', $hostData);

        if (empty($result['hostids'][0])) {
            throw new \Exception('Failed to create host: empty hostid in response.');
        }

        return (string)$result['hostids'][0];
    }

    public function getHostsByGroup(string $groupId): array
    {
        $result = $this->call('host.get', [
            'groupids'         => [$groupId],
            'output'           => ['hostid', 'host', 'name', 'status'],
            'selectInterfaces' => ['ip', 'dns', 'type'],
        ]);
        return is_array($result) ? $result : [];
    }

    public function getHostCount(string $groupId): int
    {
        return count($this->getHostsByGroup($groupId));
    }

    public function setHostsStatus(string $groupId, int $status): void
    {
        // status: 0 = monitored, 1 = not monitored
        $hosts = $this->getHostsByGroup($groupId);
        if (empty($hosts)) {
            return;
        }

        // BUG FIX: batch update — one call instead of N calls
        $updates = [];
        foreach ($hosts as $h) {
            if (isset($h['hostid'])) {
                $updates[] = ['hostid' => (string)$h['hostid'], 'status' => $status];
            }
        }

        foreach ($updates as $update) {
            $this->call('host.update', $update);
        }
    }

    public function deleteHost(string $hostId): void
    {
        $this->call('host.delete', [$hostId]);
    }

    public function deleteAllHostsInGroup(string $groupId): void
    {
        $hosts = $this->getHostsByGroup($groupId);
        if (empty($hosts)) {
            return;
        }
        // BUG FIX: filter nulls and cast to string before passing to API
        $ids = array_values(array_filter(array_map(function ($h) {
            return isset($h['hostid']) ? (string)$h['hostid'] : null;
        }, $hosts)));

        if (!empty($ids)) {
            $this->call('host.delete', $ids);
        }
    }

    // -----------------------------------------------------------------------
    // Interface builder
    // -----------------------------------------------------------------------

    private function _buildInterface(string $address, string $type): array
    {
        $isIp = filter_var($address, FILTER_VALIDATE_IP) ? 1 : 0;
        $ip   = $isIp ? $address : '';
        $dns  = $isIp ? '' : $address;

        switch ($type) {
            case 'snmp':
                return [
                    'type'    => 2, // SNMP
                    'main'    => 1,
                    'useip'   => $isIp,
                    'ip'      => $ip,
                    'dns'     => $dns,
                    'port'    => '161',
                    'details' => [
                        'version'   => 2,
                        'community' => '{$SNMP_COMMUNITY}',
                    ],
                ];

            case 'icmp':
                // ICMP ping uses agent-type interface as placeholder (Zabbix requirement)
                return [
                    'type'  => 1,
                    'main'  => 1,
                    'useip' => $isIp,
                    'ip'    => $ip,
                    'dns'   => $dns,
                    'port'  => '10050',
                ];

            case 'agent':
            default:
                return [
                    'type'  => 1, // Zabbix agent
                    'main'  => 1,
                    'useip' => $isIp,
                    'ip'    => $ip,
                    'dns'   => $dns,
                    'port'  => '10050',
                ];
        }
    }

    // -----------------------------------------------------------------------
    // PSK helpers
    // -----------------------------------------------------------------------

    /**
     * Generate a cryptographically secure PSK key (64 hex chars = 256 bit).
     */
    public static function generatePsk(): string
    {
        return bin2hex(random_bytes(32));
    }

    /**
     * Generate a unique PSK identity string.
     */
    public static function generatePskIdentity(string $hostname): string
    {
        $safe = preg_replace('/[^a-z0-9_\-]/i', '_', $hostname);
        return 'psk_' . $safe . '_' . bin2hex(random_bytes(4));
    }

    // -----------------------------------------------------------------------
    // Getters
    // -----------------------------------------------------------------------

    public function getMaxHosts(): int
    {
        return $this->maxHosts;
    }

    public function getRoleName(): string
    {
        return $this->roleName;
    }

    public function getServerHostname(): string
    {
        $parts = parse_url($this->url);
        return isset($parts['host']) ? (string)$parts['host'] : '';
    }
}
