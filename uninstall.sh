#!/usr/bin/env bash
# ==============================================================================
# https://github.com/QadirAdamson/CWP-Module-PowerDNS-Manager
# ==============================================================================

set -e

RED='\033[0;31m'
GREEN='\033[0;32m'
BLUE='\033[0;34m'
YELLOW='\033[1;33m'
NC='\033[0m'

echo -e "${YELLOW}Uninstalling CWP PowerDNS Management Module...${NC}"

# Check root privilege
if [ "$EUID" -ne 0 ]; then
  echo -e "${RED}[ERROR] This script must be run as root.${NC}"
  exit 1
fi

# 1. Remove module files
rm -f /usr/local/cwpsrv/htdocs/resources/admin/modules/powerdns-manager.php
rm -f /usr/local/cwpsrv/htdocs/resources/admin/include/powerdns_menu.php

# 2. Remove menu injection from 3rdparty.php
THIRDPARTY_FILE="/usr/local/cwpsrv/htdocs/resources/admin/include/3rdparty.php"
if [ -f "$THIRDPARTY_FILE" ]; then
    sed -i '/powerdns_menu.php/d' "$THIRDPARTY_FILE"
fi

echo -e "${GREEN}[OK] Module files and menu references removed successfully.${NC}"

# 3. Stop and disable PowerDNS and Query Logger services
if systemctl is-active --quiet pdns-query-logger 2>/dev/null || systemctl is-enabled --quiet pdns-query-logger 2>/dev/null; then
    echo -e "${BLUE}Stopping and disabling Query Logger service...${NC}"
    systemctl stop pdns-query-logger || true
    systemctl disable pdns-query-logger || true
    rm -f /etc/systemd/system/pdns-query-logger.service
    rm -f /usr/local/bin/pdns-query-logger.py
fi

if systemctl is-active --quiet pdns 2>/dev/null || systemctl is-enabled --quiet pdns 2>/dev/null; then
    echo -e "${BLUE}Stopping and disabling PowerDNS service...${NC}"
    systemctl stop pdns || true
    systemctl disable pdns || true
    echo -e "${GREEN}[OK] PowerDNS service stopped and disabled.${NC}"
fi

# 4. Automatically re-activate and start BIND (named / bind9)
echo -e "${BLUE}Re-activating BIND (named) service...${NC}"
systemctl daemon-reload

if systemctl list-unit-files named.service >/dev/null 2>&1 || [ -f /usr/lib/systemd/system/named.service ] || [ -f /etc/systemd/system/named.service ]; then
    systemctl enable named || true
    systemctl restart named || true
    if systemctl is-active --quiet named; then
        echo -e "${GREEN}[OK] BIND (named) has been successfully restored and is running on port 53.${NC}"
    else
        echo -e "${YELLOW}[WARNING] BIND enabled, but failed to start. Please check 'systemctl status named'.${NC}"
    fi
elif systemctl list-unit-files bind9.service >/dev/null 2>&1 || [ -f /usr/lib/systemd/system/bind9.service ] || [ -f /etc/systemd/system/bind9.service ]; then
    systemctl enable bind9 || true
    systemctl restart bind9 || true
    if systemctl is-active --quiet bind9; then
        echo -e "${GREEN}[OK] BIND (bind9) has been successfully restored and is running on port 53.${NC}"
    else
        echo -e "${YELLOW}[WARNING] BIND enabled, but failed to start. Please check 'systemctl status bind9'.${NC}"
    fi
else
    echo -e "${YELLOW}[NOTE] BIND service unit not found on this system.${NC}"
fi

echo -e "\n${GREEN}======================================================${NC}"
echo -e "${GREEN}  Uninstall Complete & BIND Restored!                 ${NC}"
echo -e "${GREEN}======================================================${NC}\n"
