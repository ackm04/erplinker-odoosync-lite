# ERP Linker OdooSync Lite

Essential WooCommerce ↔ Odoo 18/19 sync for products, customers, orders, and stock.

**License:** GPL v2 or later · **Product site:** https://ackm04.github.io/erplinker-odoosync-website/

---

## ☕ Support this project

OdooSync Lite is free and open source. Tips and sponsorships fund development, docs, and support.

<p>
  <a href="https://www.buymeacoffee.com/ackm04">
    <img src="https://img.shields.io/badge/Buy_me_a_coffee-FFDD00?style=for-the-badge&logo=buy-me-a-coffee&logoColor=black" alt="Buy Me a Coffee">
  </a>
  <a href="https://github.com/sponsors/ackm04">
    <img src="https://img.shields.io/badge/Sponsor-%E2%9D%A4-ea4aaa?style=for-the-badge&logo=github-sponsors&logoColor=white" alt="Sponsor on GitHub">
  </a>
  <a href="https://ko-fi.com/ackm04">
    <img src="https://img.shields.io/badge/Ko--fi-F16061?style=for-the-badge&logo=ko-fi&logoColor=white" alt="Ko-fi">
  </a>
  <a href="https://paypal.me/officialajayindia">
    <img src="https://img.shields.io/badge/PayPal-00457C?style=for-the-badge&logo=paypal&logoColor=white" alt="PayPal">
  </a>
</p>

### Sponsor perk: 24-hour OdooSync Pro evaluation

Sponsor **$19 USD or more** via any link above and receive a **24-hour OdooSync Pro evaluation license** — real-time sync, webhooks, field mapping, analytics, and every Pro feature.

1. Sponsor **$19+** (GitHub Sponsors, Buy Me a Coffee, Ko-fi, or PayPal).
2. Email **ackm04@gmail.com** with your payment reference, GitHub username, and the site you want to test.
3. Get Pro access within **24 hours** of verification.

Evaluation licenses are for testing before a commercial Pro purchase from [ERP Linker](https://erplinker.com/downloads/woocommerce-odoo-integration-odoosync/).

---

## Features

- Product sync (WooCommerce ↔ Odoo)
- Customer sync (WooCommerce → Odoo)
- Order sync (WooCommerce → Odoo)
- Stock sync (Odoo → WooCommerce)
- Scheduled sync via WP-Cron
- HPOS compatible
- GDPR-ready (WordPress Privacy API)
- Encrypted credential storage

## Requirements

- WordPress 5.8+
- WooCommerce 6.0+
- PHP 8.0+
- Odoo 18 or 19 with XML-RPC enabled

## Installation

### From GitHub

```bash
cd wp-content/plugins
git clone https://github.com/ackm04/erplinker-odoosync-lite.git
wp plugin activate erplinker-odoosync-lite
```

Or download a [release ZIP](https://github.com/ackm04/erplinker-odoosync-lite/releases) and upload it via **Plugins → Add New → Upload Plugin**.

### Configuration

1. Go to **WooCommerce → OdooSync Lite → Settings**
2. Enter your Odoo URL, database, username, and password/API key
3. Click **Test Connection**
4. Enable the sync options you need and run a manual sync

## Upgrade to Pro

Need real-time webhooks, field mapping, refunds, multi-warehouse, analytics, and more?

Visit [ERP Linker OdooSync Pro](https://erplinker.com/downloads/woocommerce-odoo-integration-odoosync/) or [sponsor $19+ for a 24-hour Pro evaluation](#-support-this-project).

## Development

This repository contains the **Lite** edition only. The Pro plugin is maintained in a separate private repository.

```bash
# Validate PHP syntax (optional)
find . -name '*.php' -not -path './vendor/*' -print0 | xargs -0 -n1 php -l
```

## Support

- Product site: https://ackm04.github.io/erplinker-odoosync-website/
- Sponsor / Pro evaluation: https://ackm04.github.io/erplinker-odoosync-website/#support
- Documentation: https://erplinker.com/docs/odoosync-lite
- Issues: https://github.com/ackm04/erplinker-odoosync-lite/issues
- GitHub: https://github.com/ackm04

---

Made with 💛 by **Ajay** · [☕ Coffee](https://www.buymeacoffee.com/ackm04) · [❤️ Sponsor](https://github.com/sponsors/ackm04) · [Ko-fi](https://ko-fi.com/ackm04) · [PayPal](https://paypal.me/officialajayindia)
