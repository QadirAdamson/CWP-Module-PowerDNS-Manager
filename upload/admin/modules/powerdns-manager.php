<?php
// PowerDNS Management Module for Control Web Panel (CWP)
// Version 3 - Fully unified table styles, robust sorting, cleaned layout

if (!isset($include_path)) {
    echo "Invalid Access";
    exit();
}

$config_file = '/etc/pdns/pdns.conf';

// Dynamically parse PowerDNS configuration from /etc/pdns/pdns.conf
if (!function_exists('parse_pdns_config')) {
    function parse_pdns_config($file) {
        $cfg = [];
        if (file_exists($file) && is_readable($file)) {
            $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            foreach ($lines as $line) {
                $line = trim($line);
                if (empty($line) || $line[0] === '#') continue;
                if (strpos($line, '=') !== false) {
                    list($key, $val) = explode('=', $line, 2);
                    $cfg[trim($key)] = trim($val);
                }
            }
        }
        return $cfg;
    }
}

$pdns_cfg = parse_pdns_config($config_file);

$api_key = $pdns_cfg['api-key'] ?? '';
$api_port = $pdns_cfg['webserver-port'] ?? '8053';
$api_address = $pdns_cfg['webserver-address'] ?? '127.0.0.1';
if ($api_address === '0.0.0.0') $api_address = '127.0.0.1';
$api_url = "http://{$api_address}:{$api_port}/api/v1/servers/localhost";

$pdns_backend = (isset($pdns_cfg['launch']) && strpos($pdns_cfg['launch'], 'gpgsql') !== false) ? 'pgsql' : 'mysql';

if ($pdns_backend === 'pgsql') {
    $db_host = $pdns_cfg['gpgsql-host'] ?? '127.0.0.1';
    $db_user = $pdns_cfg['gpgsql-user'] ?? 'pdns_user';
    $db_pass = $pdns_cfg['gpgsql-password'] ?? '';
    $db_name = $pdns_cfg['gpgsql-dbname'] ?? 'powerdns';
    $db_port = intval($pdns_cfg['gpgsql-port'] ?? 5432);
} else {
    $db_host = $pdns_cfg['gmysql-host'] ?? '127.0.0.1';
    $db_user = $pdns_cfg['gmysql-user'] ?? 'pdns_user';
    $db_pass = $pdns_cfg['gmysql-password'] ?? '';
    $db_name = $pdns_cfg['gmysql-dbname'] ?? 'powerdns';
    $db_port = intval($pdns_cfg['gmysql-port'] ?? 3306);
}

if (!class_exists('PdnsDbResult')) {
    class PdnsDbResult {
        private $rows = [];
        private $idx = 0;
        public $num_rows = 0;

        public function __construct($rows = []) {
            foreach ($rows as &$row) {
                if (is_array($row)) {
                    if (isset($row['disabled'])) {
                        $row['disabled'] = ($row['disabled'] === true || $row['disabled'] === 't' || $row['disabled'] === 1 || $row['disabled'] === '1') ? 1 : 0;
                    }
                    if (isset($row['auth'])) {
                        $row['auth'] = ($row['auth'] === true || $row['auth'] === 't' || $row['auth'] === 1 || $row['auth'] === '1') ? 1 : 0;
                    }
                }
            }
            $this->rows = $rows;
            $this->num_rows = count($rows);
        }

        public function fetch_assoc() {
            if ($this->idx < $this->num_rows) {
                return $this->rows[$this->idx++];
            }
            return null;
        }
    }
}

if (!class_exists('PdnsDbWrapper')) {
    class PdnsDbWrapper {
        public $backend = 'mysql';
        public $mysqli = null;
        public $pdo = null;
        public $insert_id = 0;
        public $affected_rows = 0;

        public function __construct($backend, $host, $user, $pass, $name, $port) {
            $this->backend = $backend;
            if ($backend === 'pgsql') {
                try {
                    $dsn = "pgsql:host={$host};port=" . ($port ?: 5432) . ";dbname={$name}";
                    $this->pdo = new PDO($dsn, $user, $pass, [
                        PDO::ATTR_ERRMODE => PDO::ERRMODE_SILENT,
                        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
                    ]);
                } catch (Exception $e) {
                    $this->pdo = null;
                }
            } else {
                $this->mysqli = @new mysqli($host, $user, $pass, $name, $port ?: 3306);
                if ($this->mysqli->connect_errno) {
                    $this->mysqli = null;
                } else {
                    $this->mysqli->set_charset("utf8");
                }
            }
        }

        public function is_connected() {
            return ($this->backend === 'pgsql') ? ($this->pdo !== null) : ($this->mysqli !== null);
        }

        public function real_escape_string($str) {
            if ($str === null) return '';
            if ($this->backend === 'pgsql') {
                if ($this->pdo) {
                    $q = $this->pdo->quote($str);
                    if (strlen($q) >= 2 && $q[0] === "'" && substr($q, -1) === "'") {
                        return substr($q, 1, -1);
                    }
                }
                return addslashes($str);
            } else {
                return $this->mysqli ? $this->mysqli->real_escape_string($str) : addslashes($str);
            }
        }

        public function query($sql) {
            if ($this->backend === 'pgsql') {
                if (!$this->pdo) return false;
                if (preg_match('/UPDATE\s+records\s+r\s+JOIN\s+domains\s+d\s+ON\s+r\.domain_id\s*=\s*d\.id\s+SET\s+r\.content\s*=\s*\'([^\']*)\'\s+WHERE\s+d\.name\s*=\s*\'([^\']*)\'\s+AND\s+r\.type\s*=\s*\'SOA\'/i', $sql, $m)) {
                    $sql = "UPDATE records SET content = " . $this->pdo->quote($m[1]) . " WHERE domain_id IN (SELECT id FROM domains WHERE name = " . $this->pdo->quote($m[2]) . ") AND type = 'SOA'";
                }
                if (preg_match('/^\s*INSERT\s+INTO\s+([a-zA-Z0-9_]+)/i', $sql, $im)) {
                    if (stripos($sql, 'RETURNING') === false) {
                        $sql_run = rtrim($sql, "; \t\n\r") . " RETURNING id";
                        $stmt = $this->pdo->query($sql_run);
                        if ($stmt) {
                            $row = $stmt->fetch();
                            $this->insert_id = intval($row['id'] ?? 0);
                            $this->affected_rows = 1;
                            return true;
                        }
                    }
                }
                $stmt = $this->pdo->query($sql);
                if ($stmt === false) {
                    $this->affected_rows = 0;
                    return false;
                }
                $this->affected_rows = $stmt->rowCount();
                if (preg_match('/^\s*(SELECT|SHOW|EXPLAIN)\s/i', $sql)) {
                    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    return new PdnsDbResult($rows);
                }
                return true;
            } else {
                if (!$this->mysqli) return false;
                $res = $this->mysqli->query($sql);
                $this->insert_id = $this->mysqli->insert_id;
                $this->affected_rows = $this->mysqli->affected_rows;
                return $res;
            }
        }

        public function close() {
            if ($this->backend === 'pgsql') {
                $this->pdo = null;
            } else {
                if ($this->mysqli) {
                    @$this->mysqli->close();
                    $this->mysqli = null;
                }
            }
        }
    }
}

if (!function_exists('get_pdns_db')) {
    function get_pdns_db() {
        global $pdns_backend, $db_host, $db_user, $db_pass, $db_name, $db_port;
        $db = new PdnsDbWrapper($pdns_backend, $db_host, $db_user, $db_pass, $db_name, $db_port);
        if (!$db->is_connected()) {
            return null;
        }
        return $db;
    }
}
$default_hostname = trim(gethostname() ?: 'test.incomel.net');

// Helper to strip trailing dot for display if present
if (!function_exists('trim_dns_dot')) {
    function trim_dns_dot($val) {
        if ($val === '.' || $val === null || $val === '') return $val;
        return rtrim($val, '.');
    }
}

// Helper function to parse BIND RFC zone files for Import BIND Zones
if (!function_exists("parse_bind_zone_file")) {
function parse_bind_zone_file($filepath, $origin_domain) {
    if (!file_exists($filepath) || !is_readable($filepath)) return null;
    $raw_content = file_get_contents($filepath);
    $raw_content = str_replace(["\r\n", "\r"], "\n", $raw_content);

    $clean_text = '';
    $in_paren = false;
    $in_quotes = false;
    $len = strlen($raw_content);
    for ($i = 0; $i < $len; $i++) {
        $ch = $raw_content[$i];
        if ($ch === '"') {
            if ($i === 0 || $raw_content[$i-1] !== '\\') {
                $in_quotes = !$in_quotes;
            }
            $clean_text .= $ch;
            continue;
        }
        if (!$in_quotes && $ch === ';') {
            while ($i < $len && $raw_content[$i] !== "\n") {
                $i++;
            }
            if ($in_paren) {
                $clean_text .= ' ';
            } else {
                $clean_text .= "\n";
            }
            continue;
        }
        if (!$in_quotes && $ch === '(') {
            $in_paren = true;
            $clean_text .= ' ';
            continue;
        }
        if (!$in_quotes && $ch === ')') {
            $in_paren = false;
            $clean_text .= ' ';
            continue;
        }
        if ($in_paren && $ch === "\n") {
            $clean_text .= ' ';
            continue;
        }
        $clean_text .= $ch;
    }

    $lines = explode("\n", $clean_text);
    $origin = rtrim($origin_domain, '.') . '.';
    $current_ttl = 3600;
    $last_name = '@';
    $records = [];
    $soa = null;

    foreach ($lines as $line) {
        $line = trim($line);
        if (empty($line)) continue;

        if (stripos($line, '$TTL') === 0) {
            $parts = preg_split('/\s+/', $line);
            if (isset($parts[1]) && is_numeric($parts[1])) $current_ttl = intval($parts[1]);
            continue;
        }
        if (stripos($line, '$ORIGIN') === 0) {
            $parts = preg_split('/\s+/', $line);
            if (isset($parts[1])) $origin = rtrim($parts[1], '.') . '.';
            continue;
        }

        preg_match_all('/"(?:\\.|[^\\"])*"|\S+/', $line, $matches);
        $tokens = $matches[0] ?? [];
        if (count($tokens) < 2) continue;

        $token_idx = 0;
        $first = $tokens[0];

        if (is_numeric($first) || in_array(strtoupper($first), ['IN', 'SOA', 'NS', 'A', 'AAAA', 'CNAME', 'MX', 'TXT', 'SRV', 'PTR', 'CAA'])) {
            $rec_name_raw = $last_name;
        } else {
            $rec_name_raw = $tokens[$token_idx++];
            $last_name = $rec_name_raw;
        }

        $rec_name = $origin;
        if ($rec_name_raw === '@') {
            $rec_name = $origin;
        } elseif (substr($rec_name_raw, -1) === '.') {
            $rec_name = $rec_name_raw;
        } else {
            $rec_name = $rec_name_raw . '.' . $origin;
        }

        $rec_ttl = $current_ttl;
        if (isset($tokens[$token_idx]) && is_numeric($tokens[$token_idx])) {
            $rec_ttl = intval($tokens[$token_idx++]);
        }

        if (isset($tokens[$token_idx]) && strtoupper($tokens[$token_idx]) === 'IN') {
            $token_idx++;
        }

        if (!isset($tokens[$token_idx])) continue;
        $rec_type = strtoupper($tokens[$token_idx++]);

        $content_tokens = array_slice($tokens, $token_idx);
        $content = implode(' ', $content_tokens);
        if (empty($rec_type) || empty($content)) continue;

        if ($rec_type === 'MX') {
            $mx_parts = preg_split('/\s+/', $content, 2);
            if (count($mx_parts) === 2 && is_numeric($mx_parts[0])) {
                $target = rtrim($mx_parts[1], '.');
                if (strpos($target, '.') === false) {
                    $target .= '.' . rtrim($origin, '.');
                }
                $content = $mx_parts[0] . ' ' . $target;
            }
        } elseif (in_array($rec_type, ['CNAME', 'NS', 'PTR'])) {
            $target = rtrim($content, '.');
            if (strpos($target, '.') === false && $target !== '@') {
                $target .= '.' . rtrim($origin, '.');
            } elseif ($target === '@') {
                $target = rtrim($origin, '.');
            }
            $content = $target;
        }

        if ($rec_type === 'SOA') {
            $soa_parts = preg_split('/\s+/', trim($content));
            $mname = !empty($soa_parts[0]) ? rtrim($soa_parts[0], '.') : 'ns1.' . rtrim($origin, '.');
            $rname = !empty($soa_parts[1]) ? rtrim(str_replace('@', '.', $soa_parts[1]), '.') : 'hostmaster.' . rtrim($origin, '.');
            $serial = (isset($soa_parts[2]) && is_numeric($soa_parts[2])) ? $soa_parts[2] : date('Ymd01');
            $refresh = (isset($soa_parts[3]) && is_numeric($soa_parts[3])) ? $soa_parts[3] : '3600';
            $retry = (isset($soa_parts[4]) && is_numeric($soa_parts[4])) ? $soa_parts[4] : '7200';
            $expire = (isset($soa_parts[5]) && is_numeric($soa_parts[5])) ? $soa_parts[5] : '1209600';
            $minimum = (isset($soa_parts[6]) && is_numeric($soa_parts[6])) ? $soa_parts[6] : '300';

            $content = "{$mname} {$rname} {$serial} {$refresh} {$retry} {$expire} {$minimum}";
            $soa = [
                'name' => rtrim($rec_name, '.'),
                'ttl' => $rec_ttl ?: 3600,
                'content' => $content
            ];
        } else {
            $records[] = [
                'name' => rtrim($rec_name, '.'),
                'type' => $rec_type,
                'ttl' => $rec_ttl,
                'content' => $content,
                'disabled' => 0
            ];
        }
    }

    return ['domain' => $origin, 'soa' => $soa, 'records' => $records];
}
}

if (!function_exists('generate_zone_file_content')) {
function generate_zone_file_content($db, $domain_id, $domain_name) {
    $clean_dom = rtrim($domain_name, '.');
    $out = "\$ORIGIN {$clean_dom}.\n";

    // 1. Fetch SOA
    $res_soa = $db->query("SELECT name, type, content, ttl FROM records WHERE domain_id = {$domain_id} AND type = 'SOA' LIMIT 1");
    $default_ttl = 3600;
    if ($res_soa && $soa = $res_soa->fetch_assoc()) {
        $default_ttl = intval($soa['ttl'] ?: 3600);
        $out .= "\$TTL {$default_ttl}\n";
        $out .= "{$clean_dom}.\t{$default_ttl}\tIN\tSOA\t{$soa['content']}\n";
    } else {
        $out .= "\$TTL 3600\n";
    }

    // 2. Fetch all other records
    $res_r = $db->query("SELECT name, type, content, ttl, prio, disabled FROM records WHERE domain_id = {$domain_id} AND type != 'SOA' ORDER BY type, name, id");
    if ($res_r) {
        while ($r = $res_r->fetch_assoc()) {
            $r_name = rtrim($r['name'], '.') . '.';
            $r_type = strtoupper($r['type']);
            $r_ttl = intval($r['ttl'] ?: $default_ttl);
            $r_prio = (isset($r['prio']) && $r['prio'] !== null && $r['prio'] !== '') ? intval($r['prio']) : null;
            $r_cnt = trim($r['content']);
            $comment_prefix = !empty($r['disabled']) ? '; [DISABLED] ' : '';

            if ($r_type === 'MX' || $r_type === 'SRV') {
                if ($r_prio !== null && !preg_match('/^\d+\s+/', $r_cnt)) {
                    $val = "{$r_prio}\t{$r_cnt}";
                } else {
                    $val = $r_cnt;
                }
            } elseif ($r_type === 'TXT') {
                if ($r_cnt === '' || $r_cnt[0] !== '"') {
                    $val = '"' . str_replace('"', '\"', $r_cnt) . '"';
                } else {
                    $val = $r_cnt;
                }
            } else {
                $val = $r_cnt;
            }

            $out .= "{$comment_prefix}{$r_name}\t{$r_ttl}\tIN\t{$r_type}\t{$val}\n";
        }
    }
    return $out;
}
}

if (!function_exists('generate_all_zones_file')) {
function generate_all_zones_file($db) {
    $res = $db->query("SELECT id, name, type FROM domains ORDER BY name");
    if (!$res) return "";
    $out = "; ==============================================================================\n";
    $out .= "; PowerDNS Multi-Zone Master ZoneFile Export (RFC 1035)\n";
    $out .= "; Generated: " . date('Y-m-d H:i:s') . "\n";
    $out .= "; Database Engine Agnostic - Works across MySQL, PostgreSQL & BIND 9\n";
    $out .= "; ==============================================================================\n\n";

    while ($row = $res->fetch_assoc()) {
        $dom_id = intval($row['id']);
        $dom_name = rtrim($row['name'], '.');
        $out .= "; ------------------------------------------------------------------------------\n";
        $out .= "; ZONE: {$dom_name} ({$row['type']})\n";
        $out .= "; ------------------------------------------------------------------------------\n";
        $out .= generate_zone_file_content($db, $dom_id, $dom_name);
        $out .= "\n";
    }
    return $out;
}
}

