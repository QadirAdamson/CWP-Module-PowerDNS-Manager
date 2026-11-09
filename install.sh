#!/usr/bin/env bash
# ==============================================================================
# CWP PowerDNS Management Module Installer
# Compatible with CentOS 7/8/9 Stream, AlmaLinux 8/9/10, Rocky Linux & RHEL
# Control Web Panel (CWP / CWP7)
# Idempotent & Safe for Re-installation
# ==============================================================================

set -e

RED='\033[0;31m'
GREEN='\033[0;32m'
BLUE='\033[0;34m'
YELLOW='\033[1;33m'
NC='\033[0m'

echo -e "${BLUE}======================================================${NC}"
echo -e "${BLUE}  CWP PowerDNS Management Module Installer            ${NC}"
echo -e "${BLUE}======================================================${NC}"

# Check root privilege
if [ "$EUID" -ne 0 ]; then
  echo -e "${RED}[ERROR] This script must be run as root.${NC}"
  exit 1
fi

# Detect CWP installation
CWP_DIR="/usr/local/cwpsrv/htdocs/resources/admin"
if [ ! -d "$CWP_DIR" ]; then
  echo -e "${RED}[ERROR] Control Web Panel directory not found at $CWP_DIR${NC}"
  exit 1
fi

# 1. Disable BIND / named to avoid port 53 conflict
echo -e "\n${YELLOW}[1/8] Disabling BIND (named) service to release port 53...${NC}"
if systemctl is-active --quiet named || systemctl is-enabled --quiet named 2>/dev/null; then
    echo -e "${BLUE}Stopping and disabling 'named' service...${NC}"
    systemctl stop named || true
    systemctl disable named || true
    echo -e "${GREEN}[OK] BIND (named) has been stopped and disabled.${NC}"
elif systemctl is-active --quiet bind9 || systemctl is-enabled --quiet bind9 2>/dev/null; then
    echo -e "${BLUE}Stopping and disabling 'bind9' service...${NC}"
    systemctl stop bind9 || true
    systemctl disable bind9 || true
    echo -e "${GREEN}[OK] BIND (bind9) has been stopped and disabled.${NC}"
else
    echo -e "${GREEN}[OK] BIND service is not running or already disabled.${NC}"
fi

# 2. Install EPEL and PowerDNS with MySQL Backend
echo -e "\n${YELLOW}[2/8] Installing PowerDNS and MySQL backend packages...${NC}"
if command -v dnf >/dev/null 2>&1; then
    dnf -y install epel-release || true
    dnf -y install pdns pdns-backend-mysql
elif command -v yum >/dev/null 2>&1; then
    yum -y install epel-release || true
    yum -y install pdns pdns-backend-mysql
else
    echo -e "${RED}[ERROR] Neither dnf nor yum package manager found.${NC}"
    exit 1
fi

# 3. Database Setup (Preserves Existing Credentials on Re-install)
echo -e "\n${YELLOW}[3/8] Configuring PowerDNS database in MariaDB/MySQL...${NC}"

DB_NAME="powerdns"
DB_USER="pdns_user"

# Check if /etc/pdns/pdns.conf already exists (re-install scenario)
EXISTING_PASS=""
EXISTING_API=""
if [ -f /etc/pdns/pdns.conf ]; then
    EXISTING_PASS=$(grep -E '^[[:space:]]*gmysql-password=' /etc/pdns/pdns.conf | head -n 1 | cut -d'=' -f2- | tr -d ' "\r\n')
    EXISTING_API=$(grep -E '^[[:space:]]*api-key=' /etc/pdns/pdns.conf | head -n 1 | cut -d'=' -f2- | tr -d ' "\r\n')
fi

if [ -n "$EXISTING_PASS" ]; then
    echo -e "${BLUE}Existing PowerDNS database configuration found. Preserving credentials...${NC}"
    DB_PASS="$EXISTING_PASS"
else
    DB_PASS=$(openssl rand -base64 16 | tr -dc 'a-zA-Z0-9' | head -c 16)
