# PowerDNS Management Module for Control Web Panel (CWP)

A native PowerDNS Authoritative Server management module designed for [Control Web Panel (CWP)](https://control-webpanel.com/).

---

## ✨ Features

- **🌐 DNS Zone Management**: Create, view, edit, and delete forward and reverse zones (Native & Master).
- **📝 Record Management**: Manage `A`, `AAAA`, `CNAME`, `MX`, `TXT`, `SRV`, `NS`, `CAA`, `PTR`, and `SOA` records with record comments.
- **📊 Real-time DNS Analytics**: Live monitoring and query logging with domain, type, response code, client IP, protocol, and packet size.
- **⚙️ In-Browser Config Editor**: Direct editor for `/etc/pdns/pdns.conf` with automatic syntax backup and instant service restart.
- **🛡️ Fallback Support**: Directly queries MySQL backend when PowerDNS service is stopped, ensuring records remain accessible.

---

## 🚀 Installation

Run on your CWP server as `root`:

```bash
curl -fsSL https://raw.githubusercontent.com/QadirAdamson/CWP-Module-PowerDNS-Manager/main/install.sh | bash
```

Access in CWP Admin Panel: 👉 **DNS Functions → PowerDNS Manager**

---

## 🗑️ Uninstallation

```bash
curl -fsSL https://raw.githubusercontent.com/QadirAdamson/CWP-Module-PowerDNS-Manager/main/uninstall.sh | bash
```

---

## 📄 License

This project is open-source and licensed under the [MIT License](LICENSE).
