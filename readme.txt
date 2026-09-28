=== ProductShift – Backup & Migration for WooCommerce ===
Contributors: rejoyan9009
Tags: woocommerce, backup, restore, products, media
Requires at least: 6.0
Tested up to: 7.1
Stable tag: 1.8.1
Requires PHP: 7.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Backup, migrate, export and restore WooCommerce products, variations and media with portable ZIP archives and resumable recovery.

== Description ==

ProductShift – Backup & Migration for WooCommerce creates portable ZIP backups for WooCommerce product catalogs and WordPress media, then restores them in small resumable batches.

The plugin is designed for store owners who need to move products between WordPress sites, recover catalog data after a site problem, or keep downloadable backup copies of product and media data.

= Product backups include =

* Product name, slug, status and catalog visibility.
* Full and short descriptions.
* SKU, regular price and sale price.
* Sale dates, stock, backorders and stock status.
* Virtual and downloadable product settings.
* Weight and dimensions.
* Categories, subcategories and their original hierarchy, descriptions, ordering/meta and category thumbnails.
* Registered third-party product taxonomies (for example brand/vendor taxonomies) and their term hierarchy/meta when the same taxonomy exists on the destination site.
* Product attributes, global attribute definitions, attribute terms, term metadata and default attributes.
* Featured images, product galleries, variation images and Media Library images embedded in product descriptions.
* Variable product variations including ordering, sale dates, stock, tax, shipping class and downloadable data.
* Shipping classes, low-stock values, grouped-product children, external-product URL/button data, upsells and cross-sells.
* Local downloadable product/variation files are packaged into the archive and restored to the destination uploads directory.
* Portable public and private third-party custom product/variation metadata, while WooCommerce core fields are restored through CRUD APIs.
* Product menu order and creation/modified timestamps for closer catalog ordering fidelity.
* Registered third-party WooCommerce product types are restored on a best-effort basis when the same extension/product class is active on the destination site.

= Media backups include =

* Original Media Library files.
* Title, caption and description.
* ALT text.
* WordPress attachment metadata, attachment custom metadata, dates and original product-parent association when it can be remapped.

= Restore features =

* Dry-run restore preview before any store data changes.
* Create/update/skip impact counts and SKU conflict preview.
* Automatic rollback point before confirmed updates.
* Recovery Center with Undo Last Restore.
* Resumable AJAX restore jobs.
* Live percentage and stage progress.
* Restore media before products so image IDs can be remapped safely.
* SKU conflict modes: update, skip or create new.
* Per-item errors are captured instead of stopping the whole restore.
* Cancel stops the active restore flow and does not restart automatically.
* Successful restores show a completion summary and close automatically.
* Compatible with backup archives created by earlier Woo Product & Media Backup builds using the `wpmb` archive format.

= Backup management =

* Full product backup and incremental changed-product backup.
* Incremental baseline tracking.
* Optional WebDAV off-site backup copy through WP-Cron.
* Developer remote-storage action for custom S3, Google Drive or other adapters.
* Recent backup history in the WordPress admin.
* Download or delete saved server-side backup copies.
* Configurable retention count.
* Optional daily or weekly product backups using WP-Cron.
* System status checks for WordPress, PHP, WooCommerce, ZIP support, memory and disk space.

= Security =

Restore ZIP paths are validated before extraction. Symbolic links and unsafe relative paths are rejected. Restore actions require an authorized WordPress user and a valid nonce. New archives include SHA-256 checksums for JSON data and media records so corruption can be detected during restore.

ProductShift – Backup & Migration for WooCommerce does not include analytics, advertising or telemetry. Remote network requests occur only when an administrator explicitly enables Remote Storage. The built-in WebDAV option sends the backup ZIP to the administrator-configured WebDAV URL using the configured credentials. Remote Storage is disabled by default.

Important: Scheduled backups use WP-Cron. WP-Cron runs when WordPress receives traffic, so exact execution time can vary on low-traffic sites.

== Installation ==

1. Upload the `productshift-backup-migration` folder to `/wp-content/plugins/`, or install the ZIP from Plugins > Add New > Upload Plugin.
2. Activate ProductShift – Backup & Migration for WooCommerce.
3. Make sure WooCommerce is active for product backup and restore.
4. Open ProductShift – Backup & Migration for WooCommerce in the WordPress admin menu.
5. Create a small test backup and restore it on a staging site before using large production archives.

== Frequently Asked Questions ==

= Does it back up orders or customers? =

No. The plugin focuses on WooCommerce catalog data: products, variations, category/tag/attribute structures, related product media, local downloadable product files and the WordPress Media Library.

= Can I move a product backup to another WordPress site? =

Yes. The restore process remaps media and product IDs instead of assuming IDs will be identical on the destination site.