fi

if [ -n "$EXISTING_API" ]; then
    API_KEY="$EXISTING_API"
else
    API_KEY=$(openssl rand -base64 24 | tr -dc 'a-zA-Z0-9' | head -c 24)
fi

# Execute idempotent MySQL commands (creates db/user if missing, updates password & grants cleanly)
mysql -e "CREATE DATABASE IF NOT EXISTS \`${DB_NAME}\` CHARACTER SET utf8 COLLATE utf8_general_ci;"
for HOST in '127.0.0.1' 'localhost'; do
    mysql -e "CREATE USER IF NOT EXISTS '${DB_USER}'@'${HOST}' IDENTIFIED BY '${DB_PASS}';"
    mysql -e "ALTER USER '${DB_USER}'@'${HOST}' IDENTIFIED BY '${DB_PASS}';"
    mysql -e "GRANT ALL PRIVILEGES ON \`${DB_NAME}\`.* TO '${DB_USER}'@'${HOST}';"
done
mysql -e "FLUSH PRIVILEGES;"

# Import schema (Tables use IF NOT EXISTS with inline indexes to prevent duplicate index errors)
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
if [ -f "$SCRIPT_DIR/sql/schema.mysql.sql" ]; then
    mysql "$DB_NAME" < "$SCRIPT_DIR/sql/schema.mysql.sql"
else
    # Download schema directly if standalone curl-to-bash run
    curl -fsSL https://raw.githubusercontent.com/QadirAdamson/CWP-Module-PowerDNS-Manager/main/sql/schema.mysql.sql | mysql "$DB_NAME" || true
fi

# 4. Configure /etc/pdns/pdns.conf
echo -e "\n${YELLOW}[4/8] Setting up /etc/pdns/pdns.conf...${NC}"
mkdir -p /etc/pdns

if [ -f /etc/pdns/pdns.conf ] && [ ! -f /etc/pdns/pdns.conf.orig ]; then
    cp /etc/pdns/pdns.conf /etc/pdns/pdns.conf.orig
fi

cat <<EOF > /etc/pdns/pdns.conf
# PowerDNS Configuration for Control Web Panel (CWP)
setuid=pdns
setgid=pdns

launch=gmysql
gmysql-host=127.0.0.1
gmysql-port=3306
gmysql-user=${DB_USER}
gmysql-password=${DB_PASS}
gmysql-dbname=${DB_NAME}
gmysql-dnssec=yes

# Webserver & REST API
webserver=yes
webserver-address=127.0.0.1
webserver-port=8053
webserver-allow-from=127.0.0.1,::1
api=yes
api-key=${API_KEY}

# DNS Settings & Real-time Analytics
local-port=53
default-ttl=300
query-cache-ttl=30
cache-ttl=30
log-dns-details=no
log-dns-queries=no
loglevel=2
EOF

chmod 600 /etc/pdns/pdns.conf
chown pdns:pdns /etc/pdns/pdns.conf || true

# 5. Copy Module Files to CWP (Overwrites existing cleanly)
echo -e "\n${YELLOW}[5/8] Installing Module files into CWP...${NC}"
mkdir -p "$CWP_DIR/modules"
mkdir -p "$CWP_DIR/include"

if [ -f "$SCRIPT_DIR/upload/admin/modules/powerdns-manager.php" ]; then
    cp -f "$SCRIPT_DIR/upload/admin/modules/powerdns-manager.php" "$CWP_DIR/modules/powerdns-manager.php"
    cp -f "$SCRIPT_DIR/upload/admin/include/powerdns_menu.php" "$CWP_DIR/include/powerdns_menu.php"
else
    curl -fsSL https://raw.githubusercontent.com/QadirAdamson/CWP-Module-PowerDNS-Manager/main/upload/admin/modules/powerdns-manager.php -o "$CWP_DIR/modules/powerdns-manager.php"
    curl -fsSL https://raw.githubusercontent.com/QadirAdamson/CWP-Module-PowerDNS-Manager/main/upload/admin/include/powerdns_menu.php -o "$CWP_DIR/include/powerdns_menu.php"
