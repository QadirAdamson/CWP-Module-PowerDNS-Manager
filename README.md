# PowerDNS Management Module for Control Web Panel (CWP)

A native PowerDNS management module designed for [Control Web Panel (CWP)](https://control-webpanel.com/).

---

## ✨ Features

- **DNS Zone Management**: Create, list, search, and delete forward and reverse DNS zones.
- **Record Management**: Add, edit, and manage all standard DNS records with bulk controls.
- **BIND Migration**: Easily import existing zones and records from BIND without overwriting data.
- **Flexible Database Backends**: Supports both MySQL and PostgreSQL database backends.
- **Database Backup & Restore**: Export and import full database backups with a single click.
- **Universal Zone Import/Export**: Export and import standard DNS zone files to easily migrate between databases.
- **Real-Time DNS Analytics**: Track incoming DNS queries, query types, and client statistics.
- **Configuration Editor**: Edit the PowerDNS configuration file with automatic backups and service reload.
- **Service Management**: Start, stop, and restart PowerDNS service directly from the panel.

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

---

## 📄 License

This project is open-source and licensed under the [MIT License](LICENSE).