= What happens when a SKU already exists? =

You can update the existing product, skip it, or create a new product without reusing the conflicting SKU.

= Can a restore continue after a network error? =

Yes. Restore progress is saved between batches. If a request fails, use Resume Restore to continue the saved job.

= Are scheduled backups exact to the minute? =

No. They use WordPress WP-Cron and therefore depend on site traffic unless your hosting environment triggers wp-cron.php using a real server cron job.

= Where are saved backup copies stored? =

They are stored in a protected plugin backup directory under the WordPress uploads directory and are downloaded through an authenticated admin action. Server configuration can differ, so do not treat local hosting storage as your only disaster-recovery copy. Download important backups and keep an off-site copy.

= Does uninstall remove my backup ZIPs? =

Not by default. Enable "Delete plugin data on uninstall" in Settings if you want uninstall to remove saved backups and plugin settings.

= What does Dry Run Restore do? =

It validates the archive and calculates product create/update/skip counts before modifying the store. After reviewing the preview, confirm the restore to create a rollback point and begin the resumable restore.

= How does Incremental Backup work? =

The plugin records a successful product-backup baseline and includes only products modified after that point. The first incremental run acts as a baseline if none exists yet.

= Does Remote Storage contact a third party automatically? =

No. Remote Storage is disabled by default. If you enable WebDAV, the plugin sends backup ZIP files only to the WebDAV directory URL you enter. A developer hook adapter is also available for custom providers.

== Screenshots ==

1. Dashboard with product and media backup cards.
2. Resumable restore with live percentage and stage progress.
3. Backup history, schedule settings and system status.
4. Successful restore summary.

== Changelog ==

= 1.8.1 =
* Renamed the plugin to ProductShift – Backup & Migration for WooCommerce.
* Changed the requested WordPress.org slug and text domain to `productshift-backup-migration`.
* Preserved existing SBOP/WPMB backup archive compatibility and internal settings/history keys.

= 1.8.0 =
* Rebranded with the distinctive name ProductShift – Backup & Migration for WooCommerce and requested slug `productshift-backup-migration`.
* Removed the external Plugin URI so ownership is tied to the WordPress.org author account rather than an unrelated email-domain check.
* Removed inline script output and inline form event handlers; admin behavior now uses the enqueued JavaScript asset or safe server redirects.
* Fixed restore and backup cancellation authorization so ownership is verified before any job state is changed.

= 1.7.5 =
* Restored admin CSS/JS loading by using the exact WordPress screen hook suffixes returned when menus are registered.
* Updated in-plugin branding and shortened the WordPress admin menu label for a cleaner interface.

= 1.7.4 =
* Added WordPress.org contributor and author profile metadata for rejoyan9009.

= 1.7.1 =
* Renamed the plugin to ProductShift – Backup & Migration for WooCommerce.
* Changed the WordPress.org package slug/text domain to `productshift-backup-migration`.
* Preserved legacy SBOP settings, backup history and archive compatibility for existing users.
* Updated generated backup filenames to the new product branding.


= 1.7.0 =
* Prepared the package for WordPress.org submission with synchronized version/readme metadata.
* Declared WooCommerce through the WordPress `Requires Plugins` dependency header while retaining runtime compatibility notices.
* Revalidated canonical plugin folder, GPL licensing, text domain, uninstall behavior, resumable backup/restore flows and saved-backup downloads.

= 1.6.7 =
* Fixed saved-backup downloads being rejected with “This download authorization is no longer valid.”
* Fixed AJAX-created Download/Delete URLs so query parameters are no longer corrupted by HTML-escaped `&amp;` separators.
* Replaced salt/session-derived download signatures with a stable random key stored on each backup record.
* Kept WooCommerce management capability as the primary authorization and made stale browser nonces non-blocking for this read-only download action.
* Added realpath containment validation and a nosniff response header before streaming backup ZIP files.
= 1.6.5 =
* Reworked Confirm Restore so large rollback points are created in resumable AJAX batches instead of one long request.
* Added restore status recovery when an AJAX response is interrupted.
* Reduced restore batch sizes for shared-hosting reliability and added longer exponential retries.
* Added lightweight rollback archives that reuse existing local attachment IDs when undoing a restore.
* Added real recovery-point progress before media/product import begins.
* Same-site restores now reuse matching Media Library attachments by SHA-256 instead of creating unnecessary duplicates.


= 1.6.4 =
* Fixed saved-backup downloads that could show WordPress “The link you followed has expired.” even for valid backups.
* Download URLs now use a fresh WordPress nonce plus a user-bound signed token fallback while still requiring WooCommerce management capability.
* Kept destructive Delete actions on strict WordPress nonce verification.