if (!function_exists('import_zonefile_content')) {
function import_zonefile_content($db, $raw_content, $pdns_backend) {
    $lines = explode("\n", str_replace(["\r\n", "\r"], "\n", $raw_content));
    $zone_blocks = [];
    $curr_zone = '';
    $curr_lines = [];

    foreach ($lines as $line) {
        if (preg_match('/^\s*\$ORIGIN\s+([^\s;]+)/i', $line, $om)) {
            if (!empty($curr_zone) && !empty($curr_lines)) {
                $zone_blocks[$curr_zone] = implode("\n", $curr_lines);
            }
            $curr_zone = trim($om[1], '.;" ');
            $curr_lines = [$line];
        } else {
            if (empty($curr_zone) && preg_match('/^\s*([^\s;]+)\s+(?:\d+\s+)?(?:IN\s+)?SOA/i', $line, $sm)) {
                $curr_zone = trim($sm[1], '.;" ');
            }
            $curr_lines[] = $line;
        }
    }
    if (!empty($curr_zone) && !empty($curr_lines)) {
        $zone_blocks[$curr_zone] = implode("\n", $curr_lines);
    }
    if (empty($zone_blocks) && !empty($curr_lines)) {
        $zone_blocks['default'] = implode("\n", $curr_lines);
    }

    $imported_zones = 0;
    $imported_records = 0;

    foreach ($zone_blocks as $dom_name => $zone_text) {
        $tmp_f = tempnam('/tmp', 'pdns_zimp_');
        file_put_contents($tmp_f, $zone_text);
        $parsed = parse_bind_zone_file($tmp_f, $dom_name);
        @unlink($tmp_f);

        if (!$parsed || empty($parsed['domain'])) continue;
        $clean_dom = rtrim($parsed['domain'], '.');
        $clean_esc = $db->real_escape_string($clean_dom);

        // Check if domain exists, else create
        $qz = $db->query("SELECT id FROM domains WHERE name = '{$clean_esc}' LIMIT 1");
        $domain_id = 0;
        if ($qz && $zrow = $qz->fetch_assoc()) {
            $domain_id = intval($zrow['id']);
        } else {
            $db->query("INSERT INTO domains (name, type) VALUES ('{$clean_esc}', 'NATIVE')");
            $domain_id = $db->insert_id;
            if (!$domain_id) {
                $qz2 = $db->query("SELECT id FROM domains WHERE name = '{$clean_esc}' LIMIT 1");
                if ($qz2 && $zrow2 = $qz2->fetch_assoc()) {
                    $domain_id = intval($zrow2['id']);
                }
            }
            $imported_zones++;
        }

        if (!$domain_id) continue;

        // Fetch existing records for this domain
        $existing_set = [];
        $q_exist = $db->query("SELECT name, type, content FROM records WHERE domain_id = {$domain_id}");
        if ($q_exist) {
            while ($er = $q_exist->fetch_assoc()) {
                $key = strtolower(rtrim($er['name'], '.')) . '|' . strtoupper($er['type']) . '|' . trim($er['content'], '."');
                $existing_set[$key] = true;
            }
        }

        $dis_val = ($pdns_backend === 'pgsql') ? 'FALSE' : '0';
        $auth_val = ($pdns_backend === 'pgsql') ? 'TRUE' : '1';

        // Process SOA
        if (!empty($parsed['soa'])) {
            $soa_name_esc = $db->real_escape_string(rtrim($parsed['soa']['name'], '.'));
            $soa_ttl = intval($parsed['soa']['ttl']);
            $soa_cnt_esc = $db->real_escape_string($parsed['soa']['content']);

            $q_soa_check = $db->query("SELECT id, content FROM records WHERE domain_id = {$domain_id} AND type = 'SOA' LIMIT 1");
            if ($q_soa_check && $row_soa = $q_soa_check->fetch_assoc()) {
                $cur_parts = preg_split('/\s+/', trim($row_soa['content']));
                if (count($cur_parts) < 7 || stripos($row_soa['content'], 'a.misconfigured.dns.server.invalid') !== false) {
                    $db->query("UPDATE records SET content = '{$soa_cnt_esc}', ttl = {$soa_ttl} WHERE id = {$row_soa['id']}");
                }
            } else {
                $db->query("INSERT INTO records (domain_id, name, type, content, ttl, prio, disabled, auth)
                            VALUES ({$domain_id}, '{$soa_name_esc}', 'SOA', '{$soa_cnt_esc}', {$soa_ttl}, NULL, {$dis_val}, {$auth_val})");
                $imported_records++;
            }
        }

        // Process records
        foreach ($parsed['records'] as $r) {
            $check_key = strtolower(rtrim($r['name'], '.')) . '|' . strtoupper($r['type']) . '|' . trim($r['content'], '."');
            if (isset($existing_set[$check_key])) continue;

            $r_name_esc = $db->real_escape_string(rtrim($r['name'], '.'));
            $r_type_esc = $db->real_escape_string($r['type']);
            $r_ttl = intval($r['ttl']);
            $r_cnt_esc = $db->real_escape_string($r['content']);
            $r_prio_val = (isset($r['prio']) && is_numeric($r['prio'])) ? intval($r['prio']) : "NULL";

            $db->query("INSERT INTO records (domain_id, name, type, content, ttl, prio, disabled, auth)
                        VALUES ({$domain_id}, '{$r_name_esc}', '{$r_type_esc}', '{$r_cnt_esc}', {$r_ttl}, {$r_prio_val}, {$dis_val}, {$auth_val})");
            $imported_records++;
            $existing_set[$check_key] = true;
        }
    }

    if ($pdns_backend === 'pgsql') {
        @$db->query("SELECT setval('domains_id_seq', COALESCE((SELECT MAX(id) FROM domains), 1))");
        @$db->query("SELECT setval('records_id_seq', COALESCE((SELECT MAX(id) FROM records), 1))");
    }

    shell_exec("pdns_control purge >/dev/null 2>&1");
    return ['zones' => $imported_zones, 'records' => $imported_records];
}
}

function call_pdns_api($endpoint, $method = 'GET', $data = null) {
    global $api_url, $api_key;
    $ch = curl_init($api_url . $endpoint);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'X-API-Key: ' . $api_key,
        'Content-Type: application/json'
    ]);
    curl_setopt($ch, CURLOPT_TIMEOUT, 6);
    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        if ($data) curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    } elseif ($method === 'PATCH') {
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PATCH');
        if ($data) curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    } elseif ($method === 'DELETE') {
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
    }
    $res = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['code' => $http_code, 'body' => json_decode($res, true)];
}

$message = '';
$msg_type = 'success';

// Handle Service Actions
if (isset($_POST['service_action'])) {
    $act = $_POST['service_action'];
    if ($act === 'start' || $act === 'restart') {
        $named_running = (trim(shell_exec('systemctl is-active named 2>/dev/null')) === 'active' || trim(shell_exec('systemctl is-active bind9 2>/dev/null')) === 'active');
        $out = trim(shell_exec("systemctl {$act} pdns 2>&1"));
        usleep(400000);
        if (trim(shell_exec('systemctl is-active pdns 2>/dev/null')) === 'active') {
            $message = ($act === 'start') ? "PowerDNS service has been started successfully." : "PowerDNS service restarted successfully.";
        } else {
            $msg_type = 'danger';
            if ($named_running) {
                $message = "Failed to {$act} PowerDNS: <b>BIND (named)</b> service is currently running on port 53. Please stop BIND first.";
            } else {
                $err_detail = !empty($out) ? htmlspecialchars($out) : 'Port 53 conflict or configuration error.';
                $message = "Failed to {$act} PowerDNS service. ({$err_detail})";
            }
        }
    } elseif ($act === 'stop') {
        shell_exec('systemctl stop pdns 2>&1');
        usleep(300000);
        if (trim(shell_exec('systemctl is-active pdns 2>/dev/null')) !== 'active') {
            $message = "PowerDNS service has been stopped.";
        } else {
            $msg_type = 'danger';
            $message = "Failed to stop PowerDNS service.";
        }
    }
}

// Handle Configuration File Save
if (isset($_POST['save_config'])) {
    $new_config = $_POST['config_content'] ?? '';
    if (!empty($new_config)) {
        @copy($config_file, $config_file . '.bak_' . date('Ymd_His'));
        if (file_put_contents($config_file, $new_config) !== false) {
            $message = "Configuration file <b>/etc/pdns/pdns.conf</b> saved successfully.";
            if (isset($_POST['restart_after_save'])) {
                shell_exec('systemctl restart pdns 2>&1');
                usleep(400000);
                if (trim(shell_exec('systemctl is-active pdns 2>/dev/null')) === 'active') {
                    $message .= " Service has been restarted.";
                } else {
                    $msg_type = 'danger';
                    $message .= " However, PowerDNS failed to restart (check port 53 / BIND conflict or configuration syntax).";
                }
            }
        } else {
            $msg_type = 'danger';
            $message = "Error saving configuration file. Check write permissions.";
        }
    }
}

// Handle Full PowerDNS Database SQL Export
$exported_sql_data = null;
if (isset($_POST['export_db_sql'])) {
    if ($pdns_backend === 'pgsql') {
        $dump_cmd = "PGPASSWORD=" . escapeshellarg($db_pass) . " pg_dump -h " . escapeshellarg($db_host ?: '127.0.0.1') .
                    " -p " . intval($db_port ?: 5432) .
                    " -U " . escapeshellarg($db_user) .
                    " --clean --if-exists " .
                    escapeshellarg($db_name) . " 2>&1";
        $dump_output = shell_exec($dump_cmd);
        if (!empty($dump_output) && strpos($dump_output, 'pg_dump: error') === false && strpos($dump_output, 'FATAL') === false) {
            $exported_sql_data = [
                'filename' => 'powerdns_pg_backup_' . date('Ymd_His') . '.sql',
                'content'  => base64_encode($dump_output)
            ];
        } else {
            $msg_type = 'danger';
            $message = "Failed to export PostgreSQL database: " . htmlspecialchars($dump_output ?: 'Unknown error');
        }
    } else {
        $dump_cmd = "mysqldump -h " . escapeshellarg($db_host ?: '127.0.0.1') .
                    " -P " . intval($db_port ?: 3306) .
                    " -u " . escapeshellarg($db_user) .
                    " -p" . escapeshellarg($db_pass) .
                    " --add-drop-table --single-transaction " .
                    escapeshellarg($db_name) . " 2>&1";
        $dump_output = shell_exec($dump_cmd);
        if (!empty($dump_output) && strpos($dump_output, 'mysqldump: Got error') === false && strpos($dump_output, 'Access denied') === false) {
            $exported_sql_data = [
                'filename' => 'powerdns_full_backup_' . date('Ymd_His') . '.sql',
                'content'  => base64_encode($dump_output)
            ];
        } else {
            $msg_type = 'danger';
            $message = "Failed to export DNS database SQL: " . htmlspecialchars($dump_output ?: 'Unknown error');
        }
    }
}

// Handle DNS Zones & Records Database SQL Import
if (isset($_POST['import_db_sql'])) {
    $sql_file = $_FILES['sql_file'] ?? null;
    if ($sql_file && $sql_file['error'] === UPLOAD_ERR_OK && is_uploaded_file($sql_file['tmp_name'])) {
        if ($pdns_backend === 'pgsql') {
            $import_cmd = "PGPASSWORD=" . escapeshellarg($db_pass) . " psql -h " . escapeshellarg($db_host ?: '127.0.0.1') .
                          " -p " . intval($db_port ?: 5432) .
                          " -U " . escapeshellarg($db_user) .
                          " -d " . escapeshellarg($db_name) .
                          " < " . escapeshellarg($sql_file['tmp_name']) . " 2>&1";
            $import_output = trim(shell_exec($import_cmd) ?? '');
            if (empty($import_output) || (strpos($import_output, 'ERROR:') === false && strpos($import_output, 'FATAL:') === false)) {
                $message = "DNS PostgreSQL backup file (<b>" . htmlspecialchars($sql_file['name']) . "</b>) imported successfully.";
                if (isset($_POST['restart_after_import'])) {
                    shell_exec('systemctl restart pdns 2>&1');
                    usleep(400000);
                    if (trim(shell_exec('systemctl is-active pdns 2>/dev/null')) === 'active') {
                        $message .= " PowerDNS service has been restarted.";
                    }
                }
            } else {
                $msg_type = 'danger';
                $message = "Failed to import PostgreSQL database SQL: " . htmlspecialchars($import_output);
            }
        } else {
            $import_cmd = "mysql -h " . escapeshellarg($db_host ?: '127.0.0.1') .
                          " -P " . intval($db_port ?: 3306) .
                          " -u " . escapeshellarg($db_user) .
                          " -p" . escapeshellarg($db_pass) .
                          " " . escapeshellarg($db_name) .
                          " < " . escapeshellarg($sql_file['tmp_name']) . " 2>&1";
            $import_output = trim(shell_exec($import_cmd) ?? '');
            if (empty($import_output) || strpos($import_output, 'ERROR') === false) {
                $message = "DNS database SQL file (<b>" . htmlspecialchars($sql_file['name']) . "</b>) imported successfully.";
                if (isset($_POST['restart_after_import'])) {
                    shell_exec('systemctl restart pdns 2>&1');
                    usleep(400000);
                    if (trim(shell_exec('systemctl is-active pdns 2>/dev/null')) === 'active') {
                        $message .= " PowerDNS service has been restarted.";
                    }
                }
            } else {
                $msg_type = 'danger';
                $message = "Failed to import DNS database SQL: " . htmlspecialchars($import_output);
            }
        }
    } else {
        $msg_type = 'danger';
        $message = "Please select a valid .sql backup file to import.";
    }
}

// Handle Single Zone RFC 1035 ZoneFile Export (.zone download)
if (isset($_GET['export_single_zone']) && !empty($_GET['export_single_zone'])) {
    $target_dom = trim($_GET['export_single_zone']);
    $db = get_pdns_db();
    if ($db) {
        $clean_esc = $db->real_escape_string(rtrim($target_dom, '.'));
        $qz = $db->query("SELECT id, name FROM domains WHERE name = '{$clean_esc}' LIMIT 1");
        if ($qz && $row = $qz->fetch_assoc()) {
            $dom_id = intval($row['id']);
            $dom_name = rtrim($row['name'], '.');
            $content = "; ==============================================================================\n";
            $content .= "; ZoneFile Export: {$dom_name} (RFC 1035 Format)\n";
            $content .= "; Generated from PowerDNS on " . date('Y-m-d H:i:s') . "\n";
            $content .= "; ==============================================================================\n\n";
            $content .= generate_zone_file_content($db, $dom_id, $dom_name);
            $db->close();

            header('Content-Type: text/plain; charset=utf-8');
            header('Content-Disposition: attachment; filename="' . $dom_name . '.zone"');
            header('Content-Length: ' . strlen($content));
            echo $content;
            exit;
        }
        $db->close();
    }
}

// Handle Multi-Zone RFC 1035 ZoneFile Export
$exported_zonefile_data = null;
if (isset($_POST['export_all_zonefile'])) {
    $db = get_pdns_db();
    if ($db) {
        $content = generate_all_zones_file($db);
        $db->close();
        if (!empty($content)) {
            $exported_zonefile_data = [
                'filename' => 'powerdns_zones_backup_' . date('Ymd_His') . '.zone',
                'content'  => base64_encode($content)
            ];
        } else {
            $msg_type = 'warning';
            $message = "No DNS zones found in database to export.";
        }
    } else {
        $msg_type = 'danger';
        $message = "Unable to connect to database for ZoneFile export.";
    }
}

// Handle RFC 1035 ZoneFile Import (.zone / .db / .txt)
if (isset($_POST['import_zonefile'])) {
    $zf = $_FILES['zone_file'] ?? null;
    if ($zf && $zf['error'] === UPLOAD_ERR_OK && is_uploaded_file($zf['tmp_name'])) {
        $raw_zf_content = file_get_contents($zf['tmp_name']);
        $db = get_pdns_db();
        if ($db) {
            $stats = import_zonefile_content($db, $raw_zf_content, $pdns_backend);
            $db->close();
            $message = "ZoneFile (<b>" . htmlspecialchars($zf['name']) . "</b>) imported successfully: <b>" . intval($stats['zones']) . "</b> zone(s) created, <b>" . intval($stats['records']) . "</b> record(s) added/updated.";
            if (isset($_POST['restart_after_import'])) {
                shell_exec('systemctl restart pdns 2>&1');
                usleep(400000);
                if (trim(shell_exec('systemctl is-active pdns 2>/dev/null')) === 'active') {
                    $message .= " PowerDNS service has been restarted.";
                }
            }
        } else {
            $msg_type = 'danger';
            $message = "Unable to connect to database for ZoneFile import.";
        }
    } else {
        $msg_type = 'danger';
        $message = "Please select a valid .zone, .db, or .txt file to import.";
    }
}

// Service status
$service_active_raw = trim(shell_exec('systemctl is-active pdns 2>/dev/null'));
$is_active = ($service_active_raw === 'active');

