# PowerDNS Management Module for Control Web Panel (CWP)

A high-performance, native PowerDNS Authoritative Server management module designed for [Control Web Panel (CWP)](https://control-webpanel.com/).

---

## ✨ Key Features

- **Zone Management**: Create, list, search, filter, and delete forward and reverse DNS zones.
- **Record Editor**: Manage A, AAAA, CNAME, MX, TXT, SRV, NS, CAA, PTR, and SOA records with inline comments and bulk status controls.
- **BIND 9 Migration**: Import missing zones and records from `/var/named` without overwriting existing data.
- **Multi-Backend Support**: Run on either MySQL or PostgreSQL database backends.
- **SQL Backup & Restore**: One-click full database SQL export and upload import.
- **RFC 1035 ZoneFile Support**: Database-agnostic `.zone` export and import for seamless migrations between MySQL and PostgreSQL.
- **Query Logging Daemon**: Real-time DNS query logging capturing client IPs, query types, response codes, and protocols.
- **Configuration Editor**: In-browser `/etc/pdns/pdns.conf` editor with automated backups and service reload.
- **High-Availability Fallback**: Direct database queries when the PowerDNS daemon is stopped.

---

## 🚀 Installation

Run on your CWP server as `root`:

```bash
curl -fsSL https://raw.githubusercontent.com/QadirAdamson/CWP-Module-PowerDNS-Manager/main/install.sh | bash
```

Access in CWP Admin Panel: 👉 **DNS Functions → PowerDNS Manager**

---

## 🗑️ Uninstallation

Run on your CWP server as `root`:

```bash
curl -fsSL https://raw.githubusercontent.com/QadirAdamson/CWP-Module-PowerDNS-Manager/main/uninstall.sh | bash
```

*(Automatically restores and re-enables BIND `named` service on port 53).*

---

## 📄 License

This project is open-source and licensed under the [MIT License](LICENSE).
