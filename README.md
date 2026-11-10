# PowerDNS Module for Control Web Panel (CWP)

PowerDNS management module for [Control Web Panel (CWP)](https://control-webpanel.com/).

---

## Features

- PowerDNS installation and service management
- Create, list, search, and delete DNS zones
- Add, edit, and delete DNS records
- Import existing DNS zones from BIND
- Support for MySQL and PostgreSQL database backends
- Database and zone file backup, export, and import
- Edit configuration file

---

## Installation

Run on your CWP server as `root`:

```bash
curl -fsSL https://raw.githubusercontent.com/QadirAdamson/CWP-Module-PowerDNS-Manager/main/install.sh | bash
```

Access in CWP Admin Panel: **DNS Functions → PowerDNS Manager**

---

## Uninstallation

Run on your CWP server as `root`:

```bash
curl -fsSL https://raw.githubusercontent.com/QadirAdamson/CWP-Module-PowerDNS-Manager/main/uninstall.sh | bash
```

---

## License

MIT License
