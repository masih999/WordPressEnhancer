=== Energy Analytics ===
Contributors: energyanalytics
Tags: energy, analytics, reports, dashboard, acf, calculations
Requires at least: 5.8
Tested up to: 6.3
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html

Enterprise-grade energy-usage reporting suite with ACF integration, automated calculations, and comprehensive analytics.

== Description ==

Energy Analytics delivers an enterprise-grade energy-usage reporting suite for WordPress, enabling organizations to track, analyze, and report on energy consumption data with ease.

### Core Features

* **Advanced Custom Fields Integration** - Synchronize ACF field groups with a single click or via WP-CLI
* **Energy Reports** - Track and filter energy usage data with WP_List_Table and CSV/PDF exports
* **Interactive Analytics Dashboard** - Visualize energy data with Chart.js (line, bar, pie charts)
* **Custom Calculation Scripts** - Create and manage custom JavaScript calculations for energy data
* **Enterprise-Grade Performance** - Optimized with transient caching and proper database indexing
* **Role-Based Access Control** - Custom user roles and fine-grained capabilities
* **Security Focused** - Nonces, sanitization, CSP headers, and rate limiting
* **PDF Export** - Generate professional reports with Dompdf

### Technical Features

* PSR-4 autoloading with Composer
* Clean uninstallation with data retention options
* WP-CLI integration for automation
* Database migration support
* Comprehensive developer documentation

== Installation ==

1. Upload the `energy-analytics` folder to the `/wp-content/plugins/` directory
2. Activate the plugin through the 'Plugins' menu in WordPress
3. Go to the 'Energy Analytics' menu to configure settings
4. Set up ACF fields and begin tracking energy data

For best results, run `composer install` in the plugin directory to install required dependencies.

== Frequently Asked Questions ==

= Does this plugin require Advanced Custom Fields? =

Yes, Energy Analytics is designed to work with Advanced Custom Fields (ACF) for data collection. The free version of ACF is sufficient, but ACF Pro provides additional flexibility.

= Can I export my energy reports? =

Yes, Energy Analytics supports exporting to CSV, PDF, and JSON formats, both from the admin interface and via WP-CLI commands.

= Is the plugin GDPR compliant? =

Energy Analytics stores only the data you collect. As long as your data collection adheres to GDPR requirements, the plugin handles data with appropriate security and privacy controls.

= How can I integrate with other systems? =

The plugin provides a REST API that can be used to fetch energy statistics. Additionally, the data can be exported in various formats for use in other systems.

== Screenshots ==

1. Energy Reports overview
2. Analytics Dashboard with interactive charts
3. Custom Script editor
4. Settings page
5. PDF export example

== Changelog ==

= 1.0.0 =
* Initial release

== Upgrade Notice ==

= 1.0.0 =
Initial release of Energy Analytics.

== Documentation ==

### ACF Field Synchronization

Energy Analytics can import ACF field definitions from a JSON file, either through the admin interface or using WP-CLI:

`wp energy sync-fields --file=/path/to/acf-fields.json`

### Custom Calculation Scripts

Create custom JavaScript calculations for your energy forms using the built-in code editor. Scripts can target specific forms or run globally.

### WP-CLI Commands

Energy Analytics includes WP-CLI integration for automation:

* `wp energy sync-fields` - Synchronize ACF fields from JSON file
* `wp energy export --format=pdf` - Export energy data to PDF
* `wp energy export --format=csv --period=month` - Export monthly data to CSV

### Developer Hooks

**Filters:**

* `ea_chart_colors` - Modify default chart colors
* `ea_export_filename` - Customize export filenames
* `ea_dashboard_stats` - Filter dashboard statistics before display

**Actions:**

* `ea_before_export_pdf` - Fires before generating a PDF export
* `ea_after_save_energy_data` - Fires after energy data is saved to the database
* `ea_sync_acf_fields_complete` - Fires after ACF field synchronization is complete
