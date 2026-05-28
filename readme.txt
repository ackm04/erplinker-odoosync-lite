=== ERP Linker OdooSync Lite ===
Contributors:      erplinker
Donate link:       https://www.buymeacoffee.com/ackm04
Tags:              woocommerce, odoo, sync, erp, integration
Requires at least: 5.8
Tested up to:      6.9
Stable tag:        3.2.0
Requires PHP:      8.0
WC requires at least: 6.0
WC tested up to:   10.6
License:           GPLv2 or later
License URI:       https://www.gnu.org/licenses/gpl-2.0.html

The essential bridge between WooCommerce and Odoo. Sync products, customers, orders, and stock.

== Description ==

OdooSync Lite provides essential synchronization between WooCommerce and Odoo 18/19.
Perfect for small businesses getting started with Odoo integration.

= Lite Features =

* Product sync from Odoo to WooCommerce
* Customer sync from WooCommerce to Odoo
* Order sync from WooCommerce to Odoo
* Stock level sync from Odoo to WooCommerce
* Scheduled sync via WP-Cron
* HPOS compatible (WooCommerce High-Performance Order Storage)
* GDPR ready with Privacy API integration
* Encrypted credential storage
* Simple, clean dashboard

= Upgrade to Pro =

Need more power? OdooSync Pro includes:

* Real-time bidirectional sync with webhooks
* Advanced field mapping with 15+ transformations
* Batch processing with progress tracking
* Analytics dashboard with health scoring
* Refund and credit note sync
* Shipping carrier mapping with tracking
* Tax rate sync
* Product bundle/kit (BOM) sync
* Payment method mapping
* Email digest reports
* Multi-warehouse support
* MRP/Manufacturing sync
* 9+ integrations (ShipStation, Stripe, Mailchimp, etc.)
* WP-CLI support
* GraphQL API
* Priority support

= Requirements =

* WordPress 5.8+
* WooCommerce 6.0+
* PHP 8.0+
* Odoo 18 or 19 (Community or Enterprise)

== Installation ==

1. Upload the `erplinker-odoosync-lite` folder to `/wp-content/plugins/`
2. Activate the plugin through **Plugins > Installed Plugins**
3. Navigate to **OdooSync Lite > Settings**
4. Enter your Odoo URL, database, username, and password
5. Enable the sync options you need
6. Save changes

== Frequently Asked Questions ==

= Does this require a custom Odoo module? =

No. The plugin uses Odoo's standard XML-RPC API.

= Which Odoo versions are supported? =

Odoo 18 and Odoo 19, both Community and Enterprise editions.

= Is this HPOS-compatible? =

Yes. Fully compatible with WooCommerce High-Performance Order Storage.

= How do I upgrade to Pro? =

Visit OdooSync Lite > Upgrade to Pro in your WordPress admin for upgrade details.

== Screenshots ==

1. Dashboard with quick stats and sync status
2. Settings page — Odoo connection configuration
3. Upgrade page showing Pro features
4. Product sync log view
5. Stock sync progress and results
6. GDPR privacy data export integration

== Changelog ==

= 3.2.0 =
* Sync with Pro version numbering
* Improved admin interface
* Better error handling
* HPOS compatibility improvements

= 1.0.0 =
* Initial release
* Basic product, customer, order, and stock sync
* Scheduled sync via WP-Cron
* HPOS compatible
* GDPR ready

== Upgrade Notice ==

= 3.2.0 =
Version sync with OdooSync Pro. Improved admin interface and error handling.