fi

chmod 644 "$CWP_DIR/modules/powerdns-manager.php"
chmod 644 "$CWP_DIR/include/powerdns_menu.php"

# 6. Inject Menu into CWP Sidebar (3rdparty.php)
echo -e "\n${YELLOW}[6/8] Injecting PowerDNS item into CWP sidebar...${NC}"
MENU_INJECT="include_once('/usr/local/cwpsrv/htdocs/resources/admin/include/powerdns_menu.php');"
THIRDPARTY_FILE="$CWP_DIR/include/3rdparty.php"

if [ -f "$THIRDPARTY_FILE" ]; then
    if ! grep -q "powerdns_menu.php" "$THIRDPARTY_FILE"; then
        echo -e "\n<?php $MENU_INJECT ?>" >> "$THIRDPARTY_FILE"
        echo -e "${GREEN}[OK] Injected menu inclusion into 3rdparty.php${NC}"
    else
        echo -e "${GREEN}[OK] Menu injection already present in 3rdparty.php${NC}"
    fi
else
    echo -e "<?php\n$MENU_INJECT\n?>" > "$THIRDPARTY_FILE"
    chmod 644 "$THIRDPARTY_FILE"
    echo -e "${GREEN}[OK] Created 3rdparty.php with menu injection${NC}"
fi

# 7. Setup Background Query Logger (Batch Writes to MySQL)
echo -e "\n${YELLOW}[7/8] Configuring background DNS query logger service...${NC}"
cat <<'PYEOF' > /usr/local/bin/pdns-query-logger.py
#!/usr/bin/env python3
import socket
import struct
import time
import datetime
import signal
import sys
import os

TYPE_MAP = {
    1: "A", 2: "NS", 5: "CNAME", 6: "SOA", 12: "PTR", 15: "MX", 16: "TXT", 28: "AAAA", 33: "SRV", 65: "HTTPS", 255: "ANY"
}
RCODE_MAP = {
    0: "NOERROR", 1: "FORMERR", 2: "SERVFAIL", 3: "NXDOMAIN", 4: "NOTIMP", 5: "REFUSED", 9: "NOTAUTH"
}

def parse_config(cfg_file="/etc/pdns/pdns.conf"):
    cfg = {}
    if os.path.exists(cfg_file):
        with open(cfg_file, "r") as f:
            for line in f:
                line = line.strip()
                if line and not line.startswith("#") and "=" in line:
                    k, v = line.split("=", 1)
                    cfg[k.strip()] = v.strip()
    return cfg

def parse_dns(payload):
    if len(payload) < 12: return None
    txid, flags, qdcount = struct.unpack("!HHH", payload[:6])
    is_response = (flags & 0x8000) != 0
    rcode_num = flags & 0x000F
    rcode = RCODE_MAP.get(rcode_num, f"RCODE{rcode_num}")
    rd = (flags & 0x0100) != 0
    if qdcount < 1: return None
    
    idx = 12
    domain_parts = []
    while idx < len(payload):
        length = payload[idx]
        if length == 0:
            idx += 1
            break
        idx += 1
        domain_parts.append(payload[idx:idx+length].decode("utf-8", errors="replace"))
        idx += length
    
    domain = ".".join(domain_parts)
    qtype = "A"
    if idx + 2 <= len(payload):
        qtype_num = struct.unpack("!H", payload[idx:idx+2])[0]
        qtype = TYPE_MAP.get(qtype_num, f"TYPE{qtype_num}")
    return {"txid": txid, "is_response": is_response, "rcode": rcode, "rd": rd, "domain": domain, "type": qtype}

running = True
def sig_handler(sig, frame):
    global running
    running = False

signal.signal(signal.SIGINT, sig_handler)
signal.signal(signal.SIGTERM, sig_handler)

cfg = parse_config()
db_host = cfg.get("gmysql-host", "127.0.0.1")
db_user = cfg.get("gmysql-user", "pdns_user")
db_pass = cfg.get("gmysql-password", "")
db_name = cfg.get("gmysql-dbname", "powerdns")
db_port = int(cfg.get("gmysql-port", 3306))