// Helper to fetch full zone details
function get_zone_data($zone_id) {
    $res = call_pdns_api('/zones/' . urlencode($zone_id));
    if ($res['code'] === 200 && is_array($res['body'])) {
        return $res['body'];
    }

    // Fallback directly to MySQL database if API is down / service stopped
    $db = get_pdns_db();
    if ($db) {
        $clean_z = rtrim($zone_id, '.');
        $clean_esc = $db->real_escape_string($clean_z);
        $qz = $db->query("SELECT id, name, type FROM domains WHERE name = '{$clean_esc}' LIMIT 1");
        if ($qz && $z_row = $qz->fetch_assoc()) {
            $dom_id = intval($z_row['id']);
            $rrsets_map = [];

            $qr = $db->query("SELECT r.name, r.type, r.ttl, r.content, r.disabled, c.comment
                             FROM records r
                             LEFT JOIN comments c ON (r.domain_id = c.domain_id AND r.name = c.name AND r.type = c.type)
                             WHERE r.domain_id = {$dom_id}");
            if ($qr) {
                while ($rec = $qr->fetch_assoc()) {
                    $key = $rec['name'] . '|' . $rec['type'];
                    if (!isset($rrsets_map[$key])) {
                        $comments = !empty($rec['comment']) ? [['account' => 'admin', 'content' => $rec['comment'], 'modified_at' => time()]] : [];
                        $rrsets_map[$key] = [
                            'name' => rtrim($rec['name'], '.') . '.',
                            'type' => $rec['type'],
                            'ttl' => intval($rec['ttl']),
                            'records' => [],
                            'comments' => $comments
                        ];
                    }
                    $rrsets_map[$key]['records'][] = [
                        'content' => $rec['content'],
                        'disabled' => (bool)$rec['disabled']
                    ];
                }
            }
            $db->close();
            return [
                'id' => $clean_z . '.',
                'name' => $clean_z . '.',
                'kind' => $z_row['type'] ?? 'Native',
                'rrsets' => array_values($rrsets_map)
            ];
        }
        $db->close();
    }
    return null;
}

// Handle Add Zone with Custom SOA & NS
if (isset($_POST['add_zone']) && $is_active) {
    $new_domain = trim($_POST['domain_name']);
    $ns1 = trim($_POST['ns1_name'] ?: 'ns1.' . $new_domain);
    $ns2 = trim($_POST['ns2_name'] ?: 'ns2.' . $new_domain);
    $hostmaster = trim($_POST['soa_hostmaster'] ?: $default_hostname);
    $ttl = intval($_POST['soa_ttl']) ?: 3600;
    $serial = trim($_POST['soa_serial'] ?: date('Ymd01'));
    $refresh = intval($_POST['soa_refresh']) ?: 10800;
    $retry = intval($_POST['soa_retry']) ?: 3600;
    $expire = intval($_POST['soa_expire']) ?: 604800;
    $minimum = intval($_POST['soa_minimum']) ?: 3600;

    if (!empty($new_domain)) {
        $new_domain_canonical = rtrim($new_domain, '.') . '.';
        $ns1_canonical = rtrim($ns1, '.') . '.';
        $ns2_canonical = rtrim($ns2, '.') . '.';
        $hostmaster_canonical = rtrim(str_replace('@', '.', $hostmaster), '.') . '.';

        $nameservers = [$ns1_canonical];
        if (!empty($ns2)) {
            $nameservers[] = $ns2_canonical;
        }

        $payload = [
            'name' => $new_domain_canonical,
            'kind' => 'Native',
            'nameservers' => $nameservers
        ];
        $res = call_pdns_api('/zones', 'POST', $payload);
        if ($res['code'] === 201) {
            $soa_content = "{$ns1_canonical} {$hostmaster_canonical} {$serial} {$refresh} {$retry} {$expire} {$minimum}";
            $soa_patch = [
                'rrsets' => [
                    [
                        'name' => $new_domain_canonical,
                        'type' => 'SOA',
                        'ttl' => $ttl,
                        'changetype' => 'REPLACE',
                        'records' => [
                            ['content' => $soa_content, 'disabled' => false]
                        ]
                    ]
                ]
            ];
            call_pdns_api('/zones/' . urlencode($new_domain_canonical), 'PATCH', $soa_patch);
            shell_exec('pdns_control purge ' . escapeshellarg($new_domain_canonical));
            $message = "Zone <b>{$new_domain}</b> has been created successfully.";
        } else {
            $msg_type = 'danger';
            $err = isset($res['body']['error']) ? $res['body']['error'] : 'Unknown error';
            $message = "Error creating zone: " . htmlspecialchars($err);
        }
    }
}

// Handle Delete Zone

// Handle Bulk Delete Zones
if (isset($_POST['bulk_delete_zones']) && !empty($_POST['selected_zones']) && $is_active) {
    $sel_zones = (array)$_POST['selected_zones'];
    $deleted_count = 0;
    foreach ($sel_zones as $del_z) {
        $del_z = trim($del_z);
        if (!empty($del_z)) {
            $res = call_pdns_api('/zones/' . urlencode($del_z), 'DELETE');
            if ($res['code'] === 204) {
                $deleted_count++;
            }
        }
    }
    if ($deleted_count > 0) {
        $message = "Successfully deleted <b>{$deleted_count}</b> DNS zone(s).";
    } else {
        $msg_type = 'danger';
        $message = "No zones could be deleted.";
    }
}

if (isset($_GET['delete_zone']) && $is_active) {
    $del_z = $_GET['delete_zone'];
    $res = call_pdns_api('/zones/' . urlencode($del_z), 'DELETE');
    if ($res['code'] === 204) {
        $message = "Zone <b>{$del_z}</b> has been deleted.";
    } else {
        $msg_type = 'danger';
        $message = "Error deleting zone.";
    }
}

// Handle Save / Edit / Add Record (Disaggregated item)
if (isset($_POST['save_record']) && $is_active) {
    $zone_id = $_POST['zone_id'];
    $rec_name = trim($_POST['rec_name']);
    $rec_type = trim($_POST['rec_type']);
    $rec_ttl = intval($_POST['rec_ttl']) ?: 300;
    $rec_content = preg_replace('/[\r\n]+/', ' ', trim($_POST['rec_content']));
    $rec_comment = trim($_POST['rec_comment'] ?? '');
    $rec_disabled = (isset($_POST['rec_disabled']) && intval($_POST['rec_disabled']) === 1) ? true : false;
    $old_name = trim($_POST['old_rec_name'] ?? '');
    $old_type = trim($_POST['old_rec_type'] ?? '');
    $old_content = trim($_POST['old_rec_content'] ?? '');

    if (!empty($rec_name) && !empty($rec_content)) {
        $rec_name = rtrim($rec_name, '.') . '.';
        if (!empty($old_name)) {
            $old_name = rtrim($old_name, '.') . '.';
        }
        if ($rec_type === 'SOA') {
            $soa_parts = preg_split('/\s+/', trim($rec_content));
            if (count($soa_parts) >= 2) {
                $soa_parts[0] = rtrim($soa_parts[0], '.') . '.';
                $soa_parts[1] = rtrim(str_replace('@', '.', $soa_parts[1]), '.') . '.';
                $rec_content = implode(' ', $soa_parts);
            }
        } elseif ($rec_type === 'MX') {
            if (!preg_match('/^\d+\s+/', $rec_content)) {
                $rec_content = "10 " . $rec_content;
            }
            $rec_content = rtrim($rec_content, '.') . '.';
        } elseif (in_array($rec_type, ['NS', 'CNAME', 'PTR'])) {
            $rec_content = rtrim($rec_content, '.') . '.';
        }
        if ($rec_type === 'TXT' && substr($rec_content, 0, 1) !== '"') {
            $rec_content = '"' . $rec_content . '"';
        }

        $zone_data = get_zone_data($zone_id);
        $rrsets_patch = [];

        if ($zone_data && isset($zone_data['rrsets'])) {
            if (!empty($old_name) && !empty($old_type) && !empty($old_content)) {
                foreach ($zone_data['rrsets'] as $rr) {
                    if ($rr['name'] === $old_name && $rr['type'] === $old_type) {
                        $remaining_records = [];
                        foreach ($rr['records'] as $r) {
                            if (rtrim($r['content'], '.') !== rtrim($old_content, '.')) {
                                $remaining_records[] = $r;
                            }
                        }
                        if ($old_name === $rec_name && $old_type === $rec_type) {
                            $remaining_records[] = ['content' => $rec_content, 'disabled' => $rec_disabled];
                            $comments_arr = !empty($rec_comment) ? [['account' => 'admin', 'content' => $rec_comment, 'modified_at' => time()]] : [];
                            $rrsets_patch[] = [
                                'name' => $rec_name,
                                'type' => $rec_type,
                                'ttl' => $rec_ttl,
                                'changetype' => 'REPLACE',
                                'records' => $remaining_records,
                                'comments' => $comments_arr
                            ];
                        } else {
                            if (empty($remaining_records)) {
                                $rrsets_patch[] = [
                                    'name' => $old_name,
                                    'type' => $old_type,
                                    'changetype' => 'DELETE'
                                ];
                            } else {
                                $rrsets_patch[] = [
                                    'name' => $old_name,
                                    'type' => $old_type,
                                    'ttl' => $rr['ttl'],
                                    'changetype' => 'REPLACE',
                                    'records' => $remaining_records
                                ];
                            }
                        }
                        break;
                    }
                }
            }
        }

        $already_in_patch = false;
        foreach ($rrsets_patch as $p) {
            if ($p['name'] === $rec_name && $p['type'] === $rec_type) {
                $already_in_patch = true;
                break;
            }
        }

        if (!$already_in_patch) {
            $existing_records = [];
            if ($zone_data && isset($zone_data['rrsets'])) {
                foreach ($zone_data['rrsets'] as $rr) {
                    if ($rr['name'] === $rec_name && $rr['type'] === $rec_type) {
                        $existing_records = $rr['records'];
                        break;
                    }
                }
            }
            $existing_records[] = ['content' => $rec_content, 'disabled' => $rec_disabled];
            $comments_arr = !empty($rec_comment) ? [['account' => 'admin', 'content' => $rec_comment, 'modified_at' => time()]] : [];
            $rrsets_patch[] = [
                'name' => $rec_name,
                'type' => $rec_type,
                'ttl' => $rec_ttl,
                'changetype' => 'REPLACE',
                'records' => $existing_records,
                'comments' => $comments_arr
            ];
        }

        $res = call_pdns_api('/zones/' . urlencode($zone_id), 'PATCH', ['rrsets' => $rrsets_patch]);
        if ($res['code'] === 204) {
            if ($rec_type === 'SOA') {
                $db_soa = get_pdns_db();
                if ($db_soa) {
                    $clean_soa_parts = preg_split('/\s+/', trim($rec_content));
                    if (count($clean_soa_parts) >= 2) {
                        $clean_soa_parts[0] = rtrim($clean_soa_parts[0], '.');
                        $clean_soa_parts[1] = rtrim($clean_soa_parts[1], '.');
                        $clean_soa_str = $db_soa->real_escape_string(implode(' ', $clean_soa_parts));
                        $clean_z_name = $db_soa->real_escape_string(rtrim($zone_id, '.'));
                        $db_soa->query("UPDATE records r JOIN domains d ON r.domain_id = d.id SET r.content = '{$clean_soa_str}' WHERE d.name = '{$clean_z_name}' AND r.type = 'SOA'");
                    }
                    $db_soa->close();
                }
            }
            $message = "Record saved successfully.";
            shell_exec('pdns_control purge ' . escapeshellarg($zone_id));
        } else {
            $msg_type = 'danger';
            $err = isset($res['body']['error']) ? $res['body']['error'] : 'Unknown error';
            $message = "Error saving record: " . htmlspecialchars($err);
        }
    }
}

// Handle Bulk Action on Records (Delete / Active / Passive) in Selected Zone
if (isset($_POST['bulk_records_action']) && !empty($_POST['selected_records']) && $is_active) {
    $sel_records = (array)$_POST['selected_records'];
    $action = $_POST['bulk_action'] ?? '';

    $db = get_pdns_db();
    if ($db) {
        $ids = [];
        $fallback_targets = [];
        foreach ($sel_records as $raw) {
            if (is_numeric($raw) && intval($raw) > 0) {
                $ids[] = intval($raw);
            } else {
                $parts = explode('|||', $raw);
                if (count($parts) >= 3) {
                    $fallback_targets[] = ['name' => $parts[0], 'type' => $parts[1], 'content' => $parts[2]];
                }
            }
        }

        if ($action === 'delete' || $action === 'active' || $action === 'passive') {
            $new_dis = ($action === 'passive') ? 1 : 0;
            $new_dis_sql = ($pdns_backend === 'pgsql') ? ($new_dis ? 'TRUE' : 'FALSE') : $new_dis;
            $affected_count = 0;

            if (!empty($ids)) {
                $id_list = implode(',', $ids);
                $sql = ($action === 'delete')
                    ? "DELETE FROM records WHERE id IN ({$id_list})"
                    : "UPDATE records SET disabled = {$new_dis_sql} WHERE id IN ({$id_list})";
                $db->query($sql);
                if ($db->affected_rows > 0) {
                    $affected_count += $db->affected_rows;
                }
            }

            foreach ($fallback_targets as $t) {
                $t_name = $db->real_escape_string(rtrim($t['name'], '.'));
                $t_type = $db->real_escape_string($t['type']);
                $t_cnt_raw = $db->real_escape_string($t['content']);
                $t_cnt_clean = $db->real_escape_string(rtrim($t['content'], '.'));
                $cond = "(name = '{$t_name}' OR name = '{$t_name}.') AND type = '{$t_type}' AND (content = '{$t_cnt_raw}' OR content = '{$t_cnt_clean}' OR content = '\"{$t_cnt_raw}\"')";
                $sql = ($action === 'delete')
                    ? "DELETE FROM records WHERE {$cond}"
                    : "UPDATE records SET disabled = {$new_dis_sql} WHERE {$cond}";
                $db->query($sql);
                if ($db->affected_rows > 0) {
                    $affected_count += $db->affected_rows;
                }
            }

            shell_exec("pdns_control purge >/dev/null 2>&1");
            if ($action === 'delete') {
                $message = "Successfully deleted <b>{$affected_count}</b> record(s) from zone.";
            } else {
                $st_label = ($action === 'active') ? 'Active' : 'Passive (Disabled)';
                $message = "Successfully set <b>{$affected_count}</b> record(s) to <b>{$st_label}</b>.";
            }
        }
        $db->close();
    }
}


// Handle Toggle Status (Active / Disabled) for Single Record
if (isset($_GET['toggle_rec_id']) && $is_active) {
    $t_id = intval($_GET['toggle_rec_id']);
    $db = get_pdns_db();
    if ($db && $t_id > 0) {
        $q_cur = $db->query("SELECT disabled FROM records WHERE id = {$t_id} LIMIT 1");
        if ($q_cur && $row = $q_cur->fetch_assoc()) {
            $new_dis = $row['disabled'] ? 0 : 1;
            $new_dis_sql = ($pdns_backend === 'pgsql') ? ($new_dis ? 'TRUE' : 'FALSE') : $new_dis;
            $db->query("UPDATE records SET disabled = {$new_dis_sql} WHERE id = {$t_id}");
            $status_str = $new_dis ? 'Disabled (Inactive)' : 'Active';
            $message = "Record status updated to <b>{$status_str}</b>.";
            shell_exec("pdns_control purge >/dev/null 2>&1");
        }
        $db->close();
    }
}

// Handle Import BIND Zones Action (Non-overwriting, adds only missing)
if (isset($_POST['import_bind_zones']) && $is_active) {
    $bind_dirs = ['/var/named', '/var/named/chroot/var/named'];
    $found_files = [];
    foreach ($bind_dirs as $bd) {
        if (is_dir($bd)) {
            $glob_files = glob("{$bd}/*.db");
            if (!empty($glob_files)) {
                foreach ($glob_files as $gf) {
                    $fn = basename($gf);
                    if (in_array($fn, ['named.empty', 'named.localhost', 'named.loopback', 'cache_dump.db'])) continue;
                    $dom_name = preg_replace('/\.db$/i', '', $fn);
                    $found_files[$dom_name] = $gf;
                }
            }
        }
    }

    $db = get_pdns_db();
    if (!$db) {
        $msg_type = 'danger';
        $message = "Unable to connect to database for BIND zone import.";
    } elseif (empty($found_files)) {
        $msg_type = 'warning';
        $message = "No BIND zone (.db) files found in /var/named.";
    } else {
        $new_zones_count = 0;
        $added_records_count = 0;
        $skipped_records_count = 0;

        foreach ($found_files as $domain_raw => $filepath) {
            $parsed = parse_bind_zone_file($filepath, $domain_raw);
            if (!$parsed || empty($parsed['domain'])) continue;

            $canonical_zone = rtrim($parsed['domain'], '.') . '.';
            $clean_dom = rtrim($parsed['domain'], '.');
            $clean_esc = $db->real_escape_string($clean_dom);

            // 1. Check or Create Zone in PowerDNS
            $qz = $db->query("SELECT id FROM domains WHERE name = '{$clean_esc}' LIMIT 1");
            $domain_id = 0;
            if ($qz && $zrow = $qz->fetch_assoc()) {
                $domain_id = intval($zrow['id']);
            } else {
                // Zone does not exist, create via API or direct DB
                $zone_payload = [
                    'name' => $canonical_zone,
                    'kind' => 'Native',
                    'nameservers' => []
                ];
                $res_z = call_pdns_api('/zones', 'POST', $zone_payload);
                if ($res_z['code'] === 201) {
                    $new_zones_count++;
                } else {
                    // Fallback create in DB
                    $db->query("INSERT INTO domains (name, type) VALUES ('{$clean_esc}', 'NATIVE')");
                    $domain_id = $db->insert_id;
                    $new_zones_count++;
                }

                if (!$domain_id) {
                    $qz2 = $db->query("SELECT id FROM domains WHERE name = '{$clean_esc}' LIMIT 1");
                    if ($qz2 && $zrow2 = $qz2->fetch_assoc()) {
                        $domain_id = intval($zrow2['id']);
                    }
                }
            }

            if (!$domain_id) continue;

            // 2. Fetch existing records for this domain to avoid overwriting
            $existing_set = [];
            $q_exist = $db->query("SELECT name, type, content FROM records WHERE domain_id = {$domain_id}");
            if ($q_exist) {
                while ($er = $q_exist->fetch_assoc()) {
                    $key = strtolower(rtrim($er['name'], '.')) . '|' . strtoupper($er['type']) . '|' . trim($er['content'], '."');
                    $existing_set[$key] = true;
                }
            }

            $dis_val = ($pdns_backend === 'pgsql') ? 'FALSE' : '0';
            $auth_val = ($pdns_backend === 'pgsql') ? 'TRUE' : '1';

            // 3. Process SOA: insert if missing, or update if placeholder/incomplete
            if (!empty($parsed['soa'])) {
                $soa_name_esc = $db->real_escape_string(rtrim($parsed['soa']['name'], '.'));
                $soa_ttl = intval($parsed['soa']['ttl']);
                $soa_cnt_esc = $db->real_escape_string($parsed['soa']['content']);

                $q_soa_check = $db->query("SELECT id, content FROM records WHERE domain_id = {$domain_id} AND type = 'SOA' LIMIT 1");
                if ($q_soa_check && $row_soa = $q_soa_check->fetch_assoc()) {
                    $cur_parts = preg_split('/\s+/', trim($row_soa['content']));
                    if (count($cur_parts) < 7 || stripos($row_soa['content'], 'a.misconfigured.dns.server.invalid') !== false) {
                        $db->query("UPDATE records SET content = '{$soa_cnt_esc}', ttl = {$soa_ttl} WHERE id = {$row_soa['id']}");
                    }
                } else {
                    $db->query("INSERT INTO records (domain_id, name, type, content, ttl, prio, disabled, auth)
                                VALUES ({$domain_id}, '{$soa_name_esc}', 'SOA', '{$soa_cnt_esc}', {$soa_ttl}, NULL, {$dis_val}, {$auth_val})");
                    $added_records_count++;
                }
            }

            // 4. Process Records (Add ONLY missing, DO NOT overwrite)
            foreach ($parsed['records'] as $r) {
                $check_key = strtolower(rtrim($r['name'], '.')) . '|' . strtoupper($r['type']) . '|' . trim($r['content'], '."');
                if (isset($existing_set[$check_key])) {
                    $skipped_records_count++;
                    continue;
                }

                $r_name_esc = $db->real_escape_string(rtrim($r['name'], '.'));
                $r_type_esc = $db->real_escape_string($r['type']);
                $r_ttl = intval($r['ttl']);
                $r_cnt_esc = $db->real_escape_string($r['content']);

                $db->query("INSERT INTO records (domain_id, name, type, content, ttl, prio, disabled, auth)
                            VALUES ({$domain_id}, '{$r_name_esc}', '{$r_type_esc}', '{$r_cnt_esc}', {$r_ttl}, NULL, {$dis_val}, {$auth_val})");
                if ($db->affected_rows > 0) {
                    $added_records_count++;
                    $existing_set[$check_key] = true;
                }
            }
        }

        $db->close();
        shell_exec("pdns_control rectify-all-zones >/dev/null 2>&1");
        shell_exec("pdns_control purge >/dev/null 2>&1");
        $message = "BIND Sync completed: <b>{$new_zones_count}</b> new zone(s) created, <b>{$added_records_count}</b> missing record(s) imported, <b>{$skipped_records_count}</b> existing record(s) kept untouched.";
    }
}

// Handle Bulk Action from ALL Records screen (Delete / Active / Passive by record IDs)
if (isset($_POST['bulk_all_records_action']) && !empty($_POST['selected_rec_ids']) && $is_active) {
    $db = get_pdns_db();
    if ($db) {
        $ids = array_filter(array_map('intval', (array)$_POST['selected_rec_ids']), function($v) { return $v > 0; });
        $action = $_POST['bulk_action'] ?? '';

        if (!empty($ids) && ($action === 'delete' || $action === 'active' || $action === 'passive')) {
            $id_list = implode(',', $ids);
            $new_dis = ($action === 'passive') ? 1 : 0;
            $new_dis_sql = ($pdns_backend === 'pgsql') ? ($new_dis ? 'TRUE' : 'FALSE') : $new_dis;
            $sql = ($action === 'delete')
                ? "DELETE FROM records WHERE id IN ({$id_list})"
                : "UPDATE records SET disabled = {$new_dis_sql} WHERE id IN ({$id_list})";
            $db->query($sql);
            $affected_num = $db->affected_rows;
            shell_exec("pdns_control rectify-all-zones >/dev/null 2>&1");
            shell_exec("pdns_control purge >/dev/null 2>&1");
            if ($action === 'delete') {
                $message = "Successfully deleted <b>{$affected_num}</b> DNS record(s).";
            } else {
                $st_label = ($action === 'active') ? 'Active' : 'Passive (Disabled)';
                $message = "Successfully set <b>{$affected_num}</b> record(s) to <b>{$st_label}</b>.";
            }
        }
        $db->close();
    }
}

// Handle Single Record Delete (from All Records or Zone Records)
if ((isset($_GET['all_del_id']) || isset($_GET['del_rec_id'])) && $is_active) {
    $del_id = intval($_GET['all_del_id'] ?? $_GET['del_rec_id'] ?? 0);
    $db = get_pdns_db();
    if ($db && $del_id > 0) {
        $db->query("DELETE FROM records WHERE id = {$del_id}");
        if ($db->affected_rows > 0) {
            shell_exec("pdns_control rectify-all-zones >/dev/null 2>&1");
            shell_exec("pdns_control purge >/dev/null 2>&1");
            $message = "Record deleted successfully.";
        }
        $db->close();
    }
} elseif (isset($_GET['del_rec_zone'], $_GET['del_rec_name'], $_GET['del_rec_type'], $_GET['del_rec_content']) && $is_active) {
    $zone_id = $_GET['del_rec_zone'];
    $del_name = $_GET['del_rec_name'];
    $del_type = $_GET['del_rec_type'];
    $del_content = $_GET['del_rec_content'];

    $zone_data = get_zone_data($zone_id);
    if ($zone_data && isset($zone_data['rrsets'])) {
        foreach ($zone_data['rrsets'] as $rr) {
            if ($rr['name'] === $del_name && $rr['type'] === $del_type) {
                $remaining_records = [];
                foreach ($rr['records'] as $r) {
                    if ($r['content'] !== $del_content) {
                        $remaining_records[] = $r;
                    }
                }
                $patch_rrset = [
                    'name' => $del_name,
                    'type' => $del_type,
                    'changetype' => empty($remaining_records) ? 'DELETE' : 'REPLACE'
                ];
                if (!empty($remaining_records)) {
                    $patch_rrset['ttl'] = $rr['ttl'];
                    $patch_rrset['records'] = $remaining_records;
                }
                $res = call_pdns_api('/zones/' . urlencode($zone_id), 'PATCH', ['rrsets' => [$patch_rrset]]);
                if ($res['code'] === 204) {
                    $message = "Record deleted successfully.";
                    shell_exec('pdns_control purge ' . escapeshellarg($zone_id));
                }
                break;
            }
        }
    }
}

// Single delete from DNS Queries
if (isset($_GET['del_query_id'])) {
    $del_qid = intval($_GET['del_query_id']);
    $db = get_pdns_db();
    if ($db && $del_qid > 0) {
        $db->query("DELETE FROM dnsqueries WHERE id = {$del_qid}");
        if ($db->affected_rows > 0) {
            $message = "DNS query log entry deleted successfully.";
        }
        $db->close();
    }
}

// Bulk delete from DNS Queries
if (isset($_POST['bulk_delete_queries']) && !empty($_POST['selected_query_ids'])) {
    $db = get_pdns_db();
    if ($db) {
        $ids = array_filter(array_map('intval', (array)$_POST['selected_query_ids']), function($v) { return $v > 0; });
        if (!empty($ids)) {
            $id_list = implode(',', $ids);
            $db->query("DELETE FROM dnsqueries WHERE id IN ({$id_list})");
            $deleted_cnt = $db->affected_rows;
            $message = "Successfully deleted <b>{$deleted_cnt}</b> DNS query log(s).";
        }
        $db->close();
    }
}

// Clear all DNS Queries Table
if (isset($_POST['clear_all_queries'])) {
    $db = get_pdns_db();
    if ($db) {
        $db->query("TRUNCATE TABLE dnsqueries");
        $message = "All DNS query logs have been deleted successfully.";
        $db->close();
    }
}

// Fetch Zones
$zones = [];

if ($is_active) {
    $zone_res = call_pdns_api('/zones');
    if ($zone_res['code'] === 200 && is_array($zone_res['body'])) {
        $zones = $zone_res['body'];
    }
}

// Map database IDs to zones (or load directly from MySQL if service is stopped)
$db = get_pdns_db();
if ($db) {
    if (!empty($zones)) {
        $dom_id_map = [];
        $qz = $db->query("SELECT id, name FROM domains");
        if ($qz) {
            while ($r_dom = $qz->fetch_assoc()) {
                $dom_id_map[rtrim($r_dom['name'], '.') . '.'] = intval($r_dom['id']);
            }
        }
        foreach ($zones as &$z_item) {
            $z_item['db_id'] = $dom_id_map[$z_item['name']] ?? 0;
        }
        unset($z_item);
    } else {
        $qz = $db->query("SELECT id, name, type FROM domains");
        if ($qz) {
            while ($row = $qz->fetch_assoc()) {
                $z_canonical = rtrim($row['name'], '.') . '.';
                $zones[] = [
                    'id' => $z_canonical,
                    'name' => $z_canonical,
                    'kind' => $row['type'],
                    'db_id' => intval($row['id'])
                ];
            }
        }
    }
    $db->close();
}

// Search & Server-Side Global Sorting logic
$sort_by = $_GET['sort_by'] ?? 'id';
$sort_dir = strtolower($_GET['sort_dir'] ?? 'desc');
if ($sort_dir !== 'asc') $sort_dir = 'desc';

$zone_filter = trim($_GET['zone_filter'] ?? '');
if (!empty($zone_filter)) {
    $zones = array_filter($zones, function($item) use ($zone_filter) {
        return stripos($item['name'], $zone_filter) !== false;
    });
}

// Global Sorting on all zones array before pagination
if ($sort_by === 'id') {
    usort($zones, function($a, $b) use ($sort_dir) {
        $valA = intval($a['db_id'] ?? 0);
        $valB = intval($b['db_id'] ?? 0);
        return ($sort_dir === 'asc') ? ($valA <=> $valB) : ($valB <=> $valA);
    });
} else {
    usort($zones, function($a, $b) use ($sort_dir) {
        $cmp = strcasecmp(trim($a['name'], '.'), trim($b['name'], '.'));
        return ($sort_dir === 'asc') ? $cmp : -$cmp;
    });
}

// Read current config
$current_config_content = file_exists($config_file) ? file_get_contents($config_file) : '';

// Active Tab
$tab = $_GET['tab'] ?? 'zones';

// Manage Zone selection & Flatten records
$selected_zone_details = null;
$flat_records = [];
$edit_record = null;

if (isset($_GET['manage_zone'])) {
    $z_id = $_GET['manage_zone'];
    $selected_zone_details = get_zone_data($z_id);
    if ($selected_zone_details) {
        $tab = 'zones';
        // Fetch records directly from DB for this zone to guarantee exact ID, content, and disabled status
        $db = get_pdns_db();
        if ($db) {
            $clean_z = $db->real_escape_string(rtrim($selected_zone_details['name'], '.'));
            $qr = $db->query("SELECT r.id, r.name, r.type, r.ttl, r.content, r.disabled, c.comment
                              FROM records r
                              JOIN domains d ON r.domain_id = d.id
                              LEFT JOIN comments c ON (r.domain_id = c.domain_id AND r.name = c.name AND r.type = c.type)
                              WHERE d.name = '{$clean_z}' AND r.type IS NOT NULL AND r.type != ''");
            if ($qr) {
                while ($rec_row = $qr->fetch_assoc()) {
                    $flat_records[] = [
                        'id' => intval($rec_row['id']),
                        'name' => $rec_row['name'],
                        'type' => $rec_row['type'],
                        'ttl' => intval($rec_row['ttl']),
                        'content' => $rec_row['content'],
                        'disabled' => !empty($rec_row['disabled']),
                        'comment' => $rec_row['comment'] ?? ''
                    ];
                }
            }
            $db->close();
        }

        $rec_type_filter = trim($_GET['rec_type_filter'] ?? '');
        if (!empty($rec_type_filter)) {
            $flat_records = array_filter($flat_records, function($item) use ($rec_type_filter) {
                return strcasecmp($item['type'] ?? '', $rec_type_filter) === 0;
            });
        }

        $rec_filter = trim($_GET['rec_filter'] ?? '');
        if (!empty($rec_filter)) {
            $flat_records = array_filter($flat_records, function($item) use ($rec_filter) {
                return (stripos($item['name'], $rec_filter) !== false ||
                        stripos($item['content'], $rec_filter) !== false ||
                        stripos($item['type'], $rec_filter) !== false ||
                        stripos($item['comment'] ?? '', $rec_filter) !== false);
            });
        }

        $sort_rec = $_GET['sort_rec'] ?? 'id';
        $sort_rec_dir = strtolower($_GET['sort_rec_dir'] ?? 'desc');
        if ($sort_rec_dir !== 'asc') $sort_rec_dir = 'desc';

        usort($flat_records, function($a, $b) use ($sort_rec, $sort_rec_dir) {
            if ($sort_rec === 'id' || $sort_rec === 'ttl') {
                $cmp = intval($a[$sort_rec] ?? 0) <=> intval($b[$sort_rec] ?? 0);
            } elseif (in_array($sort_rec, ['type', 'content', 'name'])) {
                $cmp = strcasecmp($a[$sort_rec] ?? '', $b[$sort_rec] ?? '');
            } else {
                $cmp = strcasecmp($a['name'] ?? '', $b['name'] ?? '');
            }
            return ($sort_rec_dir === 'asc') ? $cmp : -$cmp;
        });

        if (isset($_GET['edit_id']) && intval($_GET['edit_id']) > 0) {
            $e_id = intval($_GET['edit_id']);
            foreach ($flat_records as $fr) {
                if (intval($fr['id']) === $e_id) {
                    $edit_record = $fr;
                    break;
                }
            }
        }

        if (!$edit_record && isset($_GET['edit_name'], $_GET['edit_type'])) {
            $e_name = trim($_GET['edit_name']);
            $e_type = trim($_GET['edit_type']);
            $e_content = trim($_GET['edit_content'] ?? '');
            $clean_e_name = rtrim($e_name, '.');
            $clean_e_content = rtrim(trim($e_content, '"'), '.');

            foreach ($flat_records as $fr) {
                if (strcasecmp(rtrim($fr['name'], '.'), $clean_e_name) === 0 && strcasecmp($fr['type'], $e_type) === 0) {
                    $fr_content_clean = rtrim(trim($fr['content'], '"'), '.');
                    if (empty($clean_e_content) || strcasecmp($fr_content_clean, $clean_e_content) === 0) {
                        $edit_record = $fr;
                        break;
                    }
                    if (!$edit_record) {
                        $edit_record = $fr;
                    }
                }
            }

            if (!$edit_record) {
                $edit_record = [
                    'name' => $e_name,
                    'type' => $e_type,
                    'ttl' => 300,
                    'content' => $e_content,
                    'comment' => ''
                ];
            }
        }
    }
}

// Per-page limit selector (25, 50, 100, 250, 500)
$allowed_per_page = [25, 50, 100, 250, 500];
$per_page = isset($_GET['per_page']) ? intval($_GET['per_page']) : 25;
if (!in_array($per_page, $allowed_per_page)) {
    $per_page = 25;
}

// Pagination helper function
function paginate_array($items, $page_param, $per_page = 25) {
    $current_page = max(1, intval($_GET[$page_param] ?? 1));
    $total_items = count($items);
    $total_pages = ceil($total_items / $per_page);
    $offset = ($current_page - 1) * $per_page;
    $paginated = array_slice($items, $offset, $per_page);
    return [
        'items' => $paginated,
        'page' => $current_page,
        'total_items' => $total_items,
        'total_pages' => $total_pages,
        'param' => $page_param
    ];
}

// Data fetch for ALL DNS Records tab
$all_db_records = [];
$all_rec_total = 0;
$all_rec_filter = trim($_GET['all_rec_filter'] ?? '');
$all_type_filter = trim($_GET['all_type_filter'] ?? '');
$all_rec_sort = $_GET['all_sort'] ?? 'id';
$all_rec_order = strtolower($_GET['all_order'] ?? 'desc');
if (!in_array($all_rec_order, ['asc', 'desc'])) $all_rec_order = 'desc';

$allowed_all_sorts = [
    'id' => 'r.id',
    'name' => 'r.name',
    'type' => 'r.type',
    'ttl' => 'r.ttl',
    'content' => 'r.content',
    'zone' => 'd.name'
];
$sort_sql_col = $allowed_all_sorts[$all_rec_sort] ?? 'r.id';

if ($tab === 'records') {
    $db = get_pdns_db();
    if ($db) {
        $where_clauses = ["r.type IS NOT NULL AND r.type != ''"];
        if (!empty($all_type_filter)) {
            $t_flt = $db->real_escape_string($all_type_filter);
            $where_clauses[] = "r.type = '{$t_flt}'";
        }
        if (!empty($all_rec_filter)) {
            $flt = $db->real_escape_string($all_rec_filter);
            $where_clauses[] = "(r.name LIKE '%{$flt}%' OR r.content LIKE '%{$flt}%' OR r.type LIKE '%{$flt}%' OR d.name LIKE '%{$flt}%')";
        }
        $where_sql = implode(' AND ', $where_clauses);

        // Count total
        $res_cnt = $db->query("SELECT COUNT(*) as c FROM records r LEFT JOIN domains d ON r.domain_id = d.id WHERE {$where_sql}");
        if ($res_cnt && $r_c = $res_cnt->fetch_assoc()) {
            $all_rec_total = intval($r_c['c']);
        }

        // Pagination
        $all_rec_page = max(1, intval($_GET['p_all'] ?? 1));
        $all_rec_limit = $per_page;
        $all_rec_offset = ($all_rec_page - 1) * $all_rec_limit;

        $q_str = "SELECT r.id, r.name, r.type, r.content, r.ttl, r.disabled, d.name as zone_name, r.domain_id, c.comment
                  FROM records r
                  LEFT JOIN domains d ON r.domain_id = d.id
                  LEFT JOIN comments c ON (r.domain_id = c.domain_id AND r.name = c.name AND r.type = c.type)
                  WHERE {$where_sql}
                  ORDER BY {$sort_sql_col} {$all_rec_order}
                  LIMIT {$all_rec_limit} OFFSET {$all_rec_offset}";
        $res_records = $db->query($q_str);
        if ($res_records) {
            while ($row = $res_records->fetch_assoc()) {
                $all_db_records[] = $row;
            }
        }
        $db->close();
    }
}
$all_rec_pages = ceil($all_rec_total / $per_page);

// Data fetch for Analytics tab (DNS Query Logs from MySQL database: Client - Domain - Type - Response - Other - Time)
$dnsqueries = [];
$total_query_logs_count = 0;
$q_filter = trim($_GET['q_filter'] ?? '');
$q_type_filter = trim($_GET['q_type_filter'] ?? '');
$q_response_filter = trim($_GET['q_response_filter'] ?? '');
$q_sort = $_GET['q_sort'] ?? 'id';
$q_order = strtolower($_GET['q_order'] ?? 'desc');
if (!in_array($q_order, ['asc', 'desc'])) $q_order = 'desc';

$allowed_q_sorts = [
    'id'       => 'id',
    'client'   => 'client',
    'domain'   => 'domain',
    'type'     => 'type',
    'response' => 'response',
    'size'     => 'size',
    'time'     => 'time'
];
$sort_q_sql_col = $allowed_q_sorts[$q_sort] ?? 'id';

if ($tab === 'queries') {
    $db = get_pdns_db();
    if ($db) {
        $where_clauses = ["1=1"];
        if (!empty($q_type_filter)) {
            $t_flt = $db->real_escape_string($q_type_filter);
            $where_clauses[] = "type = '{$t_flt}'";
        }
        if (!empty($q_response_filter)) {
            $r_flt = $db->real_escape_string($q_response_filter);
            $where_clauses[] = "response = '{$r_flt}'";
        }
        if (!empty($q_filter)) {
            $flt = $db->real_escape_string($q_filter);
            $where_clauses[] = "(client LIKE '%{$flt}%' OR domain LIKE '%{$flt}%' OR type LIKE '%{$flt}%' OR response LIKE '%{$flt}%' OR proto LIKE '%{$flt}%' OR flags LIKE '%{$flt}%' OR time LIKE '%{$flt}%')";
        }
        $where_sql = implode(' AND ', $where_clauses);

        // Count total queries in DB
        $res_cnt = $db->query("SELECT COUNT(*) as c FROM dnsqueries WHERE {$where_sql}");
        if ($res_cnt && $r_c = $res_cnt->fetch_assoc()) {
            $total_query_logs_count = intval($r_c['c']);
        }

        // Pagination directly via SQL LIMIT / OFFSET
        $q_page = max(1, intval($_GET['p_queries'] ?? 1));
        $q_limit = $per_page;
        $q_offset = ($q_page - 1) * $q_limit;

        $q_str = "SELECT id, client, domain, type, response, proto, size, flags, time 
                  FROM dnsqueries 
                  WHERE {$where_sql} 
                  ORDER BY {$sort_q_sql_col} {$q_order} 
                  LIMIT {$q_limit} OFFSET {$q_offset}";
        $res_q = $db->query($q_str);
        if ($res_q) {
            while ($row = $res_q->fetch_assoc()) {
                $dnsqueries[] = [
                    'id'       => intval($row['id']),
                    'client'   => $row['client'],
                    'domain'   => $row['domain'],
                    'type'     => $row['type'],
                    'response' => $row['response'] ?? 'NOERROR',
                    'proto'    => $row['proto'] ?? 'UDP',
                    'size'     => intval($row['size'] ?? 0),
                    'flags'    => $row['flags'] ?? '',
                    'time'     => $row['time']
                ];
            }
        }
        $db->close();
    }
}

$paginated_queries = [
    'items'       => $dnsqueries,
    'page'        => max(1, intval($_GET['p_queries'] ?? 1)),
    'total_items' => $total_query_logs_count,
    'total_pages' => ceil($total_query_logs_count / $per_page),
    'param'       => 'p_queries'
];
$paginated_zones = paginate_array($zones, 'p_zones', $per_page);
$paginated_records = paginate_array($flat_records, 'p_records', $per_page);

function render_sort_indicator($active_col, $active_dir, $col) {
    if ($active_col === $col) {
        return ($active_dir === 'asc') ? ' <span style="color:#2f80ed; font-weight:bold;">▲</span>' : ' <span style="color:#2f80ed; font-weight:bold;">▼</span>';
    }
    return ' <span style="color:#aaa; font-size:11px;">⇅</span>';
}

function get_q_sort_url($col) {
    global $q_sort, $q_order, $q_filter, $q_type_filter, $per_page;
    $new_order = ($q_sort === $col && $q_order === 'asc') ? 'desc' : 'asc';
    $params = [
        'module'   => 'powerdns-manager',
        'tab'      => 'queries',
        'q_sort'   => $col,
        'q_order'  => $new_order,
        'per_page' => $per_page
    ];
    if (!empty($q_filter)) $params['q_filter'] = $q_filter;
    if (!empty($q_type_filter)) $params['q_type_filter'] = $q_type_filter;
    return 'index.php?' . http_build_query($params);
}

function get_q_sort_indicator($col) {
    global $q_sort, $q_order;
    return render_sort_indicator($q_sort, $q_order, $col);
}

function get_all_sort_url($col) {
    global $all_rec_sort, $all_rec_order, $all_rec_filter, $all_type_filter, $per_page;
    $new_order = ($all_rec_sort === $col && $all_rec_order === 'asc') ? 'desc' : 'asc';
    $params = [
        'module' => 'powerdns-manager',
        'tab' => 'records',
        'all_sort' => $col,
        'all_order' => $new_order,
        'per_page' => $per_page
    ];
    if (!empty($all_rec_filter)) $params['all_rec_filter'] = $all_rec_filter;
    if (!empty($all_type_filter)) $params['all_type_filter'] = $all_type_filter;
    return 'index.php?' . http_build_query($params);
}

function get_all_sort_indicator($col) {
    global $all_rec_sort, $all_rec_order;
    return render_sort_indicator($all_rec_sort, $all_rec_order, $col);
}

function get_sort_url($col) {
    global $sort_by, $sort_dir, $zone_filter, $per_page;
    $next_dir = ($sort_by === $col && $sort_dir === 'asc') ? 'desc' : 'asc';
    $url = "index.php?module=powerdns-manager&tab=zones&sort_by=" . urlencode($col) . "&sort_dir=" . $next_dir . "&per_page=" . intval($per_page);
    if (!empty($zone_filter)) $url .= "&zone_filter=" . urlencode($zone_filter);
    return $url;
}

function get_sort_indicator($col) {
    global $sort_by, $sort_dir;
    return render_sort_indicator($sort_by, $sort_dir, $col);
}

function get_rec_sort_url($col) {
    global $selected_zone_details, $sort_rec, $sort_rec_dir, $per_page;
    $next_dir = ($sort_rec === $col && $sort_rec_dir === 'asc') ? 'desc' : 'asc';
    $url = "index.php?module=powerdns-manager&tab=zones&manage_zone=" . urlencode($selected_zone_details['id']) . "&sort_rec=" . urlencode($col) . "&sort_rec_dir=" . $next_dir . "&per_page=" . intval($per_page);
    if (!empty($_GET['rec_filter'])) $url .= "&rec_filter=" . urlencode($_GET['rec_filter']);
    if (!empty($_GET['rec_type_filter'])) $url .= "&rec_type_filter=" . urlencode($_GET['rec_type_filter']);
    return $url;
}

function get_rec_sort_indicator($col) {
    global $sort_rec, $sort_rec_dir;
    return render_sort_indicator($sort_rec, $sort_rec_dir, $col);
}

function render_table_footer($shown_count, $total_items, $current_page, $total_pages, $unit_label, $page_url_base, $per_page, $allowed_per_page) {
    ?>
    <div style="margin-top:15px; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px;">
        <div style="display:flex; align-items:center; gap:12px;">
            <span style="font-size:12px; color:#666;">Showing <?php echo intval($shown_count); ?> of <?php echo number_format($total_items); ?> <?php echo htmlspecialchars($unit_label); ?> (Page <?php echo intval($current_page); ?> / <?php echo max(1, intval($total_pages)); ?>)</span>
            <div style="display:inline-flex; align-items:center; gap:5px;">
                <span style="font-size:12px; color:#666;">Show:</span>
                <select onchange="changePerPage(this.value)" class="form-control" style="height:28px; width:auto; padding:2px 8px; font-size:12px; border-radius:4px; border:1px solid #ced4da;" title="Items per page">
                    <?php foreach ($allowed_per_page as $pp): ?>
                        <option value="<?php echo $pp; ?>" <?php echo ($per_page === $pp) ? 'selected' : ''; ?>><?php echo $pp; ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        <?php if ($total_pages > 1): ?>
        <ul class="pagination" style="display:inline-flex; padding:0; margin:0; list-style:none;">
            <?php if ($current_page > 1): ?>
                <li style="margin-right:4px;"><a href="<?php echo $page_url_base . ($current_page - 1); ?>" class="btn btn-default btn-xs" style="padding:3px 7px; border:1px solid #ccc; text-decoration:none; color:#333;">« Prev</a></li>
            <?php endif; ?>
            <?php
            $start_p = max(1, $current_page - 2);
            $end_p = min($total_pages, $current_page + 2);
            for ($p = $start_p; $p <= $end_p; $p++):
            ?>
                <li style="margin-right:4px;">
                    <a href="<?php echo $page_url_base . $p; ?>" class="btn btn-xs" style="padding:3px 7px; border:1px solid #ccc; text-decoration:none; <?php echo ($p === $current_page) ? 'background:#2f80ed; color:#fff; border-color:#2f80ed; font-weight:bold;' : 'color:#333;'; ?>"><?php echo $p; ?></a>
                </li>
            <?php endfor; ?>
            <?php if ($current_page < $total_pages): ?>
                <li><a href="<?php echo $page_url_base . ($current_page + 1); ?>" class="btn btn-default btn-xs" style="padding:3px 7px; border:1px solid #ccc; text-decoration:none; color:#333;">Next »</a></li>
            <?php endif; ?>
        </ul>
        <?php endif; ?>
    </div>
    <?php
}

// Supported record types (alphabetically sorted)
$supported_types = [
    'A' => 'A (IPv4 Address)',
    'AAAA' => 'AAAA (IPv6 Address)',
    'ALIAS' => 'ALIAS (PowerDNS Auto-Flattening ALIAS)',
    'CAA' => 'CAA (Certification Authority Authorization)',
    'CNAME' => 'CNAME (Canonical Name / Alias)',
    'DNSKEY' => 'DNSKEY (DNSSEC Key)',
    'DS' => 'DS (Delegation Signer)',
    'MX' => 'MX (Mail Exchanger)',
    'NAPTR' => 'NAPTR (Naming Authority Pointer)',
    'NS' => 'NS (Name Server)',
    'PTR' => 'PTR (Pointer / Reverse DNS)',
    'SOA' => 'SOA (Start of Authority)',
    'SRV' => 'SRV (Service Locator)',
    'SSHFP' => 'SSHFP (SSH Fingerprint)',
    'TLSA' => 'TLSA (TLS Authentication)',
    'TXT' => 'TXT (Text / SPF / DKIM)'
];
ksort($supported_types);
?>
<style type="text/css">
.pdns-card {
    background: #fff;
    border: 1px solid #dcdfe6;
    border-radius: 6px;
    padding: 20px;
    box-shadow: 0 1px 4px rgba(0,0,0,0.06);
    margin-bottom: 25px;
}
.pdns-table {
    width: 100%;
    margin: 0;
    border-collapse: collapse;
    background: #fff;
    border: 1px solid #e4e7ed;
    font-size: 13px;
}
.pdns-table thead tr {
    background: #f5f7fa;
}
.pdns-table thead th {
    padding: 10px 12px;
    font-weight: 700;
    color: #495057;
    border: 1px solid #e4e7ed;
    vertical-align: middle;
    text-align: left !important;
}
.pdns-table tbody tr {
    transition: background 0.1s ease;
}
.pdns-table tbody tr:hover {
    background: #f9fbfd !important;
}
.pdns-table tbody tr:nth-child(even) {
    background: #fafbfd;
}
.pdns-table tbody td {
    padding: 9px 12px;
    border: 1px solid #e4e7ed;
    color: #333;
    vertical-align: middle;
    text-align: left !important;
}
.btn-action-edit {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 28px;
    height: 28px;
    background: #2f80ed !important;
    color: #ffffff !important;
    border: none;
    border-radius: 4px;
    text-decoration: none !important;
    transition: background 0.15s ease;
}
.btn-action-edit:hover {
    background: #1b5fc0 !important;
    color: #ffffff !important;
}
.btn-action-edit i, .btn-action-edit span {
    color: #ffffff !important;
}
.pdns-bulk-toolbar {
    display: inline-flex;
    align-items: center;
    background: transparent;
    border: none;
    padding: 0;
    gap: 6px;
}
.pdns-bulk-label {
    font-size: 13px;
    font-weight: 700;
    color: #495057;
    margin-right: 4px;
    padding-left: 0;
    user-select: none;
}
.pdns-bulk-btn {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    padding: 4px 10px;
    font-size: 12px;
    font-weight: 600;
    border-radius: 4px;
    cursor: pointer;
    line-height: 1.4;
    border: none;
    transition: all 0.15s ease;
    color: #ffffff !important;
}
.pdns-bulk-btn i, .pdns-bulk-btn span {
    color: #ffffff !important;
}
.pdns-bulk-btn-delete {
    background: #6c757d !important;
    border-color: #5a6268 !important;
    color: #ffffff !important;
}
.pdns-bulk-btn-delete:hover {
    background: #545b62 !important;
    color: #ffffff !important;
}
.pdns-bulk-btn-active {
    background: #28a745 !important;
    border-color: #218838 !important;
    color: #ffffff !important;
}
.pdns-bulk-btn-active:hover {
    background: #218838 !important;
    color: #ffffff !important;
}
.pdns-bulk-btn-passive {
    background: #dc3545 !important;
    border-color: #bd2130 !important;
    color: #ffffff !important;
}
.pdns-bulk-btn-passive:hover {
    background: #bd2130 !important;
    color: #ffffff !important;
}
.btn-action-delete {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 28px;
    height: 28px;
    background: #6c757d !important;
    color: #ffffff !important;
    border: none;
    border-radius: 4px;
    text-decoration: none !important;
    transition: background 0.15s ease;
}
.btn-action-delete:hover {
    background: #545b62 !important;
    color: #ffffff !important;
}
.btn-action-delete i, .btn-action-delete span {
    color: #ffffff !important;
}
.btn-bind-sync {
    display: inline-flex !important;
    align-items: center !important;
    gap: 6px !important;
    height: 28px !important;
    padding: 0 12px !important;
    background-color: #28a745 !important;
    background: #28a745 !important;
    color: #ffffff !important;
    border: 1px solid #218838 !important;
    border-radius: 4px !important;
    font-size: 12px !important;
    font-weight: 700 !important;
    text-decoration: none !important;
    cursor: pointer !important;
    line-height: 1 !important;
    box-shadow: none !important;
    text-shadow: none !important;
}
.btn-bind-sync:hover {
    background-color: #218838 !important;
    background: #218838 !important;
    border-color: #1e7e34 !important;
    color: #ffffff !important;
}
.btn-bind-sync i, .btn-bind-sync span {
    color: #ffffff !important;
}
.btn-back-zones {
    display: inline-flex !important;
    align-items: center !important;
    gap: 6px !important;
    height: 30px !important;
    padding: 0 14px !important;
    font-size: 12px !important;
    font-weight: 700 !important;
    border-radius: 4px !important;
    background: #ffffff !important;
    background-color: #ffffff !important;
    border: 1px solid #ced4da !important;
    color: #000000 !important;
    text-decoration: none !important;
    cursor: pointer !important;
    line-height: 1 !important;
    box-shadow: 0 1px 2px rgba(0,0,0,0.05) !important;
    text-shadow: none !important;
}
.btn-back-zones:hover {
    background-color: #f8f9fa !important;
    background: #f8f9fa !important;
    border-color: #adb5bd !important;
    color: #000000 !important;
}
.btn-back-zones i, .btn-back-zones span {
    color: #000000 !important;
}
.pdns-tab-link {
    padding: 10px 18px;
    display: block;
    text-decoration: none;
    border-radius: 4px 4px 0 0;
    font-weight: bold;
}
.pdns-tab-link.active {
    background: #2f80ed !important;
    color: #ffffff !important;
}
.pdns-tab-link.active i {
    color: #ffffff !important;
}
.pdns-tab-link.inactive {
    background: #f8f9fa !important;
    color: #333333 !important;
    border: 1px solid #ddd !important;
    border-bottom: none !important;
}
</style>
<div class="row" style="margin-bottom:20px;">
    <div class="col-md-12">
        <div style="display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:10px; padding:0 2px;">
            <div style="display:flex; align-items:center;">
                <h3 style="margin:0 15px 0 0; font-size:20px; font-weight:bold; color:#17283a;">
                    <i class="icon16 icomoon-icon-lightning" style="color:#2f80ed; margin-right:6px;"></i> PowerDNS
                </h3>
                <?php if ($is_active): ?>
                    <span class="badge" style="background:#28a745; color:#ffffff; font-size:12px; font-weight:bold; padding:5px 12px; border-radius:4px; display:inline-flex; align-items:center;">
                        <i class="icon16 icomoon-icon-checkmark-circle" style="color:#ffffff; margin-right:5px;"></i> Service Running
                    </span>
                <?php else: ?>
                    <span class="badge" style="background:#dc3545; color:#ffffff; font-size:12px; font-weight:bold; padding:5px 12px; border-radius:4px; display:inline-flex; align-items:center;">
                        <i class="icon16 icomoon-icon-cancel-circle" style="color:#ffffff; margin-right:5px;"></i> Service Stopped
                    </span>
                <?php endif; ?>
            </div>
            <div>
                <form method="POST" style="margin:0; display:inline-block;">
                    <?php if ($is_active): ?>
                        <button type="submit" name="service_action" value="restart" class="btn btn-warning" style="margin-right:6px; background:#ffc107; border:none; padding:7px 15px; font-weight:bold; cursor:pointer; border-radius:4px;"><i class="icon16 icomoon-icon-power"></i> Restart</button>
                        <button type="submit" name="service_action" value="stop" class="btn btn-danger" style="background:#dc3545; color:#fff; border:none; padding:7px 15px; font-weight:bold; cursor:pointer; border-radius:4px;"><i class="icon16 icomoon-icon-cancel-circle"></i> Stop</button>
                    <?php else: ?>
                        <button type="submit" name="service_action" value="start" class="btn btn-success" style="background:#28a745; color:#fff; border:none; padding:7px 15px; font-weight:bold; cursor:pointer; border-radius:4px;"><i class="icon16 icomoon-icon-power"></i> Start</button>
                    <?php endif; ?>
                </form>
            </div>
        </div>
    </div>
</div>
<?php if (!empty($message)): ?>
<div class="alert alert-<?php echo $msg_type; ?>" style="padding:10px 15px; margin-bottom:15px; border-radius:4px; <?php echo ($msg_type==='danger')?'background:#f8d7da;color:#721c24;border:1px solid #f5c6cb;':'background:#d4edda;color:#155724;border:1px solid #c3e6cb;'; ?>">
    <?php echo $message; ?>
</div>
<?php endif; ?>
<form method="POST" id="form-import-bind" style="display:none;">
    <input type="hidden" name="import_bind_zones" value="1">
</form>
<?php
// Count total records in DB for tab badge
$total_all_records_count = 0;
$db_stat = get_pdns_db();
if ($db_stat) {
    $q_cnt = $db_stat->query("SELECT COUNT(*) as c FROM records");
    if ($q_cnt && $r_cnt = $q_cnt->fetch_assoc()) {
        $total_all_records_count = intval($r_cnt['c']);
    }
    $db_stat->close();
}
?>
<ul class="nav nav-tabs" style="margin-bottom:20px; border-bottom:2px solid #ddd; display:flex; list-style:none; padding:0;">
    <li style="margin-right:8px;">
        <a href="index.php?module=powerdns-manager&tab=zones" class="pdns-tab-link <?php echo ($tab==='zones') ? 'active' : 'inactive'; ?>">
            <i class="icon16 icomoon-icon-earth" style="<?php echo ($tab==='zones') ? 'color:#ffffff !important;' : ''; ?>"></i> DNS Zones (<?php echo count($zones); ?>)
        </a>
    </li>
    <li style="margin-right:8px;">
        <a href="index.php?module=powerdns-manager&tab=records" class="pdns-tab-link <?php echo ($tab==='records') ? 'active' : 'inactive'; ?>">
            <i class="icon16 icomoon-icon-list" style="<?php echo ($tab==='records') ? 'color:#ffffff !important;' : ''; ?>"></i> DNS Records (<?php echo number_format($total_all_records_count); ?>)
        </a>
    </li>
    <li style="margin-right:8px;">
        <a href="index.php?module=powerdns-manager&tab=queries" class="pdns-tab-link <?php echo ($tab==='queries') ? 'active' : 'inactive'; ?>">
            <i class="icon16 icomoon-icon-stats-up" style="<?php echo ($tab==='queries') ? 'color:#ffffff !important;' : ''; ?>"></i> DNS Queries
        </a>
    </li>
    <li>
        <a href="index.php?module=powerdns-manager&tab=config" class="pdns-tab-link <?php echo ($tab==='config') ? 'active' : 'inactive'; ?>">
            <i class="icon16 icomoon-icon-cogs" style="<?php echo ($tab==='config') ? 'color:#ffffff !important;' : ''; ?>"></i> Settings
        </a>
    </li>
</ul>
<?php if ($tab === 'records'): ?>
<div class="row">
    <div class="col-md-12">
        <div class="pdns-card">
            <form method="POST" id="form-bulk-all-records">
                <input type="hidden" name="bulk_all_records_action" value="1">
                <input type="hidden" name="bulk_action" value="delete">
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:15px; flex-wrap:wrap; gap:10px;">
                    <div style="display:flex; align-items:center;">
                        <h4 style="margin:0; font-size:16px;">
                            <i class="icon16 icomoon-icon-list" style="color:#2f80ed; margin-right:5px;"></i> <b>All DNS Records</b>
                        </h4>
                    </div>
                    <div style="display:flex; align-items:center;">
                        <div class="pdns-bulk-toolbar">
                            <span class="pdns-bulk-label">Selected:</span>
                            <button type="button" onclick="confirmBulkAction('form-bulk-all-records', 'selected_rec_ids[]', 'delete')" class="pdns-bulk-btn pdns-bulk-btn-delete" title="Delete selected records">
                                <i class="icon16 icomoon-icon-remove"></i> Delete
                            </button>
                            <button type="button" onclick="confirmBulkAction('form-bulk-all-records', 'selected_rec_ids[]', 'active')" class="pdns-bulk-btn pdns-bulk-btn-active" title="Activate selected records">
                                <i class="icon16 icomoon-icon-checkmark"></i> Active
                            </button>
                            <button type="button" onclick="confirmBulkAction('form-bulk-all-records', 'selected_rec_ids[]', 'passive')" class="pdns-bulk-btn pdns-bulk-btn-passive" title="Deactivate/Disable selected records">
                                <i class="icon16 icomoon-icon-cancel"></i> Passive
                            </button>
                        </div>
                    </div>
                    <div style="display:flex; align-items:center; gap:10px;">
                        <span style="font-size:13px; color:#555;">Total: <b><?php echo number_format($all_rec_total); ?></b> records</span>
                        <select id="select_all_type_filter" onchange="triggerFilter('records', 'all_type_filter', this.value)" class="form-control" style="height:30px; width:auto; padding:2px 8px; font-size:12px; border-radius:4px; border:1px solid #ced4da;" title="Filter by Record Type">
                            <option value="">All Types</option>
                            <?php foreach ($supported_types as $st_code => $st_label): ?>
                                <option value="<?php echo htmlspecialchars($st_code); ?>" <?php echo (strcasecmp($all_type_filter, $st_code) === 0) ? 'selected' : ''; ?>><?php echo htmlspecialchars($st_code); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <div style="display:inline-flex; align-items:center;">
                            <input type="search" id="input_all_rec_filter" value="<?php echo htmlspecialchars($all_rec_filter); ?>" placeholder="Search records..." onkeydown="if(event.keyCode===13){event.preventDefault(); triggerFilter('records', 'all_rec_filter', this.value);}" class="form-control" style="height:30px; width:220px; padding:4px 10px; font-size:12px; border-radius:4px; border:1px solid #ced4da;">
                        </div>
                    </div>
                </div>
                <table class="pdns-table" id="tbl-all-records">
                    <thead>
                        <tr>
                            <th style="width:40px;">
                                <input type="checkbox" id="check-all-records" onclick="toggleSelectAll(this, 'selected_rec_ids[]')" title="Select All on page">
                            </th>
                            <th style="width:55px;"><a href="<?php echo get_all_sort_url('id'); ?>" style="color:#495057; text-decoration:none; display:flex; gap:3px;">ID <?php echo get_all_sort_indicator('id'); ?></a></th>
                            <th><a href="<?php echo get_all_sort_url('name'); ?>" style="color:#495057; text-decoration:none; display:flex;">Record Name <?php echo get_all_sort_indicator('name'); ?></a></th>
                            <th style="width:85px;"><a href="<?php echo get_all_sort_url('type'); ?>" style="color:#495057; text-decoration:none; display:flex;">Type <?php echo get_all_sort_indicator('type'); ?></a></th>
                            <th style="width:75px;"><a href="<?php echo get_all_sort_url('ttl'); ?>" style="color:#495057; text-decoration:none; display:flex; gap:5px;">TTL <?php echo get_all_sort_indicator('ttl'); ?></a></th>
                            <th><a href="<?php echo get_all_sort_url('content'); ?>" style="color:#495057; text-decoration:none; display:flex;">Value <?php echo get_all_sort_indicator('content'); ?></a></th>
                            <th style="width:80px;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($all_db_records)): ?>
                            <?php foreach ($all_db_records as $r):
                                $clean_r_name = trim_dns_dot($r['name']);
                            ?>
                            <tr>
                                <td>
                                    <input type="checkbox" name="selected_rec_ids[]" value="<?php echo intval($r['id']); ?>" class="rec-checkbox">
                                </td>
                                <td style="color:#888; font-weight:bold; font-size:12px;"><?php echo intval($r['id']); ?></td>
                                <td><a href="http://<?php echo htmlspecialchars($clean_r_name); ?>" target="_blank" style="font-weight:normal; color:#2f80ed; text-decoration:none;"><?php echo htmlspecialchars($clean_r_name); ?></a></td>
                                <td>
                                    <?php if (empty($r['disabled'])): ?>
                                        <a href="index.php?module=powerdns-manager&tab=records&toggle_rec_id=<?php echo intval($r['id']); ?>" class="badge" style="background:#28a745; color:#fff; text-decoration:none; padding:3px 7px; border-radius:3px; font-weight:bold;" title="Active record (Click to disable)"><?php echo htmlspecialchars($r['type']); ?></a>
                                    <?php else: ?>
                                        <a href="index.php?module=powerdns-manager&tab=records&toggle_rec_id=<?php echo intval($r['id']); ?>" class="badge" style="background:#dc3545; color:#fff; text-decoration:none; padding:3px 7px; border-radius:3px; font-weight:bold;" title="Disabled record (Click to activate)"><?php echo htmlspecialchars($r['type']); ?></a>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo htmlspecialchars($r['ttl']); ?></td>
                                <td style="word-break:break-all; overflow-wrap:break-word;">
                                    <div style="word-break:break-all; overflow-wrap:break-word;"><?php echo htmlspecialchars(trim_dns_dot($r['content'])); ?></div>
                                    <?php if (!empty($r['comment'])): ?>
                                        <div style="margin-top:3px; font-size:11.5px; color:#6c757d;">
                                            # <?php echo htmlspecialchars($r['comment']); ?>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div style="display:inline-flex; align-items:center; justify-content:center; gap:6px;">
                                        <a href="index.php?module=powerdns-manager&tab=zones&manage_zone=<?php echo urlencode($r['zone_name'] . '.'); ?>&edit_id=<?php echo intval($r['id']); ?>&edit_name=<?php echo urlencode($r['name']); ?>&edit_type=<?php echo urlencode($r['type']); ?>&edit_content=<?php echo urlencode($r['content']); ?>" class="btn-action-edit" title="Edit in Zone"><i class="icon16 icomoon-icon-pencil"></i></a>
                                        <a href="index.php?module=powerdns-manager&tab=records&all_del_id=<?php echo intval($r['id']); ?>" onclick="return confirm('Delete this record?');" class="btn-action-delete" title="Delete Record"><i class="icon16 icomoon-icon-remove"></i></a>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr><td colspan="7" style="padding:20px; color:#888;">No DNS records found matching criteria.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </form>
            <?php
            $all_page_url_base = "index.php?module=powerdns-manager&tab=records&all_sort=" . urlencode($all_rec_sort) . "&all_order=" . urlencode($all_rec_order) . "&per_page=" . intval($per_page) . (!empty($all_rec_filter) ? "&all_rec_filter=" . urlencode($all_rec_filter) : "") . (!empty($all_type_filter) ? "&all_type_filter=" . urlencode($all_type_filter) : "") . "&p_all=";
            echo render_table_footer(count($all_db_records), $all_rec_total, $all_rec_page, $all_rec_pages, 'records', $all_page_url_base, $per_page, $allowed_per_page);
            ?>
        </div>
    </div>
</div>
<?php endif; ?>
<?php if ($tab === 'queries'): ?>
<?php if (!$is_active): ?>
<div class="alert alert-warning" style="background:#fff3cd; color:#856404; border:1px solid #ffeeba; padding:12px 15px; border-radius:4px; margin-bottom:20px;">
    <i class="icon16 icomoon-icon-warning"></i> PowerDNS service is currently stopped. Start the service to collect live DNS query logs.
</div>
<?php endif; ?>
<form method="POST" id="form-clear-queries" style="display:none;">
    <input type="hidden" name="clear_all_queries" value="1">
</form>
<div class="row" style="margin-bottom:25px;">
    <div class="col-md-12">
        <div class="pdns-card">
            <form method="POST" id="form-bulk-queries">
                <input type="hidden" name="bulk_delete_queries" value="1">
                <input type="hidden" name="bulk_action" value="delete">
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:15px; flex-wrap:wrap; gap:10px;">
                    <div style="display:flex; align-items:center;">
                        <h4 style="margin:0; font-size:16px;">
                            <i class="icon16 icomoon-icon-stats-up" style="color:#2f80ed; margin-right:5px;"></i>
                            <b>DNS Queries</b>
                        </h4>
                    </div>
                    <div style="display:flex; align-items:center; gap:8px;">
                        <div class="pdns-bulk-toolbar">
                            <span class="pdns-bulk-label">Selected:</span>
                            <button type="button" onclick="confirmBulkAction('form-bulk-queries', 'selected_query_ids[]', 'delete')" class="pdns-bulk-btn pdns-bulk-btn-delete" title="Delete selected queries">
                                <i class="icon16 icomoon-icon-remove"></i> Delete
                            </button>
                        </div>
                        <button type="button" onclick="confirmClearAllQueries()" class="pdns-bulk-btn pdns-bulk-btn-delete" title="Delete all query logs table">
                            <i class="icon16 icomoon-icon-remove"></i> Delete All
                        </button>
                    </div>
                    <div style="display:flex; align-items:center; gap:10px;">
                        <span style="font-size:13px; color:#555;">Total: <b><?php echo number_format($paginated_queries['total_items']); ?></b> queries</span>
                        <select id="select_q_type_filter" onchange="triggerFilter('queries', 'q_type_filter', this.value)" class="form-control" style="height:30px; width:auto; padding:2px 8px; font-size:12px; border-radius:4px; border:1px solid #ced4da;" title="Filter by Query Type">
                            <option value="">All Types</option>
                            <?php
                            $q_types_list = array_unique(array_merge(array_keys($supported_types), ['ANY', 'HTTPS', 'SVCB']));
                            sort($q_types_list);
                            foreach ($q_types_list as $qt):
                            ?>
                                <option value="<?php echo htmlspecialchars($qt); ?>" <?php echo (strcasecmp($q_type_filter, $qt) === 0) ? 'selected' : ''; ?>><?php echo htmlspecialchars($qt); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <select id="select_q_response_filter" onchange="triggerFilter('queries', 'q_response_filter', this.value)" class="form-control" style="height:30px; width:auto; padding:2px 8px; font-size:12px; border-radius:4px; border:1px solid #ced4da;" title="Filter by Response">
                            <option value="">All Responses</option>
                            <?php
                            $rcodes_list = ['NOERROR', 'NXDOMAIN', 'SERVFAIL', 'REFUSED', 'FORMERR', 'NOTIMP', 'NOTAUTH'];
                            foreach ($rcodes_list as $rc_item):
                            ?>
                                <option value="<?php echo htmlspecialchars($rc_item); ?>" <?php echo (strcasecmp($q_response_filter, $rc_item) === 0) ? 'selected' : ''; ?>><?php echo htmlspecialchars($rc_item); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <input type="search" id="input_q_filter" value="<?php echo htmlspecialchars($q_filter); ?>" placeholder="Search queries..." onkeydown="if(event.keyCode===13){event.preventDefault(); triggerFilter('queries', 'q_filter', this.value);}" class="form-control" style="height:30px; width:220px; padding:4px 10px; font-size:12px; border-radius:4px; border:1px solid #ced4da;">
                    </div>
                </div>
                <table class="pdns-table" id="tbl-dns-queries">
                    <thead>
                        <tr>
                            <th style="width:40px;">
                                <input type="checkbox" id="check-all-queries" onclick="toggleSelectAll(this, 'selected_query_ids[]')" title="Select All Queries on page">
                            </th>
                            <th style="width:160px;"><a href="<?php echo get_q_sort_url('client'); ?>" style="color:#495057; text-decoration:none; display:flex;">Client <?php echo get_q_sort_indicator('client'); ?></a></th>
                            <th><a href="<?php echo get_q_sort_url('domain'); ?>" style="color:#495057; text-decoration:none; display:flex;">Domain <?php echo get_q_sort_indicator('domain'); ?></a></th>
                            <th style="width:90px;"><a href="<?php echo get_q_sort_url('type'); ?>" style="color:#495057; text-decoration:none; display:flex;">Type <?php echo get_q_sort_indicator('type'); ?></a></th>
                            <th style="width:110px;"><a href="<?php echo get_q_sort_url('response'); ?>" style="color:#495057; text-decoration:none; display:flex;">Response <?php echo get_q_sort_indicator('response'); ?></a></th>
                            <th style="width:150px;"><a href="<?php echo get_q_sort_url('size'); ?>" style="color:#495057; text-decoration:none; display:flex;">Other <?php echo get_q_sort_indicator('size'); ?></a></th>
                            <th style="width:160px;"><a href="<?php echo get_q_sort_url('time'); ?>" style="color:#495057; text-decoration:none; display:flex;">Time <?php echo get_q_sort_indicator('time'); ?></a></th>
                            <th style="width:70px;">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($paginated_queries['items'])): ?>
                            <?php foreach ($paginated_queries['items'] as $q_item): ?>
                            <tr>
                                <td>
                                    <input type="checkbox" name="selected_query_ids[]" value="<?php echo $q_item['id']; ?>" class="query-checkbox">
                                </td>
                                <td><code><?php echo htmlspecialchars($q_item['client']); ?></code></td>
                                <td>
                                    <a href="http://<?php echo htmlspecialchars($q_item['domain']); ?>" target="_blank" style="font-weight:normal; color:#2f80ed; text-decoration:none;"><?php echo htmlspecialchars($q_item['domain']); ?></a>
                                </td>
                                <td>
                                    <span class="badge" style="background:#2f80ed; color:#fff; padding:3px 7px; border-radius:3px; font-weight:bold;"><?php echo htmlspecialchars($q_item['type']); ?></span>
                                </td>
                                <td>
                                    <?php
                                    $rc = strtoupper($q_item['response'] ?? 'NOERROR');
                                    $rc_color = '#28a745'; // Green for NOERROR
                                    if ($rc === 'NXDOMAIN') {
                                        $rc_color = '#dc3545'; // Red for not found
                                    } elseif (in_array($rc, ['SERVFAIL', 'REFUSED'])) {
                                        $rc_color = '#fd7e14'; // Orange for servfail/refused
                                    } elseif ($rc !== 'NOERROR') {
                                        $rc_color = '#6c757d'; // Grey
                                    }
                                    ?>
                                    <span class="badge" style="background:<?php echo $rc_color; ?>; color:#fff; padding:3px 7px; border-radius:3px; font-weight:bold; font-size:11px;"><?php echo htmlspecialchars($rc); ?></span>
                                </td>
                                <td>
                                    <?php
                                    $other_parts = [];
                                    if (!empty($q_item['proto'])) {
                                        $other_parts[] = $q_item['proto'];
                                    }
                                    if (!empty($q_item['size']) && $q_item['size'] > 0) {
                                        $other_parts[] = $q_item['size'] . 'B';
                                    }
                                    if (!empty($q_item['flags'])) {
                                        $other_parts[] = $q_item['flags'];
                                    }
                                    $other_text = !empty($other_parts) ? implode(' • ', $other_parts) : '—';
                                    ?>
                                    <span style="color:#555; font-size:11.5px;"><?php echo htmlspecialchars($other_text); ?></span>
                                </td>
                                <td style="color:#555; font-size:12px;"><?php echo htmlspecialchars($q_item['time']); ?></td>
                                <td>
                                    <a href="index.php?module=powerdns-manager&tab=queries&del_query_id=<?php echo $q_item['id']; ?>&per_page=<?php echo intval($per_page); ?>" onclick="return confirm('Delete this query log entry?');" class="btn-action-delete" title="Delete Log"><i class="icon16 icomoon-icon-remove"></i></a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr><td colspan="8" style="padding:15px; color:#888;">No DNS queries found.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </form>
            <?php
            $q_url_base = "index.php?module=powerdns-manager&tab=queries&q_sort=" . urlencode($q_sort) . "&q_order=" . urlencode($q_order) . "&per_page=" . intval($per_page) . ($q_filter !== '' ? "&q_filter=" . urlencode($q_filter) : "") . ($q_type_filter !== '' ? "&q_type_filter=" . urlencode($q_type_filter) : "") . ($q_response_filter !== '' ? "&q_response_filter=" . urlencode($q_response_filter) : "") . "&p_queries=";
            echo render_table_footer(count($paginated_queries['items']), $paginated_queries['total_items'], $paginated_queries['page'], $paginated_queries['total_pages'], 'queries', $q_url_base, $per_page, $allowed_per_page);
            ?>
        </div>
    </div>
</div>
<?php elseif ($tab === 'zones'): ?>
<?php if ($selected_zone_details): ?>
<div class="row" style="margin-bottom:25px;">
    <div class="col-md-12">
        <div class="pdns-card">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px; padding-bottom:14px; border-bottom:1px solid #e9ecef; flex-wrap:wrap; gap:12px;">
                <div style="display:flex; align-items:center; gap:8px;">
                    <h3 style="margin:0; font-size:18px; line-height:1.3; display:inline-flex; align-items:center; flex-wrap:wrap; gap:6px;">
                        <i class="icon16 icomoon-icon-earth" style="color:#2f80ed; font-size:18px;"></i>
                        <span>Managing DNS Records:</span>
                        <span style="color:#2f80ed; font-weight:700;"><?php echo htmlspecialchars(trim_dns_dot($selected_zone_details['name'])); ?></span>
                    </h3>
                </div>
                <div>
                    <a href="index.php?module=powerdns-manager&tab=zones" class="btn-back-zones" style="background:#ffffff !important; background-color:#ffffff !important; color:#000000 !important; border:1px solid #ced4da !important;" title="Return to DNS Zones List">
                        <i class="icon16 icomoon-icon-arrow-left" style="color:#000000 !important; font-size:12px !important;"></i> <span style="color:#000000 !important; font-weight:700 !important;">Back to DNS Zones</span>
                    </a>
                </div>
            </div>
            <form method="POST" style="background:#f8f9fa; padding:18px; border-radius:6px; margin-bottom:20px; border:1px solid #e9ecef;">
                <input type="hidden" name="zone_id" value="<?php echo htmlspecialchars($selected_zone_details['id']); ?>">
                <?php if ($edit_record): ?>
                    <input type="hidden" name="old_rec_name" value="<?php echo htmlspecialchars($edit_record['name']); ?>">
                    <input type="hidden" name="old_rec_type" value="<?php echo htmlspecialchars($edit_record['type']); ?>">
                    <input type="hidden" name="old_rec_content" value="<?php echo htmlspecialchars($edit_record['content']); ?>">
                    <h5 style="margin-top:0; margin-bottom:14px; color:#2f80ed; font-weight:bold; display:inline-flex; align-items:center; gap:6px;">
                        <i class="icon16 icomoon-icon-pencil"></i> <span>Edit DNS Record:</span> <code style="margin-left:4px; font-size:12px;"><?php echo htmlspecialchars(trim_dns_dot($edit_record['name'])); ?> [<?php echo htmlspecialchars($edit_record['type']); ?>]</code>
                    </h5>
                <?php else: ?>
                    <h5 style="margin-top:0; margin-bottom:14px; font-weight:bold; display:inline-flex; align-items:center; gap:6px;">
                        <i class="icon16 icomoon-icon-plus" style="color:#28a745;"></i> <span>Add New DNS Record</span>
                    </h5>
                <?php endif; ?>
                <div class="row" style="margin-bottom:10px;">
                    <div class="col-md-6">
                        <label style="font-size:12px; font-weight:bold; margin-bottom:3px;">Record Name (FQDN) <span style="color:#dc3545;">*</span>:</label>
                        <input type="text" name="rec_name" class="form-control" placeholder="Record name" value="<?php echo htmlspecialchars(trim_dns_dot($edit_record['name'] ?? $selected_zone_details['name'])); ?>" required style="width:100%; padding:7px; box-sizing:border-box;">
                    </div>
                    <div class="col-md-4">
                        <label style="font-size:12px; font-weight:bold; margin-bottom:3px;">Record Type:</label>
                        <select name="rec_type" class="form-control" style="width:100%; padding:7px; box-sizing:border-box;">
                            <?php
                            $curr_type = $edit_record['type'] ?? 'A';
                            foreach ($supported_types as $st_code => $st_label):
                            ?>
                                <option value="<?php echo $st_code; ?>" <?php echo ($curr_type === $st_code)?'selected':''; ?>><?php echo $st_label; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label style="font-size:12px; font-weight:bold; margin-bottom:3px;">TTL (Seconds):</label>
                        <input type="number" name="rec_ttl" class="form-control" value="<?php echo htmlspecialchars($edit_record['ttl'] ?? 300); ?>" placeholder="TTL" style="width:100%; padding:7px; box-sizing:border-box;">
                    </div>
                </div>
                <div class="row" style="margin-bottom:15px;">
                    <div class="col-md-12">
                        <label style="font-size:12px; font-weight:bold; margin-bottom:3px;">Value <span style="color:#dc3545;">*</span>:</label>
                        <textarea name="rec_content" class="form-control" rows="3" placeholder="Record value" required style="width:100%; padding:8px; box-sizing:border-box; resize:vertical;"><?php echo htmlspecialchars(trim_dns_dot($edit_record['content'] ?? '')); ?></textarea>
                    </div>
                </div>
                <div class="row" style="margin-bottom:15px;">
                    <div class="col-md-9">
                        <label style="font-size:12px; font-weight:bold; margin-bottom:3px; color:#555;">Comment:</label>
                        <input type="text" name="rec_comment" class="form-control" placeholder="Comment" value="<?php echo htmlspecialchars($edit_record['comment'] ?? ''); ?>" style="width:100%; padding:8px; box-sizing:border-box; font-size:12px;">
                    </div>
                    <div class="col-md-3">
                        <label style="font-size:12px; font-weight:bold; margin-bottom:3px; color:#555;">Record Status:</label>
                        <select name="rec_disabled" class="form-control" style="width:100%; padding:7px; box-sizing:border-box;">
                            <option value="0" <?php echo (empty($edit_record['disabled'])) ? 'selected' : ''; ?>>Active (Enabled)</option>
                            <option value="1" <?php echo (!empty($edit_record['disabled'])) ? 'selected' : ''; ?>>Passive (Disabled)</option>
                        </select>
                    </div>
                </div>
                <div style="display:flex; justify-content:space-between; align-items:center;">
                    <div>
                        <?php if ($edit_record): ?>
                            <a href="index.php?module=powerdns-manager&tab=zones&manage_zone=<?php echo urlencode($selected_zone_details['id']); ?>" class="btn btn-default btn-sm" style="border:1px solid #ccc;">Cancel Editing</a>
                        <?php endif; ?>
                    </div>
                    <button type="submit" name="save_record" class="btn btn-primary" style="background:#2f80ed; color:#fff; border:none; padding:8px 24px; border-radius:4px; cursor:pointer; font-weight:bold;">
                        <?php echo ($edit_record)?'Update Record':'Save Record'; ?>
                    </button>
                </div>
            </form>
            <form method="POST" id="form-bulk-records">
                <input type="hidden" name="bulk_records_action" value="1">
                <input type="hidden" name="bulk_action" value="delete">
                <input type="hidden" name="zone_id" value="<?php echo htmlspecialchars($selected_zone_details['id']); ?>">
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:15px; flex-wrap:wrap; gap:10px;">
                    <div style="display:flex; align-items:center; gap:8px;">
                        <h4 style="margin:0; font-size:16px;">
                            <i class="icon16 icomoon-icon-list" style="color:#2f80ed; margin-right:5px;"></i> <b>DNS Records</b>
                        </h4>
                    </div>
                    <div style="display:flex; align-items:center;">
                        <div class="pdns-bulk-toolbar">
                            <span class="pdns-bulk-label">Selected:</span>
                            <button type="button" onclick="confirmBulkAction('form-bulk-records', 'selected_records[]', 'delete')" class="pdns-bulk-btn pdns-bulk-btn-delete" title="Delete selected records">
                                <i class="icon16 icomoon-icon-remove"></i> Delete
                            </button>
                            <button type="button" onclick="confirmBulkAction('form-bulk-records', 'selected_records[]', 'active')" class="pdns-bulk-btn pdns-bulk-btn-active" title="Activate selected records">
                                <i class="icon16 icomoon-icon-checkmark"></i> Active
                            </button>
                            <button type="button" onclick="confirmBulkAction('form-bulk-records', 'selected_records[]', 'passive')" class="pdns-bulk-btn pdns-bulk-btn-passive" title="Deactivate/Disable selected records">
                                <i class="icon16 icomoon-icon-cancel"></i> Passive
                            </button>
                        </div>
                    </div>
                    <div style="display:flex; align-items:center; gap:10px;">
                        <span style="font-size:13px; color:#555;">Total: <b><?php echo count($flat_records); ?></b> records</span>
                        <select id="select_rec_type_filter" onchange="triggerFilter('zones', 'rec_type_filter', this.value, '<?php echo urlencode($selected_zone_details['id']); ?>')" class="form-control" style="height:30px; width:auto; padding:2px 8px; font-size:12px; border-radius:4px; border:1px solid #ced4da;" title="Filter by Record Type">
                            <option value="">All Types</option>
                            <?php foreach ($supported_types as $st_code => $st_label): ?>
                                <option value="<?php echo htmlspecialchars($st_code); ?>" <?php echo (strcasecmp($rec_type_filter ?? '', $st_code) === 0) ? 'selected' : ''; ?>><?php echo htmlspecialchars($st_code); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <div style="display:inline-flex; align-items:center;">
                            <input type="search" id="input_rec_filter" value="<?php echo htmlspecialchars($_GET['rec_filter'] ?? ''); ?>" placeholder="Search records..." onkeydown="if(event.keyCode===13){event.preventDefault(); triggerFilter('zones', 'rec_filter', this.value, '<?php echo urlencode($selected_zone_details['id']); ?>');}" class="form-control" style="height:30px; width:220px; padding:4px 10px; font-size:12px; border-radius:4px; border:1px solid #ced4da;">
                        </div>
                    </div>
                </div>
            <table class="pdns-table" id="tbl-records">
                <thead>
                    <tr>
                        <th style="width:40px;">
                            <input type="checkbox" id="check-all-records-zone" onclick="toggleSelectAll(this, 'selected_records[]')" title="Select All Records on page">
                        </th>
                        <th style="width:55px;"><a href="<?php echo get_rec_sort_url('id'); ?>" style="color:#495057; text-decoration:none; display:flex; gap:3px;">ID <?php echo get_rec_sort_indicator('id'); ?></a></th>
                        <th><a href="<?php echo get_rec_sort_url('name'); ?>" style="color:#495057; text-decoration:none; display:flex;">Record Name <?php echo get_rec_sort_indicator('name'); ?></a></th>
                        <th style="width:90px;"><a href="<?php echo get_rec_sort_url('type'); ?>" style="color:#495057; text-decoration:none; display:flex;">Type <?php echo get_rec_sort_indicator('type'); ?></a></th>
                        <th style="width:80px;"><a href="<?php echo get_rec_sort_url('ttl'); ?>" style="color:#495057; text-decoration:none; display:flex; gap:5px;">TTL <?php echo get_rec_sort_indicator('ttl'); ?></a></th>
                        <th><a href="<?php echo get_rec_sort_url('content'); ?>" style="color:#495057; text-decoration:none; display:flex;">Value <?php echo get_rec_sort_indicator('content'); ?></a></th>
                        <th style="width:90px;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!empty($paginated_records['items'])): ?>
                        <?php
                        foreach ($paginated_records['items'] as $row):
                            $clean_row_name = trim_dns_dot($row['name']);
                        ?>
                        <tr>
                            <td>
                                <input type="checkbox" name="selected_records[]" value="<?php echo !empty($row['id']) ? intval($row['id']) : htmlspecialchars($row['name'] . '|||' . $row['type'] . '|||' . $row['content']); ?>" class="rec-checkbox">
                            </td>
                            <td style="color:#888; font-weight:bold; font-size:12px;"><?php echo !empty($row['id']) ? intval($row['id']) : '—'; ?></td>
                            <td><a href="http://<?php echo htmlspecialchars($clean_row_name); ?>" target="_blank" style="font-weight:normal; color:#2f80ed; text-decoration:none;"><?php echo htmlspecialchars($clean_row_name); ?></a></td>
                            <td>
                                <?php if (empty($row['disabled'])): ?>
                                    <a href="index.php?module=powerdns-manager&tab=zones&manage_zone=<?php echo urlencode($selected_zone_details['id']); ?>&toggle_rec_id=<?php echo intval($row['id'] ?? 0); ?>" class="badge" style="background:#28a745; color:#fff; text-decoration:none; padding:3px 7px; border-radius:3px; font-weight:bold;" title="Active record (Click to disable)"><?php echo htmlspecialchars($row['type']); ?></a>
                                <?php else: ?>
                                    <a href="index.php?module=powerdns-manager&tab=zones&manage_zone=<?php echo urlencode($selected_zone_details['id']); ?>&toggle_rec_id=<?php echo intval($row['id'] ?? 0); ?>" class="badge" style="background:#dc3545; color:#fff; text-decoration:none; padding:3px 7px; border-radius:3px; font-weight:bold;" title="Disabled record (Click to activate)"><?php echo htmlspecialchars($row['type']); ?></a>
                                <?php endif; ?>
                            </td>
                            <td><?php echo htmlspecialchars($row['ttl']); ?></td>
                            <td style="word-break:break-all; overflow-wrap:break-word;">
                                <div style="word-break:break-all; overflow-wrap:break-word;"><?php echo htmlspecialchars(trim_dns_dot($row['content'])); ?></div>
                                <?php if (!empty($row['comment'])): ?>
                                    <div style="margin-top:3px; font-size:11.5px; color:#6c757d;">
                                        # <?php echo htmlspecialchars($row['comment']); ?>
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div style="display:inline-flex; align-items:center; justify-content:center; gap:6px;">
                                    <a href="index.php?module=powerdns-manager&tab=zones&manage_zone=<?php echo urlencode($selected_zone_details['id']); ?>&edit_id=<?php echo intval($row['id'] ?? 0); ?>&edit_name=<?php echo urlencode($row['name']); ?>&edit_type=<?php echo urlencode($row['type']); ?>&edit_content=<?php echo urlencode($row['content']); ?>" class="btn-action-edit" title="Edit Record"><i class="icon16 icomoon-icon-pencil"></i></a>
                                    <a href="index.php?module=powerdns-manager&tab=zones&manage_zone=<?php echo urlencode($selected_zone_details['id']); ?>&del_rec_id=<?php echo intval($row['id'] ?? 0); ?>&del_rec_zone=<?php echo urlencode($selected_zone_details['id']); ?>&del_rec_name=<?php echo urlencode($row['name']); ?>&del_rec_type=<?php echo urlencode($row['type']); ?>&del_rec_content=<?php echo urlencode($row['content']); ?>" onclick="return confirm('Delete this record?');" class="btn-action-delete" title="Delete Record"><i class="icon16 icomoon-icon-remove"></i></a>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr><td colspan="7" style="padding:15px; color:#888;">No DNS records matching filter.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
            </form>
            <?php
            $rec_page_base = "index.php?module=powerdns-manager&tab=zones&manage_zone=" . urlencode($selected_zone_details['id']) . "&sort_rec=" . urlencode($sort_rec) . "&sort_rec_dir=" . urlencode($sort_rec_dir) . "&per_page=" . intval($per_page) . (!empty($_GET['rec_filter']) ? "&rec_filter=" . urlencode($_GET['rec_filter']) : "") . (!empty($_GET['rec_type_filter']) ? "&rec_type_filter=" . urlencode($_GET['rec_type_filter']) : "") . "&p_records=";
            echo render_table_footer(count($paginated_records['items']), $paginated_records['total_items'], $paginated_records['page'], $paginated_records['total_pages'], 'records', $rec_page_base, $per_page, $allowed_per_page);
            ?>
        </div>
    </div>
</div>
<?php else: ?>
<div class="row" style="margin-bottom:25px;">
    <div class="col-md-12">
        <div class="pdns-card">
            <h4 style="margin-top:0; font-size:16px; margin-bottom:15px;">
                <i class="icon16 icomoon-icon-plus" style="color:#28a745; margin-right:5px;"></i> <b>Add New DNS Zone</b>
            </h4>
            <form method="POST">
                <div class="row" style="margin-bottom:12px;">
                    <div class="col-md-4">
                        <label style="font-size:12px; font-weight:bold; margin-bottom:3px;">Domain Name <span style="color:#dc3545;">*</span>:</label>
                        <input type="text" name="domain_name" id="new_domain_input" class="form-control" placeholder="Domain name" required style="width:100%; padding:7px; box-sizing:border-box;">
                    </div>
                    <div class="col-md-4">
                        <label style="font-size:12px; font-weight:bold; margin-bottom:3px;">Primary Name Server (NS1):</label>
                        <input type="text" name="ns1_name" class="form-control" placeholder="Primary nameserver" style="width:100%; padding:7px; box-sizing:border-box;">
                    </div>
                    <div class="col-md-4">
                        <label style="font-size:12px; font-weight:bold; margin-bottom:3px;">Secondary Name Server (NS2):</label>
                        <input type="text" name="ns2_name" class="form-control" placeholder="Secondary nameserver" style="width:100%; padding:7px; box-sizing:border-box;">
                    </div>
                </div>
                <div style="margin-bottom:15px;">
                    <div class="row">
                        <div class="col-md-4">
                            <label style="font-size:11px; margin-bottom:2px;">Hostmaster Email / RNAME:</label>
                            <input type="text" name="soa_hostmaster" class="form-control input-sm" value="<?php echo htmlspecialchars($default_hostname); ?>" style="width:100%; padding:5px; font-size:12px;">
                        </div>
                        <div class="col-md-2">
                            <label style="font-size:11px; margin-bottom:2px;">Serial (YYYYMMDDNN):</label>
                            <input type="text" name="soa_serial" class="form-control input-sm" value="<?php echo date('Ymd01'); ?>" style="width:100%; padding:5px; font-size:12px;">
                        </div>
                        <div class="col-md-1">
                            <label style="font-size:11px; margin-bottom:2px;">TTL (sec):</label>
                            <input type="number" name="soa_ttl" class="form-control input-sm" value="3600" style="width:100%; padding:5px; font-size:12px;">
                        </div>
                        <div class="col-md-1">
                            <label style="font-size:11px; margin-bottom:2px;">Refresh:</label>
                            <input type="number" name="soa_refresh" class="form-control input-sm" value="10800" style="width:100%; padding:5px; font-size:12px;" title="Refresh (default: 10800 - 3h)">
                        </div>
                        <div class="col-md-1">
                            <label style="font-size:11px; margin-bottom:2px;">Retry:</label>
                            <input type="number" name="soa_retry" class="form-control input-sm" value="3600" style="width:100%; padding:5px; font-size:12px;" title="Retry (default: 3600 - 1h)">
                        </div>
                        <div class="col-md-1">
                            <label style="font-size:11px; margin-bottom:2px;">Expire:</label>
                            <input type="number" name="soa_expire" class="form-control input-sm" value="604800" style="width:100%; padding:5px; font-size:12px;" title="Expire (default: 604800 - 7d)">
                        </div>
                        <div class="col-md-2">
                            <label style="font-size:11px; margin-bottom:2px;">Negative TTL / Min:</label>
                            <input type="number" name="soa_minimum" class="form-control input-sm" value="3600" style="width:100%; padding:5px; font-size:12px;" title="Minimum (default: 3600 - 1h)">
                        </div>
                    </div>
                </div>
                <div style="text-align:right;">
                    <button type="submit" name="add_zone" class="btn btn-primary" style="background:#2f80ed; color:#fff; border:none; padding:8px 24px; border-radius:4px; cursor:pointer; font-weight:bold;">
                        <i class="icon16 icomoon-icon-checkmark"></i> Create Zone
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
<div class="row" style="margin-bottom:25px;">
    <div class="col-md-12">
        <div class="pdns-card">
            <form method="POST" id="form-bulk-zones">
                <input type="hidden" name="bulk_delete_zones" value="1">
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:15px; flex-wrap:wrap; gap:10px;">
                    <div style="display:flex; align-items:center; gap:12px;">
                        <h4 style="margin:0; font-size:16px;">
                            <i class="icon16 icomoon-icon-earth" style="color:#2f80ed; margin-right:5px;"></i> <b>DNS Zones</b>
                        </h4>
                        <button type="button" onclick="if(confirm('Scan /var/named and sync missing zones and records into PowerDNS?\n\nExisting records will NOT be overwritten.')){document.getElementById('form-import-bind').submit();}" class="btn-bind-sync" title="Scan /var/named and sync missing zones and records without overwriting">
                            <i class="icon16 icomoon-icon-loop" style="color:#ffffff !important; font-size:11px; margin-right:4px;"></i> <span style="color:#ffffff !important;">BIND Sync</span>
                        </button>
                    </div>
                    <div style="display:flex; align-items:center;">
                        <div class="pdns-bulk-toolbar">
                            <span class="pdns-bulk-label">Selected:</span>
                            <button type="button" onclick="confirmBulkAction('form-bulk-zones', 'selected_zones[]', 'delete')" class="pdns-bulk-btn pdns-bulk-btn-delete" title="Delete selected zones">
                                <i class="icon16 icomoon-icon-remove"></i> Delete
                            </button>
                        </div>
                    </div>
                    <div style="display:flex; align-items:center; gap:10px;">
                        <span style="font-size:13px; color:#555;">Total: <b><?php echo count($zones); ?></b> zones</span>
                        <div style="display:inline-flex; align-items:center;">
                            <input type="search" id="input_zone_filter" value="<?php echo htmlspecialchars($zone_filter); ?>" placeholder="Search domains..." onkeydown="if(event.keyCode===13){event.preventDefault(); triggerFilter('zones', 'zone_filter', this.value);}" class="form-control" style="height:30px; width:220px; padding:4px 10px; font-size:12px; border-radius:4px; border:1px solid #ced4da;">
                        </div>
                    </div>
                </div>
            <table class="pdns-table" id="tbl-zones">
                <thead>
                    <tr>
                        <th style="width:40px;">
                            <input type="checkbox" id="check-all-zones" onclick="toggleSelectAll(this, 'selected_zones[]')" title="Select All Zones on page">
                        </th>
                        <th style="width:55px;"><a href="<?php echo get_sort_url('id'); ?>" style="color:#495057; text-decoration:none; display:flex; align-items:center; gap:3px;">ID <?php echo get_sort_indicator('id'); ?></a></th>
                        <th><a href="<?php echo get_sort_url('name'); ?>" style="color:#495057; text-decoration:none; display:flex; align-items:center;">Domain Name <?php echo get_sort_indicator('name'); ?></a></th>
                        <th style="width:120px;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!empty($paginated_zones['items'])): ?>
                        <?php foreach ($paginated_zones['items'] as $z): ?>
                            <tr>
                                <td>
                                    <input type="checkbox" name="selected_zones[]" value="<?php echo htmlspecialchars($z['id']); ?>" class="zone-checkbox">
                                </td>
                                <td style="color:#888; font-weight:bold; font-size:12px;"><?php echo !empty($z['db_id']) ? intval($z['db_id']) : '—'; ?></td>
                                <td>
                                    <a href="index.php?module=powerdns-manager&tab=zones&manage_zone=<?php echo urlencode($z['id']); ?>&per_page=<?php echo intval($per_page); ?>" style="font-weight:bold; color:#2f80ed; text-decoration:none;">
                                        <?php echo htmlspecialchars(trim_dns_dot($z['name'])); ?>
                                    </a>
                                </td>
                                <td>
                                    <div style="display:inline-flex; align-items:center; justify-content:center; gap:6px;">
                                        <a href="index.php?module=powerdns-manager&tab=zones&manage_zone=<?php echo urlencode($z['id']); ?>&per_page=<?php echo intval($per_page); ?>" class="btn-action-edit" title="Manage Records"><i class="icon16 icomoon-icon-pencil"></i></a>
                                        <a href="index.php?module=powerdns-manager&tab=zones&export_single_zone=<?php echo urlencode($z['name']); ?>" class="btn-action-edit" style="background:#17a2b8 !important;" title="Export ZoneFile (.zone)"><i class="icon16 icomoon-icon-download"></i></a>
                                        <a href="index.php?module=powerdns-manager&tab=zones&delete_zone=<?php echo urlencode($z['id']); ?>" onclick="return confirm('Are you sure you want to delete this zone and all its records?');" class="btn-action-delete" title="Delete Zone"><i class="icon16 icomoon-icon-remove"></i></a>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr><td colspan="4" style="padding:15px; color:#888;">No DNS zones found matching criteria.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
            </form>
            <?php
            $zone_page_base = "index.php?module=powerdns-manager&tab=zones&sort_by=" . urlencode($sort_by) . "&sort_dir=" . urlencode($sort_dir) . "&per_page=" . intval($per_page) . (!empty($zone_filter) ? "&zone_filter=" . urlencode($zone_filter) : "") . "&p_zones=";
            echo render_table_footer(count($paginated_zones['items']), $paginated_zones['total_items'], $paginated_zones['page'], $paginated_zones['total_pages'], 'zones', $zone_page_base, $per_page, $allowed_per_page);
            ?>
        </div>
    </div>
</div>
<?php endif; ?>
<?php elseif ($tab === 'config'): ?>
<!-- Database SQL Backup & Restore Card -->
<div class="row" style="margin-bottom:25px;">
    <div class="col-md-12">
        <div class="pdns-card">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:12px; flex-wrap:wrap; gap:10px;">
                <div style="display:flex; align-items:center; gap:10px;">
                    <h4 style="margin:0; font-size:16px;">
                        <i class="icon16 icomoon-icon-database" style="color:#2f80ed; margin-right:5px;"></i>
                        <b>DNS Database Backup &amp; Restore</b>
                    </h4>
                    <span class="badge" style="background:#0066cc; color:#ffffff; font-size:12px; font-weight:bold; padding:4px 10px; border-radius:4px; display:inline-flex; align-items:center;">
                        <i class="icon16 icomoon-icon-database" style="color:#ffffff; margin-right:5px;"></i> <?php echo ($pdns_backend === 'pgsql') ? 'PostgreSQL' : 'MySQL'; ?>: <?php echo htmlspecialchars($db_name ?? 'powerdns'); ?>
                    </span>
                </div>
            </div>

            <div style="display:flex; align-items:center; gap:10px; flex-wrap:wrap;">
                <!-- SQL Backup / Restore -->
                <form method="POST" id="form-export-db-sql" style="margin:0;">
                    <input type="hidden" name="export_db_sql" value="1">
                    <button type="submit" class="btn btn-primary" style="background:#2f80ed !important; background-color:#2f80ed !important; color:#ffffff !important; border:none; padding:8px 16px; font-weight:bold; font-size:13px; border-radius:4px; cursor:pointer; display:inline-flex; align-items:center; gap:6px;">
                        <i class="icon16 icomoon-icon-download" style="color:#fff;"></i> Export SQL
                    </button>
                </form>
                <button type="button" onclick="document.getElementById('import_sql_file_input').click();" class="btn btn-primary" style="background:#2f80ed !important; background-color:#2f80ed !important; color:#ffffff !important; border:none; padding:8px 16px; font-weight:bold; font-size:13px; border-radius:4px; cursor:pointer; display:inline-flex; align-items:center; gap:6px;">
                    <i class="icon16 icomoon-icon-upload" style="color:#fff;"></i> Import SQL
                </button>

                <!-- Universal ZoneFile RFC 1035 Backup / Restore -->
                <form method="POST" id="form-export-all-zonefile" style="margin:0;">
                    <input type="hidden" name="export_all_zonefile" value="1">
                    <button type="submit" class="btn btn-info" style="background:#17a2b8 !important; background-color:#17a2b8 !important; color:#ffffff !important; border:none; padding:8px 16px; font-weight:bold; font-size:13px; border-radius:4px; cursor:pointer; display:inline-flex; align-items:center; gap:6px;" title="Export all DNS zones as standard RFC 1035 ZoneFile (Works across MySQL &amp; PostgreSQL)">
                        <i class="icon16 icomoon-icon-file" style="color:#fff;"></i> Export ZoneFile (.zone)
                    </button>
                </form>
                <button type="button" onclick="document.getElementById('import_zonefile_input').click();" class="btn btn-info" style="background:#17a2b8 !important; background-color:#17a2b8 !important; color:#ffffff !important; border:none; padding:8px 16px; font-weight:bold; font-size:13px; border-radius:4px; cursor:pointer; display:inline-flex; align-items:center; gap:6px;" title="Import standard RFC 1035 ZoneFile (.zone / .db) into PowerDNS (Works across MySQL &amp; PostgreSQL)">
                    <i class="icon16 icomoon-icon-upload" style="color:#fff;"></i> Import ZoneFile (.zone)
                </button>

                <!-- BIND Sync -->
                <button type="button" onclick="if(confirm('Scan /var/named and sync missing zones and records into PowerDNS?\n\nExisting records will NOT be overwritten.')){document.getElementById('form-import-bind').submit();}" class="btn btn-success" style="background:#28a745 !important; background-color:#28a745 !important; color:#ffffff !important; border:none; padding:8px 16px; font-weight:bold; font-size:13px; border-radius:4px; cursor:pointer; display:inline-flex; align-items:center; gap:6px;" title="Scan /var/named and sync missing zones and records without overwriting">
                    <i class="icon16 icomoon-icon-loop" style="color:#fff;"></i> BIND Sync
                </button>
            </div>

            <!-- Hidden Form for SQL Import -->
            <form method="POST" id="form-import-db-sql" enctype="multipart/form-data" style="display:none;">
                <input type="hidden" name="import_db_sql" value="1">
                <input type="hidden" name="restart_after_import" id="import_sql_restart_val" value="1">
                <input type="file" name="sql_file" id="import_sql_file_input" accept=".sql" onchange="handleSqlImport(this)">
            </form>

            <!-- Hidden Form for ZoneFile Import -->
            <form method="POST" id="form-import-zonefile" enctype="multipart/form-data" style="display:none;">
                <input type="hidden" name="import_zonefile" value="1">
                <input type="hidden" name="restart_after_import" id="import_zonefile_restart_val" value="1">
                <input type="file" name="zone_file" id="import_zonefile_input" accept=".zone,.db,.txt" onchange="handleZonefileImport(this)">
            </form>
        </div>
    </div>
</div>

<div class="row">
    <div class="col-md-12">
        <div class="pdns-card">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:15px; flex-wrap:wrap; gap:10px;">
                <div style="display:flex; align-items:center;">
                    <h4 style="margin:0; font-size:16px;">
                        <i class="icon16 icomoon-icon-cogs" style="color:#2f80ed; margin-right:5px;"></i>
                        <b>PowerDNS Configuration File (<code>/etc/pdns/pdns.conf</code>)</b>
                    </h4>
                </div>
                <div>
                    <span class="badge" style="background:#6c757d; color:#fff; padding:6px 10px; font-size:12px; border-radius:4px; font-weight:500;">
                        Auto-backups on save
                    </span>
                </div>
            </div>

            <form method="POST">
                <div class="form-group" style="margin-bottom:15px;">
                    <textarea name="config_content" id="pdns_config_textarea" class="form-control" rows="22" style="font-family:Consolas, Monaco, monospace; font-size:13px; width:100%; padding:12px; box-sizing:border-box; background:#1e1e1e; color:#d4d4d4; border:1px solid #333; border-radius:4px; line-height:1.5; min-height:480px; resize:vertical;"><?php echo htmlspecialchars($current_config_content); ?></textarea>
                </div>
                <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px; padding-top:12px; border-top:1px solid #eee;">
                    <label style="display:flex; align-items:center; gap:6px; cursor:pointer; font-weight:normal; margin:0;">
                        <input type="checkbox" name="restart_after_save" value="1" checked> <span style="margin: 10px 0 0 -10px">Restart after changes</span>
                    </label>
                    <button type="submit" name="save_config" class="btn btn-primary" style="background:#2f80ed !important; background-color:#2f80ed !important; color:#ffffff !important; border:none; padding:8px 22px; font-weight:bold; font-size:13px; border-radius:4px; cursor:pointer; display:inline-flex; align-items:center; gap:6px;">
                        <i class="icon16 icomoon-icon-disk" style="color:#fff;"></i> Save Configuration
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>
<script type="text/javascript">
function changePerPage(perPageVal) {
    var params = new URLSearchParams(window.location.search);
    params.set('per_page', perPageVal);
    params.delete('p_all');
    params.delete('p_records');
    params.delete('p_zones');
    params.delete('p_queries');
    window.location.href = 'index.php?' + params.toString();
}

function triggerFilter(tabName, paramName, filterVal, manageZone) {
    var params = new URLSearchParams(window.location.search);
    params.set('module', 'powerdns-manager');
    params.set('tab', tabName);
    params.set('per_page', '<?php echo intval($per_page); ?>');
    if (manageZone) {
        params.set('manage_zone', manageZone);
    }
    if (filterVal && filterVal.trim() !== '') {
        params.set(paramName, filterVal.trim());
    } else {
        params.delete(paramName);
    }
    params.delete('p_all');
    params.delete('p_records');
    params.delete('p_zones');
    params.delete('p_queries');
    window.location.href = 'index.php?' + params.toString();
}

function toggleSelectAll(master, targetName) {
    var checkboxes = document.getElementsByName(targetName);
    for (var i = 0; i < checkboxes.length; i++) {
        checkboxes[i].checked = master.checked;
    }
}

function confirmBulkAction(formId, targetName, actionType) {
    var form = document.getElementById(formId);
    if (!form) return false;
    var checkboxes = document.getElementsByName(targetName);
    var count = 0;
    for (var i = 0; i < checkboxes.length; i++) {
        if (checkboxes[i].checked) count++;
    }
    if (count === 0) {
        alert('Please select at least one item first.');
        return false;
    }

    if (actionType === 'delete') {
        if (!confirm('Are you sure you want to permanently delete ' + count + ' selected item(s)? This action cannot be undone.')) {
            return false;
        }
    }

    var input = form.querySelector('input[name="bulk_action"]');
    if (!input) {
        input = document.createElement('input');
        input.type = 'hidden';
        input.name = 'bulk_action';
        form.appendChild(input);
    }
    input.value = actionType;
    form.submit();
    return true;
}

function confirmClearAllQueries() {
    if (confirm("Are you sure you want to delete ALL DNS query logs from the database table?\n\nThis action cannot be undone.")) {
        var form = document.getElementById('form-clear-queries');
        if (form) form.submit();
    }
}

function handleSqlImport(input) {
    if (!input.files || input.files.length === 0) return;
    var file = input.files[0];
    var confirmed = confirm("Are you sure you want to import '" + file.name + "' into PowerDNS database?\n\nWARNING: Existing tables will be replaced by the SQL file contents.");
    if (!confirmed) {
        input.value = '';
        return;
    }

    var doRestart = confirm("Restart PowerDNS service after SQL import?");
    var restartInput = document.getElementById('import_sql_restart_val');
    if (restartInput) {
        restartInput.value = doRestart ? '1' : '0';
    }

    document.getElementById('form-import-db-sql').submit();
}

function handleZonefileImport(input) {
    if (!input.files || !input.files[0]) return;
    var file = input.files[0];
    var ext = file.name.split('.').pop().toLowerCase();
    if (['zone', 'db', 'txt'].indexOf(ext) === -1) {
        alert("Please select a valid RFC 1035 ZoneFile (.zone, .db, or .txt).");
        input.value = '';
        return;
    }

    var doRestart = confirm('Import ZoneFile "' + file.name + '" into PowerDNS?\n\nThis will parse RFC 1035 zone and records and import them into the active database. Existing records will not be overwritten.\n\nRestart PowerDNS service after import?');
    var restartInput = document.getElementById('import_zonefile_restart_val');
    if (restartInput) {
        restartInput.value = doRestart ? '1' : '0';
    }

    document.getElementById('form-import-zonefile').submit();
}

<?php if (!empty($exported_sql_data)): ?>
$(document).ready(function() {
    try {
        var sqlContent = atob("<?php echo $exported_sql_data['content']; ?>");
        var blob = new Blob([sqlContent], { type: 'application/sql;charset=utf-8' });
        var url = URL.createObjectURL(blob);
        var a = document.createElement('a');
        a.href = url;
        a.download = "<?php echo htmlspecialchars($exported_sql_data['filename']); ?>";
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
        URL.revokeObjectURL(url);
    } catch(e) {
        console.error('SQL export download error: ', e);
    }
});
<?php endif; ?>

<?php if (!empty($exported_zonefile_data)): ?>
$(document).ready(function() {
    try {
        var zoneContent = atob("<?php echo $exported_zonefile_data['content']; ?>");
        var blob = new Blob([zoneContent], { type: 'text/plain;charset=utf-8' });
        var url = URL.createObjectURL(blob);
        var a = document.createElement('a');
        a.href = url;
        a.download = "<?php echo htmlspecialchars($exported_zonefile_data['filename']); ?>";
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
        URL.revokeObjectURL(url);
    } catch(e) {
        console.error('ZoneFile export download error: ', e);
    }
});
<?php endif; ?>
</script>