= 1.6.3 =
* Fixed immediate resumable-backup start failures in admin-ajax by fully bootstrapping the WordPress filesystem API.
* Added backup storage preflight checks for uploads, temporary workspace, write and move permissions.
* Added explicit JSON errors for job workspace/file initialization failures instead of generic auto-fail behavior.
* Improved client-side start errors with HTTP status information when a host returns a non-JSON server error.

= 1.6.2 =
* Replaced long single-request manual backups with resumable AJAX batch jobs and real progress.
* Added automatic retry and reload-resume for interrupted backups.
* Optimized final archive persistence by moving completed ZIPs into protected storage before falling back to copy.
* Backup persistence reliability: success is shown only after the ZIP is verified, saved, and registered in Backup History.
* Product/Media/Incremental backups now appear immediately in Saved Backups and can be downloaded again.
* Removed the timer-based false-success state from the backup UI.
* Added high-fidelity product backups that always include featured, gallery, variation, category and embedded content images.
* Product categories now preserve hierarchy, names, slugs, descriptions, ordering/meta and category thumbnails across restore.
* Global attributes preserve their definitions, term metadata, term slugs and original attribute positions.
* Added portable local downloadable product and variation files with SHA-256 validation.
* Added external-product URL/button data, grouped-product child relationships, shipping classes, low-stock values, sale dates, variation ordering and registered third-party product taxonomy relationships.
* Product descriptions now remap restored Media Library URLs and wp-image IDs to the new attachment IDs.
* Expanded custom-meta portability to private third-party product/variation metadata while excluding WooCommerce core fields handled by CRUD.
* Product Backup now uses complete-fidelity mode so related media cannot be accidentally omitted.

= 1.5.0 =
* Added Dry Run Restore with create/update/skip counts, SKU conflict samples and archive warnings before any store changes.
* Added automatic rollback snapshots for products updated by a restore and a Recovery Center with Undo Last Restore.
* Added Incremental Backup with changed-product baseline tracking.
* Added optional background WebDAV remote copies and a developer storage adapter hook.
* Added recovery, incremental and remote-storage administration UI.
* Reworked restore confirmation so recovery points are created before data changes begin.

= 1.4.1 =
* Added animated green check mark when Product or Media backup finishes.
* Backup completion popup now closes automatically after a short success state.
* Manual Close remains available as a fallback.

= 1.4.0 =
* Redesigned the dashboard with a cleaner WordPress-native header navigation, consistent card system, readiness overview, recent backups/activity panels, responsive layout and footer navigation.
* Added contextual WordPress Help tabs on plugin administration screens.
* Kept the WordPress sidebar menu and color scheme untouched for admin consistency and accessibility.

= 1.3.1 =
* Fixed package folder identity so manual ZIP uploads replace the existing plugin instead of installing a duplicate.

= 1.3.0 =
* Added nonce-protected admin success notices to satisfy WordPress Plugin Check recommendations.

= 1.2.0 =
* WordPress.org Plugin Check hardening: WordPress filesystem APIs, secure upload handling, explicit AJAX nonce verification, sanitized restore inputs, and safer uninstall cleanup.
* Removed discouraged execution-time overrides while preserving resumable batch restore.
* Kept streaming ZIP downloads memory-efficient with SplFileObject.

= 1.1.0 =
* New administration center with operational KPIs and navigation.
* Added administrative activity log for backup, restore, schedule, delete, cancel and settings events.
* Added optional email notifications for scheduled backup and restore completion/failure.
* Added clearer WordPress admin success notices and enhanced settings management.

= 1.0.0 =
* Renamed plugin to ProductShift – Backup & Migration for WooCommerce.
* Added WordPress.org-ready plugin metadata, GPL license information and uninstall handling.
* Added saved backup history with download and delete actions.
* Added retention controls.
* Added daily and weekly scheduled product backups.
* Added system status panel.
* Increased default restore batch sizes for faster restores while keeping filter hooks available.
* Kept resumable restore, cancel protection, live progress and completion UI.
* Added compatibility with legacy `wpmb` backup archives.
* Added SHA-256 integrity checks for new archives.
* Hardened stored backup and restore path handling.

== Upgrade Notice ==

= 1.7.0 =
WordPress.org submission-ready release with WooCommerce dependency metadata and synchronized package versioning.

= 1.6.7 =
Fixes saved backup downloads that could be rejected because AJAX download URLs were HTML-escaped or their authorization became stale.

= 1.6.0 =
High-fidelity catalog backup/restore with category hierarchy, embedded media, attributes, local downloads and richer product relationships.

= 1.0.0 =
First WordPress.org-ready release under the ProductShift – Backup & Migration for WooCommerce name. Existing `wpmb` backup ZIP files remain restorable.