try:
    import pymysql
    def get_db():
        return pymysql.connect(host=db_host, user=db_user, password=db_pass, database=db_name, port=db_port, autocommit=True)
except ImportError:
    import subprocess
    def insert_batch_cli(batch):
        if not batch: return
        vals = []
        for r in batch:
            c = r["client"].replace("'", "")
            d = r["domain"].replace("'", "")
            t = r["type"].replace("'", "")
            rc = r["rcode"].replace("'", "")
            pr = r["proto"].replace("'", "")
            sz = int(r["size"])
            flg = r["flags"].replace("'", "")
            tm = r["time"]
            vals.append(f"('{c}', '{d}', '{t}', '{rc}', '{pr}', {sz}, '{flg}', '{tm}')")
        sql = "INSERT INTO dnsqueries (client, domain, type, response, proto, size, flags, time) VALUES " + ",".join(vals) + ";"
        cmd = ["mysql", "-h", db_host, "-P", str(db_port), f"-u{db_user}", f"-p{db_pass}", db_name, "-e", sql]
        subprocess.run(cmd, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
    get_db = None

try:
    sock = socket.socket(socket.AF_PACKET, socket.SOCK_RAW, socket.ntohs(0x0800))
    is_raw_packet = True
except Exception:
    sock = socket.socket(socket.AF_INET, socket.SOCK_RAW, socket.IPPROTO_UDP)
    is_raw_packet = False
sock.settimeout(1.0)

pending_queries = {}
batch = []
last_flush = time.time()
BATCH_MAX = 200
FLUSH_INTERVAL = 10.0

zones_cache = {}
last_cache_update = 0

def refresh_zones_cache(c=None):
    global zones_cache, last_cache_update
    now = time.time()
    if now - last_cache_update < 60 and zones_cache:
        return
    try:
        new_cache = {}
        if c:
            with c.cursor() as cur:
                cur.execute("SELECT id, name FROM domains")
                doms = cur.fetchall()
                cur.execute("SELECT domain_id, name, type FROM records WHERE disabled = 0")
                recs = cur.fetchall()
                for d in doms:
                    d_id, d_name = d[0], d[1].lower().rstrip('.')
                    new_cache[d_name] = {'id': d_id, 'records': set(), 'names': set()}
                for r in recs:
                    r_did, r_name, r_type = r[0], r[1].lower().rstrip('.'), r[2].upper()
                    for z_name, z_val in new_cache.items():
                        if z_val['id'] == r_did:
                            z_val['records'].add((r_name, r_type))
                            z_val['names'].add(r_name)
                            break
        else:
            # Fallback to MySQL CLI query
            cmd_d = ["mysql", "-h", db_host, "-P", str(db_port), f"-u{db_user}", f"-p{db_pass}", db_name, "-N", "-e", "SELECT id, name FROM domains;"]
            res_d = subprocess.run(cmd_d, stdout=subprocess.PIPE, stderr=subprocess.DEVNULL, text=True)
            for line in res_d.stdout.splitlines():
                parts = line.strip().split("\t")
                if len(parts) >= 2:
                    d_id, d_name = int(parts[0]), parts[1].lower().rstrip('.')
                    new_cache[d_name] = {'id': d_id, 'records': set(), 'names': set()}
            
            cmd_r = ["mysql", "-h", db_host, "-P", str(db_port), f"-u{db_user}", f"-p{db_pass}", db_name, "-N", "-e", "SELECT domain_id, name, type FROM records WHERE disabled = 0;"]
            res_r = subprocess.run(cmd_r, stdout=subprocess.PIPE, stderr=subprocess.DEVNULL, text=True)
            for line in res_r.stdout.splitlines():
                parts = line.strip().split("\t")
                if len(parts) >= 3:
                    r_did, r_name, r_type = int(parts[0]), parts[1].lower().rstrip('.'), parts[2].upper()
                    for z_name, z_val in new_cache.items():
                        if z_val['id'] == r_did:
                            z_val['records'].add((r_name, r_type))
                            z_val['names'].add(r_name)
                            break
        if new_cache:
            zones_cache = new_cache
            last_cache_update = now
    except Exception:
        pass

def is_domain_in_hosted_zones(domain):
    d = domain.lower().rstrip('.')
    parts = d.split('.')
    for i in range(len(parts)):
        sub = ".".join(parts[i:])
        if sub in zones_cache:
            return True
    return False

def eval_authoritative_response(domain, qtype):
    d = domain.lower().rstrip('.')
    parts = d.split('.')
    matched_zone = None
    for i in range(len(parts)):
        sub = ".".join(parts[i:])
        if sub in zones_cache:
            matched_zone = sub
            break
    if not matched_zone:
        return None
    z_data = zones_cache[matched_zone]
    if (d, qtype) in z_data['records'] or (d, 'CNAME') in z_data['records']:
        return "NOERROR"
    if d in z_data['names']:
        return "NOERROR"
    return "NXDOMAIN"

conn = None
if get_db:
    try: 
        conn = get_db()
        refresh_zones_cache(conn)
    except Exception: 
        conn = None

while running:
    try:
        pkt, addr = sock.recvfrom(4096)
        if is_raw_packet:
            if len(pkt) < 34: continue
            ip_header_len = (pkt[14] & 0x0F) * 4
            proto_num = pkt[23]
            src_ip = socket.inet_ntoa(pkt[26:30])
            dst_ip = socket.inet_ntoa(pkt[30:34])
            transport_offset = 14 + ip_header_len
        else:
            ip_header_len = (pkt[0] & 0x0F) * 4
            proto_num = pkt[9]
            src_ip = socket.inet_ntoa(pkt[12:16])
            dst_ip = socket.inet_ntoa(pkt[16:20])
            transport_offset = ip_header_len

        if proto_num == 17: # UDP
            udp_header = pkt[transport_offset:transport_offset+8]
            if len(udp_header) < 8: continue
            src_port, dst_port, udp_len, udp_ck = struct.unpack("!HHHH", udp_header)
            dns_payload = pkt[transport_offset+8:transport_offset+udp_len]
            proto_name = "UDP"
        elif proto_num == 6: # TCP
            tcp_header = pkt[transport_offset:transport_offset+20]
            if len(tcp_header) < 20: continue
            src_port, dst_port = struct.unpack("!HH", tcp_header[:4])
            tcp_data_offset = ((tcp_header[12] >> 4) & 0x0F) * 4
            tcp_payload = pkt[transport_offset+tcp_data_offset:]
            if len(tcp_payload) <= 2: continue
            dns_payload = tcp_payload[2:] # Strip 2-byte TCP length prefix
            proto_name = "TCP"
        else:
            continue

        if dst_port == 53 or src_port == 53:
            parsed = parse_dns(dns_payload)
            if parsed:
                now_ts = time.time()
                now_str = datetime.datetime.now().strftime("%Y-%m-%d %H:%M:%S")
                size_bytes = len(dns_payload)
                txid = parsed["txid"]

                if not parsed["is_response"] and dst_port == 53:
                    # Ignore queries for foreign domains not hosted on this server
                    refresh_zones_cache(conn)
                    if not is_domain_in_hosted_zones(parsed["domain"]):
                        continue

                    # Incoming DNS query
                    key = (src_ip, src_port, txid)
                    flags_str = "RD" if parsed["rd"] else ""
                    pending_queries[key] = {
                        "client": src_ip,
                        "domain": parsed["domain"],
                        "type": parsed["type"],
                        "proto": proto_name,
                        "size": size_bytes,
                        "flags": flags_str,
                        "time": now_str,
                        "ts": now_ts
                    }
                elif parsed["is_response"] and src_port == 53:
                    # Outgoing DNS response matching query
                    key = (dst_ip, dst_port, txid)
                    q = pending_queries.pop(key, None)
                    if q:
                        batch.append({
                            "client": q["client"],
                            "domain": q["domain"],
                            "type": q["type"],
                            "rcode": parsed["rcode"],
                            "proto": q["proto"],
                            "size": q["size"],
                            "flags": q["flags"],
                            "time": q["time"]
                        })
                    else:
                        if is_domain_in_hosted_zones(parsed["domain"]):
                            flags_str = "RD" if parsed["rd"] else ""
                            batch.append({
                                "client": dst_ip,
                                "domain": parsed["domain"],
                                "type": parsed["type"],
                                "rcode": parsed["rcode"],
                                "proto": proto_name,
                                "size": size_bytes,
                                "flags": flags_str,
                                "time": now_str
                            })
    except socket.timeout:
        pass
    except Exception:
        pass

    now = time.time()
    # Expire unmatched queries older than 2.0 seconds with authoritative evaluation
    if len(pending_queries) > 0:
        expired_keys = [k for k, v in pending_queries.items() if now - v["ts"] > 2.0]
        if expired_keys:
            refresh_zones_cache(conn)
        for k in expired_keys:
            q = pending_queries.pop(k)
            evaluated_response = eval_authoritative_response(q["domain"], q["type"])
            if evaluated_response is not None:
                batch.append({
                    "client": q["client"],
                    "domain": q["domain"],
                    "type": q["type"],
                    "rcode": evaluated_response,
                    "proto": q["proto"],
                    "size": q["size"],
                    "flags": q["flags"],
                    "time": q["time"]
                })

    if len(batch) >= BATCH_MAX or (batch and (now - last_flush) >= FLUSH_INTERVAL):
        to_save = batch
        batch = []
        last_flush = now
        
        if conn:
            vals = [(r["client"], r["domain"], r["type"], r["rcode"], r["proto"], r["size"], r["flags"], r["time"]) for r in to_save]
            sql_insert = "INSERT INTO dnsqueries (client, domain, type, response, proto, size, flags, time) VALUES (%s, %s, %s, %s, %s, %s, %s, %s)"
            for attempt in range(2):
                try:
                    with conn.cursor() as cur:
                        cur.executemany(sql_insert, vals)
                    break
                except Exception:
                    if attempt == 0:
                        try:
                            conn = get_db()
                        except Exception:
                            break
        else:
            insert_batch_cli(to_save)

sock.close()
if conn:
    try: conn.close()
    except: pass
PYEOF

chmod 755 /usr/local/bin/pdns-query-logger.py

cat <<'UNITEOF' > /etc/systemd/system/pdns-query-logger.service
[Unit]
Description=PowerDNS Batch Query Logger to MySQL
After=network.target pdns.service mysql.service mariadb.service

[Service]
Type=simple
ExecStart=/usr/bin/python3 /usr/local/bin/pdns-query-logger.py
Restart=always
RestartSec=3

[Install]
WantedBy=multi-user.target
UNITEOF

# 8. Start & Enable Services
echo -e "\n${YELLOW}[8/8] Starting PowerDNS and Query Logger services...${NC}"
systemctl daemon-reload
systemctl enable pdns
systemctl enable pdns-query-logger
systemctl restart pdns
systemctl restart pdns-query-logger

echo -e "\n${GREEN}======================================================${NC}"
echo -e "${GREEN}  PowerDNS Management Module Installed Successfully! ${NC}"
echo -e "${GREEN}======================================================${NC}"
echo -e "API Port       : 8053"
echo -e "API Key        : ${API_KEY}"
echo -e "Database User  : ${DB_USER}"
echo -e "Database Name  : ${DB_NAME}"
echo -e "DNS Status     : PowerDNS is active on port 53 (BIND/named disabled)"
echo -e "\nYou can now refresh your Control Web Panel (CWP) admin dashboard"
echo -e "and access ${BLUE}PowerDNS Manager${NC} directly from the left sidebar!"
echo -e "Direct URL: ${YELLOW}https://<your-cwp-ip>:2031/index.php?module=powerdns-manager${NC}\n"
