<?php
/**
 * Plugin Name: ProductShift – Backup & Migration for WooCommerce
 * Description: Back up, migrate, export and restore product catalogs, variations and media for WooCommerce using portable ZIP archives.
 * Version: 1.8.1
 * Author: rejoyan9009
 * Author URI: https://profiles.wordpress.org/rejoyan9009/
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Requires Plugins: woocommerce
 * WC requires at least: 7.0
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: productshift-backup-migration
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class SBOP_Backup_Plugin {
    const VERSION = '1.8.1';
    const MENU_SLUG = 'sbop-backup';
    private $admin_view = 'dashboard';
    private $admin_screen_hooks = array();

    public static function activate() {
        $schedule = (string) get_option( 'sbop_schedule', 'off' );
        if ( 'off' !== $schedule && ! wp_next_scheduled( 'sbop_scheduled_backup' ) ) {
            wp_schedule_event( time() + 5 * MINUTE_IN_SECONDS, $schedule, 'sbop_scheduled_backup' );
        }
    }

    public static function deactivate() {
        wp_clear_scheduled_hook( 'sbop_scheduled_backup' );
        wp_clear_scheduled_hook( 'sbop_remote_upload' );
    }

    public function __construct() {
        add_action( 'admin_menu', array( $this, 'admin_menu' ) );
        add_action( 'admin_notices', array( $this, 'dependency_notice' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'admin_assets' ) );
        add_action( 'before_woocommerce_init', array( $this, 'declare_wc_compatibility' ) );
        add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), array( $this, 'plugin_action_links' ) );
        add_action( 'admin_post_sbop_export_products', array( $this, 'export_products' ) );
        add_action( 'admin_post_sbop_export_media', array( $this, 'export_media' ) );
        add_action( 'admin_post_sbop_export_incremental', array( $this, 'export_incremental' ) );
        add_action( 'admin_post_sbop_rollback_last_restore', array( $this, 'rollback_last_restore' ) );
        add_action( 'admin_post_sbop_reset_incremental', array( $this, 'reset_incremental_baseline' ) );
        add_action( 'admin_post_sbop_download_backup', array( $this, 'download_backup' ) );
        add_action( 'admin_post_sbop_delete_backup', array( $this, 'delete_backup' ) );
        add_action( 'admin_post_sbop_save_settings', array( $this, 'save_settings' ) );
        add_action( 'sbop_scheduled_backup', array( $this, 'run_scheduled_backup' ) );
        add_action( 'sbop_remote_upload', array( $this, 'remote_upload_backup' ), 10, 1 );
        add_filter( 'cron_schedules', array( $this, 'cron_schedules' ) );
        add_action( 'wp_ajax_sbop_backup_start', array( $this, 'ajax_backup_start' ) );
        add_action( 'wp_ajax_sbop_backup_step', array( $this, 'ajax_backup_step' ) );
        add_action( 'wp_ajax_sbop_backup_cancel', array( $this, 'ajax_backup_cancel' ) );
        add_action( 'wp_ajax_sbop_restore_start', array( $this, 'ajax_restore_start' ) );
        add_action( 'wp_ajax_sbop_restore_confirm', array( $this, 'ajax_restore_confirm' ) );
        add_action( 'wp_ajax_sbop_restore_status', array( $this, 'ajax_restore_status' ) );
        add_action( 'wp_ajax_sbop_restore_step', array( $this, 'ajax_restore_step' ) );
        add_action( 'wp_ajax_sbop_restore_cancel', array( $this, 'ajax_restore_cancel' ) );
    }

    public function declare_wc_compatibility() {
        if ( class_exists( '\\Automattic\\WooCommerce\\Utilities\\FeaturesUtil' ) ) {
            \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
        }
    }

    public function plugin_action_links( $links ) {
        array_unshift( $links, '<a href="' . esc_url( admin_url( 'admin.php?page=' . self::MENU_SLUG ) ) . '">' . esc_html__( 'Open Product Catalog Backup', 'productshift-backup-migration' ) . '</a>' );
        return $links;
    }

    public function admin_menu() {
        $screen_hooks = array();
        $screen_hooks[] = add_menu_page(
            __( 'ProductShift – Backup & Migration for WooCommerce', 'productshift-backup-migration' ),
            __( 'ProductShift', 'productshift-backup-migration' ),
            'manage_woocommerce',
            self::MENU_SLUG,
            array( $this, 'render_dashboard_page' ),
            'dashicons-database-export',
            56
        );

        $screen_hooks[] = add_submenu_page( self::MENU_SLUG, __( 'Dashboard', 'productshift-backup-migration' ), __( 'Dashboard', 'productshift-backup-migration' ), 'manage_woocommerce', self::MENU_SLUG, array( $this, 'render_dashboard_page' ) );
        $screen_hooks[] = add_submenu_page( self::MENU_SLUG, __( 'Backup', 'productshift-backup-migration' ), __( 'Backup', 'productshift-backup-migration' ), 'manage_woocommerce', 'sbop-backup-create', array( $this, 'render_backup_page' ) );
        $screen_hooks[] = add_submenu_page( self::MENU_SLUG, __( 'Restore', 'productshift-backup-migration' ), __( 'Restore', 'productshift-backup-migration' ), 'manage_woocommerce', 'sbop-backup-restore', array( $this, 'render_restore_page' ) );
        $screen_hooks[] = add_submenu_page( self::MENU_SLUG, __( 'Recovery', 'productshift-backup-migration' ), __( 'Recovery', 'productshift-backup-migration' ), 'manage_woocommerce', 'sbop-backup-recovery', array( $this, 'render_recovery_page' ) );
        $screen_hooks[] = add_submenu_page( self::MENU_SLUG, __( 'Backup History', 'productshift-backup-migration' ), __( 'Backup History', 'productshift-backup-migration' ), 'manage_woocommerce', 'sbop-backup-history', array( $this, 'render_history_page' ) );
        $screen_hooks[] = add_submenu_page( self::MENU_SLUG, __( 'Automation', 'productshift-backup-migration' ), __( 'Automation', 'productshift-backup-migration' ), 'manage_woocommerce', 'sbop-backup-automation', array( $this, 'render_automation_page' ) );
        $screen_hooks[] = add_submenu_page( self::MENU_SLUG, __( 'Activity Logs', 'productshift-backup-migration' ), __( 'Activity Logs', 'productshift-backup-migration' ), 'manage_woocommerce', 'sbop-backup-activity', array( $this, 'render_activity_page' ) );
        $screen_hooks[] = add_submenu_page( self::MENU_SLUG, __( 'System Status', 'productshift-backup-migration' ), __( 'System Status', 'productshift-backup-migration' ), 'manage_woocommerce', 'sbop-backup-system', array( $this, 'render_system_page' ) );
        $screen_hooks[] = add_submenu_page( self::MENU_SLUG, __( 'Settings', 'productshift-backup-migration' ), __( 'Settings', 'productshift-backup-migration' ), 'manage_woocommerce', 'sbop-backup-settings', array( $this, 'render_settings_page' ) );

        $this->admin_screen_hooks = array_values( array_filter( $screen_hooks ) );
        foreach ( $this->admin_screen_hooks as $screen_hook ) {
            add_action( 'load-' . $screen_hook, array( $this, 'add_screen_help' ) );
        }
    }

    public function add_screen_help() {
        $screen = get_current_screen();
        if ( ! $screen ) {
            return;
        }
        $screen->add_help_tab( array(
            'id'      => 'sbop-getting-started',
            'title'   => __( 'Getting Started', 'productshift-backup-migration' ),
            'content' => '<p>' . esc_html__( 'Create a small product backup first, download a copy off-site, and test restore on a staging site before restoring a large production archive.', 'productshift-backup-migration' ) . '</p>',
        ) );
        $screen->add_help_tab( array(
            'id'      => 'sbop-restore-safety',
            'title'   => __( 'Restore Safety', 'productshift-backup-migration' ),
            'content' => '<p>' . esc_html__( 'Restore jobs run in resumable batches. If a request is interrupted, use Resume Restore instead of starting the same job again.', 'productshift-backup-migration' ) . '</p>',
        ) );
        $screen->set_help_sidebar( '<p><strong>' . esc_html__( 'ProductShift – Backup & Migration for WooCommerce', 'productshift-backup-migration' ) . '</strong></p><p>' . esc_html__( 'Use System Status to review server limits and compatibility before large backup or restore jobs.', 'productshift-backup-migration' ) . '</p>' );
    }

    private function render_view( $view ) {
        $this->admin_view = $view;
        $this->render_admin_page();
    }

    public function render_dashboard_page() { $this->render_view( 'dashboard' ); }
    public function render_backup_page() { $this->render_view( 'backup' ); }
    public function render_restore_page() { $this->render_view( 'restore' ); }
    public function render_recovery_page() { $this->render_view( 'recovery' ); }
    public function render_history_page() { $this->render_view( 'history' ); }
    public function render_automation_page() { $this->render_view( 'automation' ); }
    public function render_activity_page() { $this->render_view( 'activity' ); }
    public function render_system_page() { $this->render_view( 'system' ); }
    public function render_settings_page() { $this->render_view( 'settings' ); }

    public function dependency_notice() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            return;
        }
        if ( ! class_exists( 'WooCommerce' ) ) {
            echo '<div class="notice notice-error"><p><strong>ProductShift – Backup & Migration for WooCommerce:</strong> WooCommerce must be active for product backup and restore.</p></div>';
        }
        if ( ! class_exists( 'ZipArchive' ) ) {
            echo '<div class="notice notice-error"><p><strong>ProductShift – Backup & Migration for WooCommerce:</strong> PHP ZipArchive is required. Ask your hosting provider to enable the PHP zip extension.</p></div>';
        }
    }

    public function admin_assets( $hook ) {
        // WordPress returns the authoritative hook suffix from add_menu_page()/
        // add_submenu_page(). Using those exact values keeps assets loading even
        // when the visible menu title or plugin branding changes.
        if ( ! in_array( $hook, $this->admin_screen_hooks, true ) ) {
            return;
        }
        wp_enqueue_style( 'sbop-admin', plugin_dir_url( __FILE__ ) . 'assets/admin.css', array(), self::VERSION );
        wp_enqueue_script( 'sbop-admin', plugin_dir_url( __FILE__ ) . 'assets/admin.js', array( 'jquery' ), self::VERSION, true );
        $active_job = (string) get_user_meta( get_current_user_id(), '_sbop_active_restore_job', true );
        if ( $active_job && ! file_exists( $this->job_state_path( $active_job ) ) ) {
            delete_user_meta( get_current_user_id(), '_sbop_active_restore_job' );
            $active_job = '';
        }
        $active_job_data = array();
        if ( $active_job ) {
            $active_state = $this->load_job_state( $active_job );
            if ( $active_state ) {
                $active_job_data = $this->job_payload( $active_state );
            }
        }
        $active_backup_job = (string) get_user_meta( get_current_user_id(), '_sbop_active_backup_job', true );
        $active_backup_data = array();
        if ( $active_backup_job ) {
            $active_backup_state = $this->load_backup_job_state( $active_backup_job );
            if ( ! $active_backup_state ) {
                delete_user_meta( get_current_user_id(), '_sbop_active_backup_job' );
                $active_backup_job = '';
            } else {
                $active_backup_data = $this->backup_job_payload( $active_backup_state );
            }
        }
        wp_localize_script( 'sbop-admin', 'SBOP', array(
            'ajaxUrl'       => admin_url( 'admin-ajax.php' ),
            'nonce'         => wp_create_nonce( 'sbop_restore_ajax' ),
            'backupNonce'   => wp_create_nonce( 'sbop_backup_ajax' ),
            'activeBackupJob' => $active_backup_job,
            'activeBackupData' => $active_backup_data,
            'activeJob'     => $active_job,
            'activeJobData' => $active_job_data,
            'maxUploadBytes' => (int) wp_max_upload_size(),
            'strings'       => array(
                'starting'   => 'Uploading, validating and analyzing backup…',
                'working'    => 'Restore is running. You can keep this page open.',
                'complete'   => 'Restore completed.',
                'failed'     => 'The restore request failed. You can retry/resume without starting over.',
                'chooseFile' => 'Please choose a backup ZIP file.',
                'cancelled'  => 'Restore job cancelled.',
                'preview'    => 'Dry run complete. Review the impact before restoring.',
            ),
        ) );
    }

    private function can_run() {
        return current_user_can( 'manage_woocommerce' ) && class_exists( 'ZipArchive' );
    }

    public function render_admin_page() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( esc_html__( 'You do not have permission to access this page.', 'productshift-backup-migration' ) );
        }

        $product_count = 0;
        if ( class_exists( 'WooCommerce' ) ) {
            $counts = wp_count_posts( 'product' );
            foreach ( array( 'publish', 'draft', 'pending', 'private' ) as $status ) {
                if ( isset( $counts->{$status} ) ) {
                    $product_count += (int) $counts->{$status};
                }
            }
        }
        $media_count = 0;
        $media_counts = wp_count_attachments();
        if ( $media_counts ) {
            foreach ( (array) $media_counts as $count ) {
                $media_count += (int) $count;
            }
        }
        $upload_limit_mb = max( 1, (int) floor( wp_max_upload_size() / 1024 / 1024 ) );
        $all_history = $this->backup_history();
        $history = array_slice( $all_history, 0, 8 );
        $activity = array_slice( $this->activity_log(), 0, 12 );
        $saved_storage = 0;
        foreach ( $all_history as $entry ) { $saved_storage += (int) ( $entry['size'] ?? 0 ); }
        $last_backup = ! empty( $all_history[0]['created_at'] ) ? (int) $all_history[0]['created_at'] : 0;
        $schedule = (string) get_option( 'sbop_schedule', 'off' );
        $retention = max( 1, min( 25, (int) get_option( 'sbop_retention', 5 ) ) );
        $scheduled_limit = max( 1, min( 5000, (int) get_option( 'sbop_scheduled_limit', 5000 ) ) );
        $delete_on_uninstall = (bool) get_option( 'sbop_delete_data_on_uninstall', false );
        $email_notifications = (bool) get_option( 'sbop_email_notifications', false );
        $notification_email = sanitize_email( (string) get_option( 'sbop_notification_email', get_option( 'admin_email' ) ) );
        $incremental_anchor = (int) get_option( 'sbop_incremental_anchor', 0 );
        $last_restore_rollback = get_option( 'sbop_last_restore_rollback', array() );
        $last_restore_rollback = is_array( $last_restore_rollback ) ? $last_restore_rollback : array();
        $protected_rollback_id = ! empty( $last_restore_rollback['rollback_backup_id'] ) ? (string) $last_restore_rollback['rollback_backup_id'] : '';
        $remote_enabled = (bool) get_option( 'sbop_remote_enabled', false );
        $remote_provider = sanitize_key( (string) get_option( 'sbop_remote_provider', 'webdav' ) );
        $remote_url = esc_url_raw( (string) get_option( 'sbop_remote_url', '' ) );
        $remote_user = sanitize_text_field( (string) get_option( 'sbop_remote_user', '' ) );
        $remote_last_status = sanitize_text_field( (string) get_option( 'sbop_remote_last_status', '' ) );
        $next_schedule = wp_next_scheduled( 'sbop_scheduled_backup' );
        $last_schedule = (int) get_option( 'sbop_last_schedule_run', 0 );
        $last_schedule_error = (string) get_option( 'sbop_last_schedule_error', '' );
        $memory_limit = ini_get( 'memory_limit' );
        $disk_free = function_exists( 'disk_free_space' ) ? @disk_free_space( WP_CONTENT_DIR ) : false;
        $uploads_info = wp_upload_dir();
        $uploads_writable = empty( $uploads_info['error'] ) && wp_is_writable( $uploads_info['basedir'] );
        $backup_is_recent = $last_backup && ( time() - $last_backup <= WEEK_IN_SECONDS );
        $readiness_checks = array(
            class_exists( 'WooCommerce' ),
            class_exists( 'ZipArchive' ),
            $uploads_writable,
            (bool) $backup_is_recent,
        );
        $readiness_passed = count( array_filter( $readiness_checks ) );
        $readiness_percent = (int) round( ( $readiness_passed / count( $readiness_checks ) ) * 100 );
        $protection_label = ! $last_backup ? __( 'Needs first backup', 'productshift-backup-migration' ) : ( $backup_is_recent ? __( 'Protected', 'productshift-backup-migration' ) : __( 'Backup due', 'productshift-backup-migration' ) );
        $recent_history = array_slice( $all_history, 0, 3 );
        $recent_activity = array_slice( $activity, 0, 5 );
        ?>
        <div class="wrap sbop-wrap">
            <iframe name="sbop-download-frame" id="sbop-download-frame" class="sbop-hidden-frame" aria-hidden="true"></iframe>

            <header class="sbop-topbar sbop-app-header">
                <div class="sbop-brand">
                    <div class="sbop-logo" aria-hidden="true"><span class="dashicons dashicons-database-export"></span></div>
                    <div>
                        <h1>ProductShift <span>Backup & Migration</span></h1>
                        <p>Import, export, backup and migrate WooCommerce products and media.</p>
                    </div>
                </div>
                <div class="sbop-header-meta">
                    <span class="sbop-health"><span class="sbop-health-dot"></span><?php echo esc_html( $protection_label ); ?></span>
                    <span class="sbop-version">v<?php echo esc_html( self::VERSION ); ?></span>
                </div>
            </header>

            <?php
            $sbop_notice_nonce_valid = isset( $_GET['_wpnonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'sbop_admin_notice' );
            $sbop_settings_saved     = $sbop_notice_nonce_valid && isset( $_GET['sbop_settings_saved'] );
            $sbop_deleted            = $sbop_notice_nonce_valid && isset( $_GET['sbop_deleted'] );
            ?>
            <?php if ( $sbop_settings_saved ) : ?><div class="notice notice-success is-dismissible"><p><strong>ProductShift – Backup & Migration for WooCommerce:</strong> Settings saved.</p></div><?php endif; ?>
            <?php if ( $sbop_deleted ) : ?><div class="notice notice-success is-dismissible"><p><strong>ProductShift – Backup & Migration for WooCommerce:</strong> Backup deleted.</p></div><?php endif; ?>

            <?php
            $view_titles = array(
                'dashboard'  => array( 'Dashboard', 'Store protection overview and quick actions.' ),
                'backup'     => array( 'Create Backup', 'Create portable product or media backup archives.' ),
                'restore'    => array( 'Restore / Import', 'Analyze a backup first, then restore it in resumable batches.' ),
                'recovery'   => array( 'Recovery Center', 'Manage rollback points, incremental baselines and recovery actions.' ),
                'history'    => array( 'Backup History', 'Download, review and remove saved backup archives.' ),
                'automation' => array( 'Automation', 'Schedule recurring backups and manage retention.' ),
                'activity'   => array( 'Activity Logs', 'Review recent backup, restore and configuration events.' ),
                'system'     => array( 'System Status', 'Check compatibility, limits and restore readiness.' ),
                'settings'   => array( 'Settings', 'Manage notifications and plugin data preferences.' ),
            );
            $view_title = isset( $view_titles[ $this->admin_view ] ) ? $view_titles[ $this->admin_view ] : $view_titles['dashboard'];
            $header_tabs = array(
                'dashboard'  => array( self::MENU_SLUG, __( 'Dashboard', 'productshift-backup-migration' ) ),
                'backup'     => array( 'sbop-backup-create', __( 'Backup', 'productshift-backup-migration' ) ),
                'restore'    => array( 'sbop-backup-restore', __( 'Restore', 'productshift-backup-migration' ) ),
                'recovery'   => array( 'sbop-backup-recovery', __( 'Recovery', 'productshift-backup-migration' ) ),
                'history'    => array( 'sbop-backup-history', __( 'History', 'productshift-backup-migration' ) ),
                'automation' => array( 'sbop-backup-automation', __( 'Automation', 'productshift-backup-migration' ) ),
                'activity'   => array( 'sbop-backup-activity', __( 'Activity', 'productshift-backup-migration' ) ),
                'system'     => array( 'sbop-backup-system', __( 'System', 'productshift-backup-migration' ) ),
                'settings'   => array( 'sbop-backup-settings', __( 'Settings', 'productshift-backup-migration' ) ),
            );
            ?>

            <nav class="nav-tab-wrapper wp-clearfix sbop-header-nav" aria-label="<?php esc_attr_e( 'ProductShift navigation', 'productshift-backup-migration' ); ?>">
                <?php foreach ( $header_tabs as $tab_view => $tab ) : ?>
                    <a class="nav-tab <?php echo $this->admin_view === $tab_view ? 'nav-tab-active' : ''; ?>" href="<?php echo esc_url( admin_url( 'admin.php?page=' . $tab[0] ) ); ?>"><?php echo esc_html( $tab[1] ); ?></a>
                <?php endforeach; ?>
            </nav>

            <div class="sbop-page-heading sbop-native-heading">
                <div>
                    <h1 class="wp-heading-inline"><?php echo esc_html( $view_title[0] ); ?></h1>
                    <p><?php echo esc_html( $view_title[1] ); ?></p>
                </div>
                <?php if ( 'dashboard' !== $this->admin_view ) : ?><a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::MENU_SLUG ) ); ?>"><span class="dashicons dashicons-arrow-left-alt2"></span> <?php esc_html_e( 'Dashboard', 'productshift-backup-migration' ); ?></a><?php endif; ?>
            </div>
            <hr class="wp-header-end sbop-header-end">

            <?php if ( 'dashboard' === $this->admin_view ) : ?>
            <section class="sbop-dashboard-hero">
                <div class="sbop-dashboard-hero-copy">
                    <span class="sbop-kicker">BACKUP CONTROL CENTER</span>
                    <h2><?php echo esc_html( $protection_label ); ?></h2>
                    <p>Keep your WooCommerce catalog portable, verify restore readiness, and see the most important backup signals without opening every screen.</p>
                    <div class="sbop-dashboard-actions">
                        <a class="button button-primary" href="<?php echo esc_url( admin_url( 'admin.php?page=sbop-backup-create' ) ); ?>"><span class="dashicons dashicons-download"></span> <?php esc_html_e( 'Create Backup', 'productshift-backup-migration' ); ?></a>
                        <a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=sbop-backup-restore' ) ); ?>"><span class="dashicons dashicons-image-rotate"></span> <?php esc_html_e( 'Restore Backup', 'productshift-backup-migration' ); ?></a>
                    </div>
                </div>
                <div class="sbop-readiness-card" aria-label="<?php esc_attr_e( 'Backup readiness', 'productshift-backup-migration' ); ?>">
                    <div class="sbop-readiness-ring" style="--sbop-ready:<?php echo esc_attr( $readiness_percent ); ?>%;"><span><?php echo esc_html( $readiness_percent ); ?>%</span></div>
                    <div><small>BACKUP READINESS</small><strong><?php echo esc_html( $readiness_passed ); ?>/4 checks ready</strong><p>WooCommerce, ZIP support, writable storage and recent backup.</p></div>
                </div>
            </section>

            <section class="sbop-kpi-grid" aria-label="<?php esc_attr_e( 'Backup overview', 'productshift-backup-migration' ); ?>">
                <article class="sbop-kpi-card"><span class="dashicons dashicons-backup"></span><div><small>Saved backups</small><strong><?php echo esc_html( number_format_i18n( count( $all_history ) ) ); ?></strong><p>Retention limit <?php echo esc_html( $retention ); ?></p></div></article>
                <article class="sbop-kpi-card"><span class="dashicons dashicons-database"></span><div><small>Backup storage</small><strong><?php echo esc_html( size_format( $saved_storage ) ); ?></strong><p>Protected local copies</p></div></article>
                <article class="sbop-kpi-card"><span class="dashicons dashicons-clock"></span><div><small>Last backup</small><strong><?php echo $last_backup ? esc_html( human_time_diff( $last_backup, time() ) . ' ago' ) : esc_html__( 'Never', 'productshift-backup-migration' ); ?></strong><p><?php echo $last_backup ? esc_html( wp_date( 'M j, g:i a', $last_backup ) ) : esc_html__( 'Create your first backup', 'productshift-backup-migration' ); ?></p></div></article>
                <article class="sbop-kpi-card"><span class="dashicons dashicons-controls-repeat"></span><div><small>Automation</small><strong><?php echo 'off' === $schedule ? esc_html__( 'Manual', 'productshift-backup-migration' ) : esc_html( ucfirst( str_replace( 'sbop_', '', $schedule ) ) ); ?></strong><p><?php echo $next_schedule ? esc_html( 'Next ' . human_time_diff( time(), $next_schedule ) ) : esc_html__( 'No scheduled job', 'productshift-backup-migration' ); ?></p></div></article>
            </section>

            <div class="sbop-dashboard-columns">
                <section class="sbop-dashboard-panel">
                    <div class="sbop-section-title"><div><span class="dashicons dashicons-chart-area"></span><h2>Store snapshot</h2></div><a href="<?php echo esc_url( admin_url( 'admin.php?page=sbop-backup-system' ) ); ?>">View system status</a></div>
                    <div class="sbop-snapshot-grid">
                        <div><span class="dashicons dashicons-products"></span><small>Products</small><strong><?php echo esc_html( number_format_i18n( $product_count ) ); ?></strong></div>
                        <div><span class="dashicons dashicons-format-gallery"></span><small>Media files</small><strong><?php echo esc_html( number_format_i18n( $media_count ) ); ?></strong></div>
                        <div><span class="dashicons dashicons-upload"></span><small>Upload limit</small><strong><?php echo esc_html( $upload_limit_mb ); ?> MB</strong></div>
                        <div><span class="dashicons dashicons-shield-alt"></span><small>Restore mode</small><strong>Resumable</strong></div>
                    </div>
                </section>

                <section class="sbop-dashboard-panel">
                    <div class="sbop-section-title"><div><span class="dashicons dashicons-admin-generic"></span><h2>Quick actions</h2></div><span>Common tasks</span></div>
                    <div class="sbop-action-list">
                        <a href="<?php echo esc_url( admin_url( 'admin.php?page=sbop-backup-create' ) ); ?>"><span class="dashicons dashicons-download"></span><div><strong>Create a backup</strong><small>Products or Media Library</small></div><span class="dashicons dashicons-arrow-right-alt2"></span></a>
                        <a href="<?php echo esc_url( admin_url( 'admin.php?page=sbop-backup-restore' ) ); ?>"><span class="dashicons dashicons-image-rotate"></span><div><strong>Restore an archive</strong><small>Resumable ZIP restore</small></div><span class="dashicons dashicons-arrow-right-alt2"></span></a>
                        <a href="<?php echo esc_url( admin_url( 'admin.php?page=sbop-backup-automation' ) ); ?>"><span class="dashicons dashicons-calendar-alt"></span><div><strong>Configure automation</strong><small>Schedule and retention</small></div><span class="dashicons dashicons-arrow-right-alt2"></span></a>
                        <a href="<?php echo esc_url( admin_url( 'admin.php?page=sbop-backup-recovery' ) ); ?>"><span class="dashicons dashicons-shield-alt"></span><div><strong>Recovery Center</strong><small>Rollback and incremental status</small></div><span class="dashicons dashicons-arrow-right-alt2"></span></a>
                    </div>
                </section>
            </div>

            <div class="sbop-dashboard-columns sbop-dashboard-lower">
                <section class="sbop-dashboard-panel">
                    <div class="sbop-section-title"><div><span class="dashicons dashicons-backup"></span><h2>Recent backups</h2></div><a href="<?php echo esc_url( admin_url( 'admin.php?page=sbop-backup-history' ) ); ?>">View all</a></div>
                    <?php if ( empty( $recent_history ) ) : ?>
                        <div class="sbop-dashboard-empty"><span class="dashicons dashicons-archive"></span><div><strong>No saved backups yet</strong><p>Create a backup and it will appear here.</p></div></div>
                    <?php else : ?>
                        <div class="sbop-compact-list">
                            <?php foreach ( $recent_history as $entry ) : ?>
                                <div class="sbop-compact-row"><span class="sbop-file-icon"><span class="dashicons dashicons-media-archive"></span></span><div><strong><?php echo esc_html( isset( $entry['filename'] ) ? $entry['filename'] : 'backup.zip' ); ?></strong><small><?php echo esc_html( isset( $entry['created_at'] ) ? wp_date( 'M j, Y g:i a', (int) $entry['created_at'] ) : '' ); ?> · <?php echo esc_html( isset( $entry['size'] ) ? size_format( (int) $entry['size'] ) : '' ); ?></small></div><span class="sbop-type-pill"><?php echo esc_html( ucfirst( isset( $entry['type'] ) ? $entry['type'] : 'backup' ) ); ?></span></div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </section>

                <section class="sbop-dashboard-panel">
                    <div class="sbop-section-title"><div><span class="dashicons dashicons-list-view"></span><h2>Recent activity</h2></div><a href="<?php echo esc_url( admin_url( 'admin.php?page=sbop-backup-activity' ) ); ?>">View log</a></div>
                    <?php if ( empty( $recent_activity ) ) : ?>
                        <div class="sbop-dashboard-empty"><span class="dashicons dashicons-info-outline"></span><div><strong>No activity yet</strong><p>Backup and restore events will appear here.</p></div></div>
                    <?php else : ?>
                        <div class="sbop-compact-list">
                            <?php foreach ( $recent_activity as $event ) : $event_type = sanitize_html_class( isset( $event['type'] ) ? $event['type'] : 'event' ); ?>
                                <div class="sbop-compact-row"><span class="sbop-event-dot <?php echo esc_attr( $event_type ); ?>"></span><div><strong><?php echo esc_html( isset( $event['message'] ) ? $event['message'] : 'Activity recorded' ); ?></strong><small><?php echo esc_html( isset( $event['time'] ) ? human_time_diff( (int) $event['time'], time() ) . ' ago' : '' ); ?></small></div><span class="sbop-type-pill"><?php echo esc_html( ucfirst( $event_type ) ); ?></span></div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </section>
            </div>
            <?php endif; ?>

            <?php if ( 'backup' === $this->admin_view ) : ?>
            <div id="sbop-backup" class="sbop-grid sbop-grid-main">
                <section class="sbop-card sbop-backup-card">
                    <div class="sbop-card-head"><div class="sbop-icon purple"><span class="dashicons dashicons-products"></span></div><div><h2>Product Backup</h2><p>Complete catalog-fidelity backup: products, categories, attributes, images, variations, relationships and portable custom data.</p></div></div>
                    <form class="sbop-export-form" data-kind="products" method="post" target="sbop-download-frame" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                        <input type="hidden" name="action" value="sbop_export_products">
                        <input type="hidden" name="include_images" value="1">
                        <?php wp_nonce_field( 'sbop_export_products' ); ?>
                        <div class="sbop-info-box"><span class="dashicons dashicons-shield-alt"></span><div><strong>Complete product fidelity is always enabled</strong><small>Featured/gallery/variation/category/content images, category hierarchy, attributes and local downloadable files are packaged automatically.</small></div></div>
                        <label class="sbop-field"><span>Maximum products</span><input type="number" min="1" max="5000" name="limit" value="5000"></label>
                        <button class="sbop-btn sbop-btn-primary" type="submit"><span class="dashicons dashicons-download"></span> Create Product Backup</button>
                    </form>
                </section>

                <section class="sbop-card sbop-backup-card">
                    <div class="sbop-card-head"><div class="sbop-icon cyan"><span class="dashicons dashicons-format-gallery"></span></div><div><h2>Media Backup</h2><p>Original Media Library files and attachment metadata.</p></div></div>
                    <form class="sbop-export-form" data-kind="media" method="post" target="sbop-download-frame" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                        <input type="hidden" name="action" value="sbop_export_media">
                        <?php wp_nonce_field( 'sbop_export_media' ); ?>
                        <div class="sbop-info-box"><span class="dashicons dashicons-info-outline"></span><p>Includes title, caption, description and ALT text for each original media file.</p></div>
                        <label class="sbop-field"><span>Maximum media items</span><input type="number" min="1" max="10000" name="limit" value="5000"></label>
                        <button class="sbop-btn sbop-btn-dark" type="submit"><span class="dashicons dashicons-download"></span> Create Media Backup</button>
                    </form>
                </section>
            </div>
            <section class="sbop-card sbop-incremental-card">
                <div class="sbop-card-head"><div class="sbop-icon green"><span class="dashicons dashicons-update"></span></div><div><h2>Incremental Backup</h2><p>Back up only products and related media changed since the last successful product or incremental backup.</p></div></div>
                <div class="sbop-incremental-layout">
                    <div class="sbop-incremental-meta">
                        <span class="sbop-type-pill"><?php echo $incremental_anchor ? esc_html__( 'Baseline ready', 'productshift-backup-migration' ) : esc_html__( 'First run creates baseline', 'productshift-backup-migration' ); ?></span>
                        <strong><?php echo $incremental_anchor ? esc_html( wp_date( 'M j, Y g:i a', $incremental_anchor ) ) : esc_html__( 'No incremental baseline yet', 'productshift-backup-migration' ); ?></strong>
                        <p><?php echo $incremental_anchor ? esc_html__( 'Only products modified after this point are included.', 'productshift-backup-migration' ) : esc_html__( 'The first incremental backup includes the current product set and establishes the baseline.', 'productshift-backup-migration' ); ?></p>
                    </div>
                    <form class="sbop-export-form sbop-inline-export" data-kind="incremental" method="post" target="sbop-download-frame" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                        <input type="hidden" name="action" value="sbop_export_incremental">
                        <?php wp_nonce_field( 'sbop_export_incremental' ); ?>
                        <label class="sbop-toggle-row"><span><strong>Include changed product images</strong><small>Package featured, gallery and variation images for changed products.</small></span><input type="checkbox" name="include_images" value="1" checked><i></i></label>
                        <label class="sbop-field"><span>Maximum changed products</span><input type="number" min="1" max="5000" name="limit" value="5000"></label>
                        <button class="sbop-btn sbop-btn-success" type="submit"><span class="dashicons dashicons-update"></span> Create Incremental Backup</button>
                    </form>
                </div>
            </section>

            <section class="sbop-card sbop-backup-saved-card">
                <div class="sbop-card-head"><div class="sbop-icon purple"><span class="dashicons dashicons-backup"></span></div><div><h2>Saved Backups</h2><p>Every successful backup is stored on the server first, then downloaded. You can download it again from here or from Backup History.</p></div></div>
                <div id="sbop-backup-recent-list" class="sbop-history-list">
                    <?php if ( empty( $history ) ) : ?>
                        <div id="sbop-backup-empty" class="sbop-empty-state"><span class="dashicons dashicons-archive"></span><p>No saved backups yet. Create a Product, Media or Incremental backup and it will appear here.</p></div>
                    <?php else : ?>
                        <?php foreach ( array_slice( $history, 0, 5 ) as $entry ) :
                            $entry_id = isset( $entry['id'] ) ? (string) $entry['id'] : '';
                            $download_url = $this->backup_download_url( $entry_id );
                            $delete_url = $this->backup_delete_url( $entry_id );
                        ?>
                        <div class="sbop-history-row" data-backup-id="<?php echo esc_attr( $entry_id ); ?>">
                            <div><strong><?php echo esc_html( isset( $entry['filename'] ) ? $entry['filename'] : 'backup.zip' ); ?></strong><small><?php echo esc_html( isset( $entry['created_at'] ) ? wp_date( 'M j, Y g:i a', (int) $entry['created_at'] ) : '' ); ?> · <?php echo esc_html( isset( $entry['size'] ) ? size_format( (int) $entry['size'] ) : '' ); ?></small></div>
                            <div class="sbop-history-meta"><span><?php echo esc_html( ucfirst( str_replace( '-', ' ', isset( $entry['type'] ) ? $entry['type'] : 'backup' ) ) ); ?></span><span><?php echo esc_html( (int) ( $entry['product_count'] ?? 0 ) ); ?> products</span><span><?php echo esc_html( (int) ( $entry['media_count'] ?? 0 ) ); ?> media</span></div>
                            <div class="sbop-history-actions"><a class="sbop-btn sbop-btn-soft" href="<?php echo esc_url( $download_url ); ?>">Download</a><?php if ( $protected_rollback_id && hash_equals( $protected_rollback_id, $entry_id ) ) : ?><span class="sbop-protected-badge"><span class="dashicons dashicons-lock"></span> Recovery point</span><?php else : ?><a class="sbop-btn sbop-btn-danger sbop-delete-backup" href="<?php echo esc_url( $delete_url ); ?>">Delete</a><?php endif; ?></div>
                        </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
                <p class="sbop-saved-backup-foot"><a href="<?php echo esc_url( admin_url( 'admin.php?page=sbop-backup-history' ) ); ?>">Open full Backup History</a></p>
            </section>
            <?php endif; ?>

            <?php if ( 'restore' === $this->admin_view ) : ?>
            <section id="sbop-restore" class="sbop-card sbop-restore-card">
                <div class="sbop-restore-header">
                    <div class="sbop-card-head"><div class="sbop-icon green"><span class="dashicons dashicons-image-rotate"></span></div><div><h2>Restore / Import</h2><p>Upload a backup ZIP. Restore runs in small resumable batches.</p></div></div>
                    <span class="sbop-safe-badge"><span class="dashicons dashicons-lock"></span> Safe batch restore</span>
                </div>
                <form id="sbop-restore-form" enctype="multipart/form-data">
                    <label class="sbop-dropzone" for="sbop-backup-zip">
                        <span class="sbop-upload-icon"><span class="dashicons dashicons-upload"></span></span>
                        <strong>Choose a backup ZIP</strong>
                        <span>Tap to browse. Maximum upload: <?php echo esc_html( $upload_limit_mb ); ?> MB</span>
                        <input type="file" name="backup_zip" id="sbop-backup-zip" accept=".zip,application/zip" required>
                        <em id="sbop-selected-file">No file selected</em>
                    </label>
                    <div class="sbop-restore-options">
                        <label class="sbop-field"><span>When SKU already exists</span><select name="conflict_mode" id="sbop-conflict-mode"><option value="update">Update existing product</option><option value="skip">Skip existing product</option><option value="new">Create a new product</option></select></label>
                        <div class="sbop-actions">
                            <button type="submit" class="sbop-btn sbop-btn-primary" id="sbop-start-restore"><span class="dashicons dashicons-search"></span> Analyze Backup</button>
                            <button type="button" class="sbop-btn sbop-btn-success" id="sbop-confirm-restore" style="display:none"><span class="dashicons dashicons-yes-alt"></span> Confirm Restore</button><button type="button" class="sbop-btn sbop-btn-soft" id="sbop-resume-restore" style="display:none"><span class="dashicons dashicons-controls-play"></span> Resume Restore</button>
                            <button type="button" class="sbop-btn sbop-btn-danger" id="sbop-cancel-restore" style="display:none">Cancel Job</button>
                        </div>
                    </div>
                </form>

                <div id="sbop-dry-run" class="sbop-dry-run" style="display:none" aria-live="polite">
                    <div class="sbop-dry-run-head"><div><small>DRY RUN PREVIEW</small><h3>Restore impact analysis</h3><p>No store data has been changed yet. Review the plan, then confirm restore.</p></div><span class="sbop-safe-badge"><span class="dashicons dashicons-shield-alt"></span> Read-only analysis</span></div>
                    <div class="sbop-dry-run-grid">
                        <div><small>Products</small><strong id="sbop-preview-products">0</strong><span>in archive</span></div>
                        <div><small>Will create</small><strong id="sbop-preview-create">0</strong><span>new products</span></div>
                        <div><small>Will update</small><strong id="sbop-preview-update">0</strong><span>existing products</span></div>
                        <div><small>Will skip</small><strong id="sbop-preview-skip">0</strong><span>by conflict rule</span></div>
                        <div><small>Media</small><strong id="sbop-preview-media">0</strong><span>files to import</span></div>
                        <div><small>Variations</small><strong id="sbop-preview-variations">0</strong><span>records</span></div>
                    </div>
                    <div id="sbop-preview-conflicts" class="sbop-preview-conflicts" style="display:none"></div>
                    <div class="sbop-info-box"><span class="dashicons dashicons-backup"></span><p>When you confirm, ProductShift – Backup & Migration for WooCommerce creates a rollback point for existing products that are about to be updated.</p></div>
                </div>

                <div id="sbop-progress" class="sbop-progress-panel" style="display:none" aria-live="polite">
                    <div class="sbop-progress-top">
                        <div><small id="sbop-progress-kicker">RESTORE IN PROGRESS</small><h3 id="sbop-progress-label">Preparing restore…</h3></div>
                        <div class="sbop-percent-ring"><svg viewBox="0 0 48 48"><circle class="track" cx="24" cy="24" r="20"></circle><circle id="sbop-ring-progress" class="value" cx="24" cy="24" r="20"></circle></svg><strong id="sbop-progress-percent">0%</strong></div>
                    </div>
                    <div class="sbop-progress-bar"><span id="sbop-progress-fill" style="width:0%"></span></div>
                    <div class="sbop-stage-row" id="sbop-stage-row"><span data-stage="validate">Validate ZIP</span><span data-stage="media">Restore media</span><span data-stage="products">Restore products</span><span data-stage="variations">Variations</span><span data-stage="finalize">Finalize</span></div>
                    <p id="sbop-progress-details" class="sbop-progress-details"></p>
                    <div id="sbop-error-box" class="sbop-error-box" style="display:none"></div>
                </div>
                <div id="sbop-success-panel" class="sbop-success-panel" style="display:none" role="status" aria-live="polite">
                    <div class="sbop-success-check"><span class="dashicons dashicons-yes-alt"></span></div>
                    <div class="sbop-success-copy"><small>RESTORE COMPLETE</small><h3>Successfully restored</h3><p id="sbop-success-summary">Your backup was restored successfully.</p></div>
                    <button type="button" class="sbop-success-close" id="sbop-success-close" aria-label="Close success message"><span class="dashicons dashicons-no-alt"></span></button>
                </div>
            </section>

            <section class="sbop-card sbop-note">
                <div class="sbop-card-head"><div class="sbop-icon amber"><span class="dashicons dashicons-shield"></span></div><div><h2>Restore safety</h2><p>ZIP paths are validated before extraction. Media, products and variations restore separately, and item errors are captured without crashing the whole request. Backups from v0.1.0 remain supported.</p></div></div>
            </section>
            <?php endif; ?>

            <?php if ( 'recovery' === $this->admin_view ) : ?>
            <div class="sbop-recovery-grid">
                <section class="sbop-card sbop-recovery-card">
                    <div class="sbop-card-head"><div class="sbop-icon green"><span class="dashicons dashicons-backup"></span></div><div><h2>Last Restore Rollback</h2><p>Undo the most recent completed restore using its automatic recovery point.</p></div></div>
                    <?php if ( empty( $last_restore_rollback ) ) : ?>
                        <div class="sbop-empty-state"><span class="dashicons dashicons-shield-alt"></span><p>No rollback point is available yet. A recovery point is created automatically when a restore is confirmed.</p></div>
                    <?php else : ?>
                        <div class="sbop-recovery-summary">
                            <div><small>Restore completed</small><strong><?php echo ! empty( $last_restore_rollback['completed_at'] ) ? esc_html( wp_date( 'M j, Y g:i a', (int) $last_restore_rollback['completed_at'] ) ) : esc_html__( 'Recently', 'productshift-backup-migration' ); ?></strong></div>
                            <div><small>Created products</small><strong><?php echo esc_html( count( (array) ( $last_restore_rollback['created_product_ids'] ?? array() ) ) ); ?></strong></div>
                            <div><small>Created media</small><strong><?php echo esc_html( count( (array) ( $last_restore_rollback['created_media_ids'] ?? array() ) ) ); ?></strong></div>
                            <div><small>Product snapshot</small><strong><?php echo ! empty( $last_restore_rollback['rollback_backup_id'] ) ? esc_html__( 'Available', 'productshift-backup-migration' ) : esc_html__( 'Not required', 'productshift-backup-migration' ); ?></strong></div>
                        </div>
                        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" data-sbop-confirm="<?php echo esc_attr__( 'Undo the last restore? Products/media created by that restore will be removed and the saved recovery point will be applied.', 'productshift-backup-migration' ); ?>">
                            <input type="hidden" name="action" value="sbop_rollback_last_restore">
                            <?php wp_nonce_field( 'sbop_rollback_last_restore' ); ?>
                            <button type="submit" class="sbop-btn sbop-btn-danger"><span class="dashicons dashicons-undo"></span> Undo Last Restore</button>
                        </form>
                    <?php endif; ?>
                </section>

                <section class="sbop-card sbop-recovery-card">
                    <div class="sbop-card-head"><div class="sbop-icon purple"><span class="dashicons dashicons-update"></span></div><div><h2>Incremental Baseline</h2><p>Track the point from which changed products are collected.</p></div></div>
                    <div class="sbop-recovery-highlight"><small>CURRENT BASELINE</small><strong><?php echo $incremental_anchor ? esc_html( wp_date( 'M j, Y g:i a', $incremental_anchor ) ) : esc_html__( 'Not established', 'productshift-backup-migration' ); ?></strong><p><?php echo $incremental_anchor ? esc_html__( 'Future incremental archives include products modified after this timestamp.', 'productshift-backup-migration' ) : esc_html__( 'Create an incremental or full product backup to establish the baseline.', 'productshift-backup-migration' ); ?></p></div>
                    <div class="sbop-recovery-actions"><a class="button button-primary" href="<?php echo esc_url( admin_url( 'admin.php?page=sbop-backup-create' ) ); ?>">Create backup</a><form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" data-sbop-confirm="<?php echo esc_attr__( 'Reset the incremental baseline? The next incremental backup will act as a new full baseline.', 'productshift-backup-migration' ); ?>"><input type="hidden" name="action" value="sbop_reset_incremental"><?php wp_nonce_field( 'sbop_reset_incremental' ); ?><button type="submit" class="button">Reset baseline</button></form></div>
                </section>

                <section class="sbop-card sbop-recovery-card">
                    <div class="sbop-card-head"><div class="sbop-icon cyan"><span class="dashicons dashicons-cloud-upload"></span></div><div><h2>Remote Copy Status</h2><p>Keep an off-site copy after local backups are created.</p></div></div>
                    <div class="sbop-recovery-highlight"><small>REMOTE STORAGE</small><strong><?php echo $remote_enabled ? esc_html( 'Enabled · ' . ucfirst( $remote_provider ) ) : esc_html__( 'Disabled', 'productshift-backup-migration' ); ?></strong><p><?php echo $remote_last_status ? esc_html( $remote_last_status ) : esc_html__( 'Configure WebDAV or a developer storage adapter in Settings.', 'productshift-backup-migration' ); ?></p></div>
                    <a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=sbop-backup-settings' ) ); ?>">Remote storage settings</a>
                </section>
            </div>
            <?php endif; ?>

            <?php if ( 'history' === $this->admin_view ) : ?>
            <div class="sbop-single-column">
                <section id="sbop-history" class="sbop-card">
                    <div class="sbop-card-head"><div class="sbop-icon purple"><span class="dashicons dashicons-backup"></span></div><div><h2>Backup History</h2><p>Recent backups are kept on the server according to your retention setting.</p></div></div>
                    <?php if ( empty( $history ) ) : ?>
                        <div class="sbop-empty-state"><span class="dashicons dashicons-archive"></span><p>No saved backups yet. Create a backup and it will appear here.</p></div>
                    <?php else : ?>
                        <div class="sbop-history-list">
                            <?php foreach ( $history as $entry ) :
                                $entry_id = isset( $entry['id'] ) ? (string) $entry['id'] : '';
                                $download_url = $this->backup_download_url( $entry_id );
                                $delete_url = $this->backup_delete_url( $entry_id );
                                ?>
                                <div class="sbop-history-row">
                                    <div><strong><?php echo esc_html( isset( $entry['filename'] ) ? $entry['filename'] : 'backup.zip' ); ?></strong><small><?php echo esc_html( isset( $entry['created_at'] ) ? wp_date( 'M j, Y g:i a', (int) $entry['created_at'] ) : '' ); ?> · <?php echo esc_html( isset( $entry['size'] ) ? size_format( (int) $entry['size'] ) : '' ); ?></small></div>
                                    <div class="sbop-history-meta"><span><?php echo esc_html( ucfirst( str_replace( '-', ' ', isset( $entry['type'] ) ? $entry['type'] : 'backup' ) ) ); ?></span><span><?php echo esc_html( (int) ( $entry['product_count'] ?? 0 ) ); ?> products</span><span><?php echo esc_html( (int) ( $entry['media_count'] ?? 0 ) ); ?> media</span><?php if ( ! empty( $entry['remote_status'] ) ) : ?><span><?php echo esc_html( $entry['remote_status'] ); ?></span><?php endif; ?></div>
                                    <div class="sbop-history-actions"><a class="sbop-btn sbop-btn-soft" href="<?php echo esc_url( $download_url ); ?>">Download</a><?php if ( $protected_rollback_id && hash_equals( $protected_rollback_id, $entry_id ) ) : ?><span class="sbop-protected-badge"><span class="dashicons dashicons-lock"></span> Recovery point</span><?php else : ?><a class="sbop-btn sbop-btn-danger sbop-delete-backup" href="<?php echo esc_url( $delete_url ); ?>">Delete</a><?php endif; ?></div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </section>
            </div>
            <?php endif; ?>

            <?php if ( 'automation' === $this->admin_view ) : ?>
            <div class="sbop-single-column">
                <section id="sbop-automation" class="sbop-card">
                    <div class="sbop-card-head"><div class="sbop-icon cyan"><span class="dashicons dashicons-calendar-alt"></span></div><div><h2>Schedule & Retention</h2><p>Automatically create product backups and control how many server copies are kept.</p></div></div>
                    <form class="sbop-settings-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                        <input type="hidden" name="action" value="sbop_save_settings">
                        <input type="hidden" name="settings_section" value="automation">
                        <?php wp_nonce_field( 'sbop_save_settings' ); ?>
                        <label class="sbop-field"><span>Automatic backup</span><select name="schedule"><option value="off" <?php selected( $schedule, 'off' ); ?>>Off</option><option value="daily" <?php selected( $schedule, 'daily' ); ?>>Daily</option><option value="sbop_weekly" <?php selected( $schedule, 'sbop_weekly' ); ?>>Weekly</option></select></label>
                        <label class="sbop-field"><span>Keep latest backups</span><input type="number" min="1" max="25" name="retention" value="<?php echo esc_attr( $retention ); ?>"></label>
                        <label class="sbop-field"><span>Scheduled product limit</span><input type="number" min="1" max="5000" name="scheduled_limit" value="<?php echo esc_attr( $scheduled_limit ); ?>"></label>
                        <div class="sbop-schedule-note">
                            <?php if ( $next_schedule ) : ?><span>Next run: <strong><?php echo esc_html( wp_date( 'M j, Y g:i a', $next_schedule ) ); ?></strong></span><?php else : ?><span>Automatic backups are currently disabled.</span><?php endif; ?>
                            <?php if ( $last_schedule ) : ?><span>Last run: <?php echo esc_html( wp_date( 'M j, Y g:i a', $last_schedule ) ); ?></span><?php endif; ?>
                            <?php if ( $last_schedule_error ) : ?><span class="sbop-status-bad">Last scheduled error: <?php echo esc_html( $last_schedule_error ); ?></span><?php endif; ?>
                        </div>
                        <button class="sbop-btn sbop-btn-dark" type="submit">Save Settings</button>
                    </form>
                </section>
            </div>
            <?php endif; ?>

            <?php if ( 'activity' === $this->admin_view ) : ?>
            <section id="sbop-activity" class="sbop-card sbop-activity-card">
                <div class="sbop-card-head"><div class="sbop-icon purple"><span class="dashicons dashicons-list-view"></span></div><div><h2>Administrative Activity</h2><p>Recent backup, restore, automation and configuration events.</p></div></div>
                <?php if ( empty( $activity ) ) : ?>
                    <div class="sbop-empty-state"><span class="dashicons dashicons-clock"></span><p>No administrative activity has been recorded yet.</p></div>
                <?php else : ?>
                    <div class="sbop-activity-list">
                    <?php foreach ( $activity as $event ) : $etype = isset( $event['type'] ) ? sanitize_key( $event['type'] ) : 'info'; ?>
                        <div class="sbop-activity-row"><span class="sbop-event-dot <?php echo esc_attr( $etype ); ?>"></span><div><strong><?php echo esc_html( $event['message'] ?? 'Activity' ); ?></strong><small><?php echo ! empty( $event['time'] ) ? esc_html( wp_date( 'M j, Y g:i a', (int) $event['time'] ) ) : ''; ?><?php if ( ! empty( $event['user'] ) ) : ?> · User #<?php echo esc_html( (int) $event['user'] ); ?><?php endif; ?></small></div><em><?php echo esc_html( ucfirst( $etype ) ); ?></em></div>
                    <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </section>
            <?php endif; ?>

            <?php if ( 'system' === $this->admin_view ) : ?>
            <section id="sbop-system" class="sbop-card sbop-system-card">
                <div class="sbop-card-head"><div class="sbop-icon green"><span class="dashicons dashicons-admin-tools"></span></div><div><h2>System Status</h2><p>Quick checks that help identify backup and restore limits before a job starts.</p></div></div>
                <div class="sbop-status-grid">
                    <div><small>WordPress</small><strong><?php echo esc_html( get_bloginfo( 'version' ) ); ?></strong><span class="sbop-status-good">Ready</span></div>
                    <div><small>PHP</small><strong><?php echo esc_html( PHP_VERSION ); ?></strong><span class="sbop-status-good">Ready</span></div>
                    <div><small>WooCommerce</small><strong><?php echo esc_html( defined( 'WC_VERSION' ) ? WC_VERSION : 'Not active' ); ?></strong><span class="<?php echo class_exists( 'WooCommerce' ) ? 'sbop-status-good' : 'sbop-status-bad'; ?>"><?php echo class_exists( 'WooCommerce' ) ? 'Ready' : 'Required for products'; ?></span></div>
                    <div><small>ZIP extension</small><strong><?php echo class_exists( 'ZipArchive' ) ? 'Enabled' : 'Missing'; ?></strong><span class="<?php echo class_exists( 'ZipArchive' ) ? 'sbop-status-good' : 'sbop-status-bad'; ?>"><?php echo class_exists( 'ZipArchive' ) ? 'Ready' : 'Ask your host'; ?></span></div>
                    <div><small>PHP memory</small><strong><?php echo esc_html( $memory_limit ? $memory_limit : 'Unknown' ); ?></strong><span>Server setting</span></div>
                    <div><small>Free disk</small><strong><?php echo esc_html( false !== $disk_free ? size_format( (int) $disk_free ) : 'Unknown' ); ?></strong><span>wp-content volume</span></div>
                </div>
            </section>
            <?php endif; ?>

            <?php if ( 'settings' === $this->admin_view ) : ?>
            <section class="sbop-card sbop-settings-card">
                <div class="sbop-card-head"><div class="sbop-icon purple"><span class="dashicons dashicons-admin-generic"></span></div><div><h2>Plugin Settings</h2><p>Notification preferences and data cleanup controls.</p></div></div>
                <form class="sbop-settings-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                    <input type="hidden" name="action" value="sbop_save_settings">
                    <input type="hidden" name="settings_section" value="general">
                    <?php wp_nonce_field( 'sbop_save_settings' ); ?>
                    <label class="sbop-toggle-row"><span><strong>Email notifications</strong><small>Send an administrator email when scheduled backups or restores complete or fail.</small></span><input type="checkbox" name="email_notifications" value="1" <?php checked( $email_notifications ); ?>><i></i></label>
                    <label class="sbop-field"><span>Notification email</span><input type="email" name="notification_email" value="<?php echo esc_attr( $notification_email ); ?>" placeholder="admin@example.com"></label>
                    <div class="sbop-setting-divider"></div>
                    <label class="sbop-toggle-row"><span><strong>Delete plugin data on uninstall</strong><small>Remove saved backup ZIPs and plugin settings only when the plugin is uninstalled.</small></span><input type="checkbox" name="delete_data_on_uninstall" value="1" <?php checked( $delete_on_uninstall ); ?>><i></i></label>
                    <div class="sbop-danger-note"><span class="dashicons dashicons-warning"></span><div><strong>Uninstall cleanup</strong><p>Keep this disabled if you want saved backups to remain after removing the plugin.</p></div></div>
                    <button class="sbop-btn sbop-btn-dark" type="submit">Save Settings</button>
                </form>
            </section>

            <section class="sbop-card sbop-settings-card sbop-remote-settings">
                <div class="sbop-card-head"><div class="sbop-icon cyan"><span class="dashicons dashicons-cloud-upload"></span></div><div><h2>Remote Storage</h2><p>Send a background off-site copy after each local product, media or incremental backup.</p></div></div>
                <form class="sbop-settings-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                    <input type="hidden" name="action" value="sbop_save_settings">
                    <input type="hidden" name="settings_section" value="remote">
                    <?php wp_nonce_field( 'sbop_save_settings' ); ?>
                    <label class="sbop-toggle-row"><span><strong>Enable remote copy</strong><small>Local backups remain available even if the remote upload fails.</small></span><input type="checkbox" name="remote_enabled" value="1" <?php checked( $remote_enabled ); ?>><i></i></label>
                    <label class="sbop-field"><span>Provider</span><select name="remote_provider"><option value="webdav" <?php selected( $remote_provider, 'webdav' ); ?>>WebDAV</option><option value="hook" <?php selected( $remote_provider, 'hook' ); ?>>Developer hook adapter</option></select></label>
                    <label class="sbop-field"><span>WebDAV directory URL</span><input type="url" name="remote_url" value="<?php echo esc_attr( $remote_url ); ?>" placeholder="https://cloud.example.com/remote.php/dav/files/user/backups"></label>
                    <label class="sbop-field"><span>WebDAV username</span><input type="text" name="remote_user" value="<?php echo esc_attr( $remote_user ); ?>" autocomplete="username"></label>
                    <label class="sbop-field"><span>WebDAV password</span><input type="password" name="remote_password" value="" placeholder="Leave blank to keep saved password" autocomplete="new-password"></label>
                    <div class="sbop-info-box"><span class="dashicons dashicons-info-outline"></span><p>WebDAV uploads run through WP-Cron after the local backup completes. Developer adapters can use the <code>sbop_remote_store_backup</code> action for S3, Google Drive or another provider.</p></div>
                    <button class="sbop-btn sbop-btn-dark" type="submit">Save Remote Storage</button>
                </form>
            </section>
            <?php endif; ?>

            <footer class="sbop-admin-footer">
                <div class="sbop-footer-brand"><span class="dashicons dashicons-database-export"></span><strong>ProductShift</strong><span>v<?php echo esc_html( self::VERSION ); ?></span></div>
                <nav aria-label="<?php esc_attr_e( 'ProductShift footer navigation', 'productshift-backup-migration' ); ?>">
                    <a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::MENU_SLUG ) ); ?>">Dashboard</a>
                    <a href="<?php echo esc_url( admin_url( 'admin.php?page=sbop-backup-create' ) ); ?>">Backup</a>
                    <a href="<?php echo esc_url( admin_url( 'admin.php?page=sbop-backup-restore' ) ); ?>">Restore</a>
                    <a href="<?php echo esc_url( admin_url( 'admin.php?page=sbop-backup-recovery' ) ); ?>">Recovery</a>
                    <a href="<?php echo esc_url( admin_url( 'admin.php?page=sbop-backup-system' ) ); ?>">System Status</a>
                    <a href="<?php echo esc_url( admin_url( 'admin.php?page=sbop-backup-settings' ) ); ?>">Settings</a>
                </nav>
                <span class="sbop-footer-state"><span class="sbop-health-dot"></span><?php echo esc_html( $protection_label ); ?></span>
            </footer>

            <div id="sbop-toast-stack" class="sbop-toast-stack" aria-live="polite" aria-atomic="true"></div>
            <div id="sbop-backup-modal" class="sbop-modal" style="display:none" role="dialog" aria-modal="true" aria-labelledby="sbop-backup-modal-title">
                <div class="sbop-modal-card">
                    <div class="sbop-backup-status-icon" aria-hidden="true"><div class="sbop-loader"><span></span><span></span><span></span></div><div class="sbop-backup-success-check"><span class="dashicons dashicons-yes-alt"></span></div></div>
                    <h2 id="sbop-backup-modal-title">Preparing backup</h2>
                    <p id="sbop-backup-modal-text">Collecting files and building your ZIP package…</p>
                    <div class="sbop-progress-bar large"><span id="sbop-backup-progress-fill" style="width:4%"></span></div>
                    <div class="sbop-backup-percent"><span id="sbop-backup-progress-text">4%</span><small>Keep this page open until the download starts.</small></div>
                    <button type="button" class="sbop-btn sbop-btn-soft" id="sbop-close-backup-modal" style="display:none">Close</button>
                </div>
            </div>
        </div>
        <?php
    }

    public function cron_schedules( $schedules ) {
        if ( ! isset( $schedules['sbop_weekly'] ) ) {
            $schedules['sbop_weekly'] = array(
                'interval' => WEEK_IN_SECONDS,
                'display'  => __( 'Once Weekly', 'productshift-backup-migration' ),
            );
        }
        return $schedules;
    }

    private function backup_base() {
        $uploads = wp_upload_dir();
        $base = trailingslashit( $uploads['basedir'] ) . 'smart-backup-only-pro/backups';
        wp_mkdir_p( $base );
        $protect = array(
            'index.php'  => "<?php\n// Silence is golden.\n",
            '.htaccess'  => "Deny from all\n",
            'web.config' => '<configuration><system.webServer><authorization><deny users="*" /></authorization></system.webServer></configuration>',
        );
        $filesystem = $this->filesystem();
        foreach ( $protect as $file => $contents ) {
            $path = trailingslashit( $base ) . $file;
            if ( ! file_exists( $path ) ) {
                $filesystem->put_contents( $path, $contents, FS_CHMOD_FILE );
            }
        }
        return $base;
    }

    private function backup_history() {
        $history = get_option( 'sbop_backup_history', array() );
        return is_array( $history ) ? $history : array();
    }

    private function save_backup_history( $history ) {
        $history = array_values( is_array( $history ) ? $history : array() );
        $updated = update_option( 'sbop_backup_history', $history, false );
        if ( $updated ) {
            return true;
        }
        return $history === get_option( 'sbop_backup_history', array() );
    }

    private function activity_log() {
        $log = get_option( 'sbop_activity_log', array() );
        return is_array( $log ) ? $log : array();
    }

    private function log_activity( $type, $message, $context = array() ) {
        $log = $this->activity_log();
        array_unshift( $log, array(
            'time'    => time(),
            'type'    => sanitize_key( $type ),
            'message' => sanitize_text_field( wp_strip_all_tags( (string) $message ) ),
            'user'    => get_current_user_id(),
            'context' => is_array( $context ) ? array_map( 'sanitize_text_field', array_map( 'strval', $context ) ) : array(),
        ) );
        $log = array_slice( $log, 0, 100 );
        update_option( 'sbop_activity_log', $log, false );
    }

    private function maybe_send_admin_email( $subject, $message ) {
        if ( ! get_option( 'sbop_email_notifications', false ) ) {
            return;
        }
        $email = sanitize_email( (string) get_option( 'sbop_notification_email', get_option( 'admin_email' ) ) );
        if ( $email ) {
            wp_mail( $email, $subject, $message );
        }
    }

    /**
     * Get a direct WordPress filesystem instance for plugin-owned files.
     *
     * @return WP_Filesystem_Direct
     */
    private function filesystem() {
        // admin-ajax.php does not guarantee that the filesystem constants/classes
        // normally loaded by wp-admin/includes/file.php are available. Load the
        // canonical WordPress filesystem bootstrap before creating our direct
        // instance so AJAX backup jobs behave the same as normal admin requests.
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-base.php';
        require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-direct.php';

        return new WP_Filesystem_Direct( false );
    }

    /**
     * Stream a file to the current response without loading it fully into memory.
     *
     * @param string $path Absolute file path.
     * @return void
     */
    private function stream_file( $path ) {
        try {
            $file = new SplFileObject( $path, 'rb' );
            while ( ! $file->eof() ) {
                $chunk = $file->fread( 1024 * 1024 );
                if ( '' === $chunk ) {
                    break;
                }
                // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Binary ZIP data must be streamed verbatim.
                echo $chunk;
            }
        } catch ( RuntimeException $e ) {
            wp_die( esc_html__( 'The backup file could not be read.', 'productshift-backup-migration' ) );
        }
    }

    private function register_backup( $source_path, $filename, $type, $manifest = array() ) {
        if ( ! file_exists( $source_path ) || ! is_file( $source_path ) || filesize( $source_path ) < 22 ) {
            $this->log_activity( 'error', 'Backup registration failed because the generated ZIP was missing or empty.', array( 'type' => $type ) );
            return false;
        }
        $id = wp_generate_uuid4();
        $stored_name = $id . '-' . sanitize_file_name( $filename );
        $dest = trailingslashit( $this->backup_base() ) . $stored_name;
        $filesystem = $this->filesystem();
        // Generated archives live under uploads as well, so moving first avoids copying a potentially multi-GB ZIP.
        $stored = $filesystem->move( $source_path, $dest, true );
        if ( ! $stored || ! file_exists( $dest ) || filesize( $dest ) < 22 ) {
            if ( file_exists( $dest ) ) {
                wp_delete_file( $dest );
            }
            $stored = $filesystem->copy( $source_path, $dest, true, FS_CHMOD_FILE );
        }
        if ( ! $stored || ! file_exists( $dest ) || ! is_file( $dest ) || filesize( $dest ) < 22 ) {
            $this->log_activity( 'error', 'Backup ZIP was created but could not be persisted in protected backup storage.', array( 'type' => $type ) );
            return false;
        }
        $verify_zip = new ZipArchive();
        $open_result = $verify_zip->open( $dest );
        $verified = true === $open_result && false !== $verify_zip->locateName( 'manifest.json', ZipArchive::FL_NODIR );
        if ( true === $open_result ) {
            $verify_zip->close();
        }
        if ( ! $verified ) {
            wp_delete_file( $dest );
            $this->log_activity( 'error', 'The persisted backup ZIP failed integrity verification.', array( 'type' => $type ) );
            return false;
        }
        $history = $this->backup_history();
        array_unshift( $history, array(
            'id'              => $id,
            'type'            => sanitize_key( $type ),
            'file'            => $stored_name,
            'filename'        => sanitize_file_name( $filename ),
            'size'            => (int) filesize( $dest ),
            'sha256'          => function_exists( 'hash_file' ) ? (string) hash_file( 'sha256', $dest ) : '',
            'download_key'    => wp_generate_password( 40, false, false ),
            'created_at'      => time(),
            'product_count'   => isset( $manifest['product_count'] ) ? (int) $manifest['product_count'] : 0,
            'variation_count' => isset( $manifest['variation_count'] ) ? (int) $manifest['variation_count'] : 0,
            'media_count'     => isset( $manifest['media_count'] ) ? (int) $manifest['media_count'] : 0,
            'remote_status'   => get_option( 'sbop_remote_enabled', false ) && 'rollback' !== sanitize_key( $type ) ? 'queued' : '',
        ) );
        $retention = max( 1, min( 25, (int) get_option( 'sbop_retention', 5 ) ) );
        $rollback_meta = get_option( 'sbop_last_restore_rollback', array() );
        $protected_rollback_id = is_array( $rollback_meta ) && ! empty( $rollback_meta['rollback_backup_id'] ) ? (string) $rollback_meta['rollback_backup_id'] : '';
        while ( count( $history ) > $retention ) {
            $remove_index = null;
            for ( $i = count( $history ) - 1; $i >= 0; $i-- ) {
                $candidate_id = isset( $history[ $i ]['id'] ) ? (string) $history[ $i ]['id'] : '';
                if ( ! $protected_rollback_id || ! hash_equals( $protected_rollback_id, $candidate_id ) ) {
                    $remove_index = $i;
                    break;
                }
            }
            if ( null === $remove_index ) {
                break;
            }
            $removed = array_splice( $history, $remove_index, 1 );
            $old = ! empty( $removed[0] ) ? $removed[0] : array();
            if ( ! empty( $old['file'] ) ) {
                $old_path = trailingslashit( $this->backup_base() ) . wp_basename( $old['file'] );
                if ( file_exists( $old_path ) ) {
                    wp_delete_file( $old_path );
                }
            }
        }
        if ( ! $this->save_backup_history( $history ) ) {
            wp_delete_file( $dest );
            $this->log_activity( 'error', 'Backup ZIP was saved but its Backup History record could not be written.', array( 'type' => $type ) );
            return false;
        }
        $history_check = get_option( 'sbop_backup_history', array() );
        $history_found = false;
        foreach ( (array) $history_check as $history_entry ) {
            if ( isset( $history_entry['id'] ) && hash_equals( (string) $id, (string) $history_entry['id'] ) ) {
                $history_found = true;
                break;
            }
        }
        if ( ! $history_found ) {
            wp_delete_file( $dest );
            $this->log_activity( 'error', 'Backup History verification failed after saving the ZIP.', array( 'type' => $type ) );
            return false;
        }
        $this->log_activity( 'backup', sprintf( 'Backup created: %s', sanitize_file_name( $filename ) ), array( 'type' => $type, 'id' => $id ) );
        if ( get_option( 'sbop_remote_enabled', false ) && 'rollback' !== sanitize_key( $type ) ) {
            wp_schedule_single_event( time() + 10, 'sbop_remote_upload', array( $id ) );
        }
        return $id;
    }

    /**
     * Get or create a stable random key for a saved backup download.
     *
     * The key is stored with the backup record instead of being derived from
     * WordPress salts/session state. This keeps saved Download links reliable
     * across nonce refreshes, session rotation and hosts that rotate salts.
     * Authorization still requires an authenticated WooCommerce manager.
     *
     * @param string $id Backup record UUID.
     * @return string
     */
    private function backup_download_key( $id ) {
        $id = sanitize_text_field( (string) $id );
        if ( ! $id ) {
            return '';
        }
        $entry = $this->find_backup( $id );
        if ( $entry && ! empty( $entry['download_key'] ) ) {
            return sanitize_text_field( (string) $entry['download_key'] );
        }
        $key = wp_generate_password( 40, false, false );
        if ( ! $this->update_backup_entry( $id, array( 'download_key' => $key ) ) ) {
            return '';
        }
        return $key;
    }

    private function backup_download_url( $id ) {
        $id = sanitize_text_field( (string) $id );
        // Return an unescaped URL because this helper is also used inside JSON/AJAX
        // responses. HTML call sites escape it with esc_url() at render time.
        return add_query_arg(
            array(
                'action'     => 'sbop_download_backup',
                'backup_id'  => $id,
                'sbop_key'   => $this->backup_download_key( $id ),
                '_wpnonce'   => wp_create_nonce( 'sbop_download_backup_' . $id ),
            ),
            admin_url( 'admin-post.php' )
        );
    }

    private function backup_delete_url( $id ) {
        $id = sanitize_text_field( (string) $id );
        // Keep this raw for AJAX-created history rows; HTML renderers call esc_url().
        return add_query_arg(
            array(
                'action'    => 'sbop_delete_backup',
                'backup_id' => $id,
                '_wpnonce'  => wp_create_nonce( 'sbop_backup_action_' . $id ),
            ),
            admin_url( 'admin-post.php' )
        );
    }

    private function backup_frame_response( $success, $data = array() ) {
        nocache_headers();
        if ( $success && ! empty( $data['download_url'] ) ) {
            wp_safe_redirect( esc_url_raw( $data['download_url'] ) );
            exit;
        }
        $message = ! empty( $data['message'] ) ? sanitize_text_field( (string) $data['message'] ) : __( 'The backup could not be completed.', 'productshift-backup-migration' );
        wp_die(
            esc_html( $message ),
            esc_html__( 'Backup could not be completed', 'productshift-backup-migration' ),
            array( 'response' => $success ? 200 : 500, 'back_link' => true )
        );
    }

    private function backup_client_payload( $backup_id ) {
        $entry = $this->find_backup( $backup_id );
        if ( ! $entry ) {
            return array();
        }
        $type = isset( $entry['type'] ) ? sanitize_key( $entry['type'] ) : 'backup';
        return array(
            'backup_id'       => (string) $backup_id,
            'filename'        => isset( $entry['filename'] ) ? sanitize_file_name( $entry['filename'] ) : 'backup.zip',
            'type'            => $type,
            'type_label'      => ucfirst( str_replace( '-', ' ', $type ) ),
            'size'            => isset( $entry['size'] ) ? (int) $entry['size'] : 0,
            'size_human'      => isset( $entry['size'] ) ? size_format( (int) $entry['size'] ) : '',
            'created_label'   => isset( $entry['created_at'] ) ? wp_date( 'M j, Y g:i a', (int) $entry['created_at'] ) : wp_date( 'M j, Y g:i a' ),
            'product_count'   => isset( $entry['product_count'] ) ? (int) $entry['product_count'] : 0,
            'variation_count' => isset( $entry['variation_count'] ) ? (int) $entry['variation_count'] : 0,
            'media_count'     => isset( $entry['media_count'] ) ? (int) $entry['media_count'] : 0,
            'download_url'    => $this->backup_download_url( $backup_id ),
            'delete_url'      => $this->backup_delete_url( $backup_id ),
        );
    }

    private function find_backup( $id ) {
        foreach ( $this->backup_history() as $entry ) {
            if ( isset( $entry['id'] ) && hash_equals( (string) $entry['id'], (string) $id ) ) {
                return $entry;
            }
        }
        return null;
    }

    private function update_backup_entry( $id, $changes ) {
        $history = $this->backup_history();
        $updated = false;
        foreach ( $history as &$entry ) {
            if ( isset( $entry['id'] ) && hash_equals( (string) $entry['id'], (string) $id ) ) {
                foreach ( (array) $changes as $key => $value ) {
                    $entry[ sanitize_key( $key ) ] = is_scalar( $value ) ? sanitize_text_field( (string) $value ) : $value;
                }
                $updated = true;
                break;
            }
        }
        unset( $entry );
        if ( $updated ) {
            $this->save_backup_history( $history );
        }
        return $updated;
    }

    public function remote_upload_backup( $backup_id ) {
        $backup_id = sanitize_text_field( (string) $backup_id );
        if ( ! get_option( 'sbop_remote_enabled', false ) ) {
            return;
        }
        $entry = $this->find_backup( $backup_id );
        if ( ! $entry || empty( $entry['file'] ) ) {
            return;
        }
        $path = trailingslashit( $this->backup_base() ) . wp_basename( $entry['file'] );
        if ( ! file_exists( $path ) ) {
            $this->update_backup_entry( $backup_id, array( 'remote_status' => 'missing local file' ) );
            return;
        }
        $provider = sanitize_key( (string) get_option( 'sbop_remote_provider', 'webdav' ) );
        if ( 'hook' === $provider ) {
            do_action( 'sbop_remote_store_backup', $entry, $path );
            $this->update_backup_entry( $backup_id, array( 'remote_status' => 'dispatched to adapter' ) );
            update_option( 'sbop_remote_last_status', 'Latest backup dispatched to the developer storage adapter.', false );
            return;
        }
        $url = esc_url_raw( (string) get_option( 'sbop_remote_url', '' ) );
        if ( ! $url ) {
            $this->update_backup_entry( $backup_id, array( 'remote_status' => 'WebDAV URL missing' ) );
            update_option( 'sbop_remote_last_status', 'Remote upload skipped: WebDAV URL is missing.', false );
            return;
        }
        $max_bytes = max( 1024 * 1024, (int) apply_filters( 'sbop_remote_max_bytes', 25 * 1024 * 1024 ) );
        $size = (int) filesize( $path );
        if ( $size > $max_bytes ) {
            $message = sprintf( 'Remote upload skipped: %s exceeds the %s safe background upload limit.', size_format( $size ), size_format( $max_bytes ) );
            $this->update_backup_entry( $backup_id, array( 'remote_status' => $message ) );
            update_option( 'sbop_remote_last_status', $message, false );
            $this->log_activity( 'remote', $message, array( 'backup_id' => $backup_id ) );
            return;
        }
        $filesystem = $this->filesystem();
        $body = $filesystem->get_contents( $path );
        if ( false === $body ) {
            $this->update_backup_entry( $backup_id, array( 'remote_status' => 'Could not read local backup' ) );
            return;
        }
        $remote_name = ! empty( $entry['filename'] ) ? sanitize_file_name( $entry['filename'] ) : wp_basename( $path );
        $target = untrailingslashit( $url ) . '/' . rawurlencode( $remote_name );
        $headers = array( 'Content-Type' => 'application/zip' );
        $user = sanitize_text_field( (string) get_option( 'sbop_remote_user', '' ) );
        $password = (string) get_option( 'sbop_remote_password', '' );
        if ( $user ) {
            $headers['Authorization'] = 'Basic ' . base64_encode( $user . ':' . $password );
        }
        $response = wp_remote_request( $target, array(
            'method'      => 'PUT',
            'timeout'     => 30,
            'redirection' => 3,
            'headers'     => $headers,
            'body'        => $body,
        ) );
        if ( is_wp_error( $response ) ) {
            $message = 'Remote upload failed: ' . $response->get_error_message();
            $this->update_backup_entry( $backup_id, array( 'remote_status' => $message ) );
            update_option( 'sbop_remote_last_status', $message, false );
            $this->log_activity( 'error', $message, array( 'backup_id' => $backup_id ) );
            return;
        }
        $code = (int) wp_remote_retrieve_response_code( $response );
        if ( $code < 200 || $code >= 300 ) {
            $message = 'Remote upload failed with HTTP ' . $code . '.';
            $this->update_backup_entry( $backup_id, array( 'remote_status' => $message ) );
            update_option( 'sbop_remote_last_status', $message, false );
            $this->log_activity( 'error', $message, array( 'backup_id' => $backup_id ) );
            return;
        }
        $this->update_backup_entry( $backup_id, array( 'remote_status' => 'stored remotely' ) );
        update_option( 'sbop_remote_last_status', 'Latest backup copied to WebDAV successfully.', false );
        $this->log_activity( 'remote', 'Backup copied to remote storage.', array( 'backup_id' => $backup_id ) );
    }

    public function download_backup() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( esc_html__( 'You do not have permission to download backups.', 'productshift-backup-migration' ), '', array( 'response' => 403 ) );
        }

        $id = isset( $_GET['backup_id'] ) ? sanitize_text_field( wp_unslash( $_GET['backup_id'] ) ) : '';
        if ( ! $id ) {
            wp_die( esc_html__( 'Backup not found.', 'productshift-backup-migration' ), '', array( 'response' => 404 ) );
        }

        $entry = $this->find_backup( $id );
        if ( ! $entry ) {
            wp_die( esc_html__( 'Backup not found.', 'productshift-backup-migration' ), '', array( 'response' => 404 ) );
        }

        // Verify the fresh nonce and stable per-backup key when available. A stale
        // browser nonce must not lock an already-authorized store manager out of a
        // read-only download; current_user_can() remains the primary authorization.
        $nonce = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '';
        $key = isset( $_GET['sbop_key'] ) ? sanitize_text_field( wp_unslash( $_GET['sbop_key'] ) ) : '';
        $nonce_valid = $nonce && wp_verify_nonce( $nonce, 'sbop_download_backup_' . $id );
        $stored_key = ! empty( $entry['download_key'] ) ? sanitize_text_field( (string) $entry['download_key'] ) : '';
        $key_valid = $key && $stored_key && hash_equals( $stored_key, $key );
        if ( ! $nonce_valid && ! $key_valid ) {
            $this->log_activity( 'download', 'Backup download used capability fallback because the page authorization was stale.', array( 'backup_id' => $id ) );
        }

        $base = $this->backup_base();
        $path = trailingslashit( $base ) . wp_basename( $entry['file'] );
        $base_real = realpath( $base );
        $path_real = realpath( $path );
        if ( ! $base_real || ! $path_real || 0 !== strpos( $path_real, trailingslashit( $base_real ) ) || ! is_file( $path_real ) ) {
            wp_die( esc_html__( 'Backup file is missing or invalid.', 'productshift-backup-migration' ), '', array( 'response' => 404 ) );
        }

        nocache_headers();
        header( 'Content-Type: application/zip' );
        header( 'X-Content-Type-Options: nosniff' );
        header( 'Content-Disposition: attachment; filename="' . sanitize_file_name( $entry['filename'] ) . '"' );
        header( 'Content-Length: ' . filesize( $path_real ) );
        $this->stream_file( $path_real );
        exit;
    }

    public function delete_backup() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( esc_html__( 'You do not have permission to delete backups.', 'productshift-backup-migration' ) );
        }
        $id = isset( $_GET['backup_id'] ) ? sanitize_text_field( wp_unslash( $_GET['backup_id'] ) ) : '';
        check_admin_referer( 'sbop_backup_action_' . $id );
        $rollback_meta = get_option( 'sbop_last_restore_rollback', array() );
        $protected_rollback_id = is_array( $rollback_meta ) && ! empty( $rollback_meta['rollback_backup_id'] ) ? (string) $rollback_meta['rollback_backup_id'] : '';
        if ( $protected_rollback_id && hash_equals( $protected_rollback_id, (string) $id ) ) {
            wp_die( esc_html__( 'This backup is the active recovery point. Use Recovery Center or complete another restore before deleting it.', 'productshift-backup-migration' ) );
        }
        $history = $this->backup_history();
        $new = array();
        foreach ( $history as $entry ) {
            if ( isset( $entry['id'] ) && hash_equals( (string) $entry['id'], (string) $id ) ) {
                if ( ! empty( $entry['file'] ) ) {
                    $path = trailingslashit( $this->backup_base() ) . wp_basename( $entry['file'] );
                    if ( file_exists( $path ) ) {
                        wp_delete_file( $path );
                    }
                }
                continue;
            }
            $new[] = $entry;
        }
        $this->save_backup_history( $new );
        $this->log_activity( 'delete', 'A saved backup was deleted.', array( 'backup_id' => $id ) );
        wp_safe_redirect( wp_nonce_url( admin_url( 'admin.php?page=sbop-backup-history&sbop_deleted=1' ), 'sbop_admin_notice' ) );
        exit;
    }

    public function save_settings() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( esc_html__( 'You do not have permission to change backup settings.', 'productshift-backup-migration' ) );
        }
        check_admin_referer( 'sbop_save_settings' );
        $section = isset( $_POST['settings_section'] ) ? sanitize_key( wp_unslash( $_POST['settings_section'] ) ) : 'automation';

        if ( 'general' === $section ) {
            update_option( 'sbop_delete_data_on_uninstall', ! empty( $_POST['delete_data_on_uninstall'] ), false );
            update_option( 'sbop_email_notifications', ! empty( $_POST['email_notifications'] ), false );
            $notification_email = isset( $_POST['notification_email'] ) ? sanitize_email( wp_unslash( $_POST['notification_email'] ) ) : sanitize_email( get_option( 'admin_email' ) );
            update_option( 'sbop_notification_email', $notification_email ? $notification_email : sanitize_email( get_option( 'admin_email' ) ), false );
            $this->log_activity( 'settings', 'Plugin notification and data settings were updated.' );
            $redirect_page = 'sbop-backup-settings';
        } elseif ( 'remote' === $section ) {
            $remote_provider = isset( $_POST['remote_provider'] ) ? sanitize_key( wp_unslash( $_POST['remote_provider'] ) ) : 'webdav';
            if ( ! in_array( $remote_provider, array( 'webdav', 'hook' ), true ) ) {
                $remote_provider = 'webdav';
            }
            update_option( 'sbop_remote_enabled', ! empty( $_POST['remote_enabled'] ), false );
            update_option( 'sbop_remote_provider', $remote_provider, false );
            update_option( 'sbop_remote_url', isset( $_POST['remote_url'] ) ? esc_url_raw( wp_unslash( $_POST['remote_url'] ) ) : '', false );
            update_option( 'sbop_remote_user', isset( $_POST['remote_user'] ) ? sanitize_text_field( wp_unslash( $_POST['remote_user'] ) ) : '', false );
            $remote_password = isset( $_POST['remote_password'] ) ? sanitize_text_field( wp_unslash( $_POST['remote_password'] ) ) : '';
            if ( '' !== $remote_password ) {
                update_option( 'sbop_remote_password', $remote_password, false );
            }
            $this->log_activity( 'settings', 'Remote storage settings were updated.' );
            $redirect_page = 'sbop-backup-settings';
        } else {
            $schedule = isset( $_POST['schedule'] ) ? sanitize_key( wp_unslash( $_POST['schedule'] ) ) : 'off';
            if ( ! in_array( $schedule, array( 'off', 'daily', 'sbop_weekly' ), true ) ) {
                $schedule = 'off';
            }
            $retention = isset( $_POST['retention'] ) ? max( 1, min( 25, absint( $_POST['retention'] ) ) ) : 5;
            $scheduled_limit = isset( $_POST['scheduled_limit'] ) ? max( 1, min( 5000, absint( $_POST['scheduled_limit'] ) ) ) : 5000;
            update_option( 'sbop_schedule', $schedule, false );
            update_option( 'sbop_retention', $retention, false );
            update_option( 'sbop_scheduled_limit', $scheduled_limit, false );
            wp_clear_scheduled_hook( 'sbop_scheduled_backup' );
            if ( 'off' !== $schedule ) {
                wp_schedule_event( time() + 5 * MINUTE_IN_SECONDS, $schedule, 'sbop_scheduled_backup' );
            }
            $this->log_activity( 'settings', 'Backup automation settings were updated.' );
            $redirect_page = 'sbop-backup-automation';
        }

        wp_safe_redirect( wp_nonce_url( admin_url( 'admin.php?page=' . $redirect_page . '&sbop_settings_saved=1' ), 'sbop_admin_notice' ) );
        exit;
    }

    public function run_scheduled_backup() {
        if ( ! class_exists( 'WooCommerce' ) || ! class_exists( 'ZipArchive' ) ) {
            return;
        }
        $limit = max( 1, min( 5000, (int) get_option( 'sbop_scheduled_limit', 5000 ) ) );
        $result = $this->create_products_archive( $limit, true );
        if ( is_wp_error( $result ) ) {
            update_option( 'sbop_last_schedule_error', $result->get_error_message(), false );
            $this->log_activity( 'error', 'Scheduled backup failed: ' . $result->get_error_message() );
            $this->maybe_send_admin_email( 'ProductShift – Backup & Migration for WooCommerce: scheduled backup failed', 'Scheduled backup failed: ' . $result->get_error_message() );
            return;
        }
        $backup_id = $this->register_backup( $result['path'], $result['filename'], 'scheduled-products', $result['manifest'] );
        if ( file_exists( $result['path'] ) ) {
            wp_delete_file( $result['path'] );
        }
        if ( ! $backup_id ) {
            $message = 'Scheduled backup ZIP was created but could not be saved in protected backup storage.';
            update_option( 'sbop_last_schedule_error', $message, false );
            $this->log_activity( 'error', $message );
            $this->maybe_send_admin_email( 'ProductShift – Backup & Migration for WooCommerce: scheduled backup failed', $message );
            return;
        }
        update_option( 'sbop_incremental_anchor', isset( $result['anchor_to'] ) ? (int) $result['anchor_to'] : time(), false );
        update_option( 'sbop_last_schedule_run', time(), false );
        delete_option( 'sbop_last_schedule_error' );
        $this->log_activity( 'schedule', 'Scheduled product backup completed successfully.' );
        $this->maybe_send_admin_email( 'ProductShift – Backup & Migration for WooCommerce: scheduled backup complete', 'Your scheduled product backup completed successfully.' );
    }

    private function local_download_path( $file ) {
        $file = (string) $file;
        if ( '' === $file ) {
            return '';
        }
        $uploads = wp_upload_dir();
        $basedir = wp_normalize_path( $uploads['basedir'] );
        $candidate = '';
        if ( 0 === strpos( $file, $uploads['baseurl'] ) ) {
            $candidate = $uploads['basedir'] . substr( $file, strlen( $uploads['baseurl'] ) );
        } elseif ( file_exists( $file ) ) {
            $candidate = $file;
        }
        if ( ! $candidate || ! file_exists( $candidate ) || ! is_file( $candidate ) ) {
            return '';
        }
        $real = wp_normalize_path( realpath( $candidate ) );
        if ( ! $real || 0 !== strpos( $real, trailingslashit( $basedir ) ) ) {
            return '';
        }
        return $real;
    }

    private function package_downloads_for_record( &$record, ZipArchive $zip, &$added_downloads ) {
        if ( empty( $record['downloads'] ) || empty( $record['old_id'] ) ) {
            return;
        }
        foreach ( $record['downloads'] as $index => $download ) {
            if ( empty( $download['file'] ) ) {
                continue;
            }
            $local = $this->local_download_path( $download['file'] );
            if ( ! $local ) {
                continue;
            }
            $hash = function_exists( 'hash_file' ) ? hash_file( 'sha256', $local ) : md5( $local );
            $zip_path = 'downloads/' . absint( $record['old_id'] ) . '-' . substr( $hash, 0, 12 ) . '-' . sanitize_file_name( wp_basename( $local ) );
            if ( empty( $added_downloads[ $zip_path ] ) ) {
                $this->add_file_to_zip( $zip, $local, $zip_path );
                $added_downloads[ $zip_path ] = true;
            }
            $record['downloads'][ $index ]['backup_file'] = $zip_path;
            $record['downloads'][ $index ]['filename'] = wp_basename( $local );
            $record['downloads'][ $index ]['sha256'] = function_exists( 'hash_file' ) ? hash_file( 'sha256', $local ) : '';
        }
    }

    private function create_products_archive_from_ids( $ids, $include_images = true, $variant = 'full', $extra_manifest = array() ) {
        $ids = array_values( array_filter( array_map( 'absint', (array) $ids ) ) );
        $ids = array_slice( $ids, 0, 5000 );
        $variant = sanitize_key( $variant );
        $dir = $this->temp_dir( 'products' );
        $zip_path = $dir . '.zip';
        $zip = new ZipArchive();
        if ( true !== $zip->open( $zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) {
            $this->cleanup_dir( $dir );
            return new WP_Error( 'zip_create_failed', __( 'Could not create ZIP archive.', 'productshift-backup-migration' ) );
        }
        $products = array();
        $variations = array();
        $media = array();
        $added_media = array();
        $added_downloads = array();
        foreach ( $ids as $id ) {
            $product = wc_get_product( $id );
            if ( ! $product || is_a( $product, 'WC_Product_Variation' ) ) {
                continue;
            }
            $product_record = $this->product_record( $product );
            $this->package_downloads_for_record( $product_record, $zip, $added_downloads );
            $products[] = $product_record;
            if ( $include_images ) {
                $content_media_ids = wp_list_pluck( (array) $product_record['content_media'], 'old_id' );
                $category_media_ids = $this->collect_term_media_ids( (array) $product_record['categories'] );
                $tag_media_ids = $this->collect_term_media_ids( (array) $product_record['tags'] );
                $shipping_media_ids = ! empty( $product_record['shipping_class'] ) ? $this->collect_term_media_ids( array( $product_record['shipping_class'] ) ) : array();
                $custom_taxonomy_media_ids = array();
                foreach ( (array) $product_record['custom_taxonomies'] as $taxonomy_records ) { $custom_taxonomy_media_ids = array_merge( $custom_taxonomy_media_ids, $this->collect_term_media_ids( $taxonomy_records ) ); }
                $attribute_media_ids = $this->collect_attribute_media_ids( (array) $product_record['attributes'] );
                $custom_meta_media_ids = array();
                foreach ( (array) $product_record['custom_meta_media_ids'] as $meta_ids ) { $custom_meta_media_ids = array_merge( $custom_meta_media_ids, (array) $meta_ids ); }
                $image_ids = array_filter( array_unique( array_merge( array( $product->get_image_id() ), $product->get_gallery_image_ids(), $content_media_ids, $category_media_ids, $tag_media_ids, $shipping_media_ids, $custom_taxonomy_media_ids, $attribute_media_ids, $custom_meta_media_ids ) ) );
                foreach ( $image_ids as $image_id ) {
                    list( $row, $added_media ) = $this->media_record( $image_id, $zip, $added_media );
                    if ( $row ) {
                        $media[ $image_id ] = $row;
                    }
                }
            }
            if ( $product->is_type( 'variable' ) ) {
                foreach ( $product->get_children() as $variation_id ) {
                    $variation = wc_get_product( $variation_id );
                    if ( $variation && is_a( $variation, 'WC_Product_Variation' ) ) {
                        $variation_record = $this->variation_record( $variation );
                        $this->package_downloads_for_record( $variation_record, $zip, $added_downloads );
                        $variations[] = $variation_record;
                        if ( $include_images ) {
                            $variation_media_ids = array( $variation->get_image_id() );
                            if ( ! empty( $variation_record['shipping_class'] ) ) { $variation_media_ids = array_merge( $variation_media_ids, $this->collect_term_media_ids( array( $variation_record['shipping_class'] ) ) ); }
                            foreach ( (array) $variation_record['custom_meta_media_ids'] as $meta_ids ) { $variation_media_ids = array_merge( $variation_media_ids, (array) $meta_ids ); }
                            foreach ( array_filter( array_unique( array_map( 'absint', $variation_media_ids ) ) ) as $variation_media_id ) {
                                list( $row, $added_media ) = $this->media_record( $variation_media_id, $zip, $added_media );
                                if ( $row ) { $media[ $variation_media_id ] = $row; }
                            }
                        }
                    }
                }
            }
        }
        $manifest = array(
            'format'              => 'sbop',
            'compatible_formats'  => array( 'sbop', 'wpmb' ),
            'format_version'      => 4,
            'plugin_version'      => self::VERSION,
            'backup_type'         => 'products',
            'archive_variant'     => $variant,
            'created_at'          => gmdate( DATE_ATOM ),
            'site_url'            => site_url(),
            'wordpress_version'   => get_bloginfo( 'version' ),
            'woocommerce_version' => defined( 'WC_VERSION' ) ? WC_VERSION : '',
            'product_count'       => count( $products ),
            'variation_count'     => count( $variations ),
            'media_count'         => count( $media ),
            'catalog_fidelity'    => true,
            'category_hierarchy'  => true,
            'embedded_media'      => true,
            'portable_downloads'  => true,
            'private_custom_meta' => true,
        );
        foreach ( (array) $extra_manifest as $key => $value ) {
            $manifest[ sanitize_key( $key ) ] = $value;
        }
        $manifest['checksum_algorithm'] = 'sha256';
        $json_files = array(
            'manifest.json'   => wp_json_encode( $manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ),
            'products.json'   => wp_json_encode( array_values( $products ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ),
            'variations.json' => wp_json_encode( array_values( $variations ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ),
            'media.json'      => wp_json_encode( array_values( $media ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ),
        );
        $checksums = array();
        foreach ( $json_files as $json_name => $json_contents ) {
            $zip->addFromString( $json_name, $json_contents );
            $checksums[ $json_name ] = hash( 'sha256', $json_contents );
        }
        $zip->addFromString( 'checksums.json', wp_json_encode( array( 'algorithm' => 'sha256', 'files' => $checksums ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
        $zip->close();
        $this->cleanup_dir( $dir );
        $prefix = 'productshift-backup-migration-products';
        if ( 'incremental' === $variant ) {
            $prefix = 'productshift-backup-migration-incremental';
        } elseif ( 'rollback' === $variant ) {
            $prefix = 'productshift-backup-migration-rollback';
        }
        return array(
            'path'     => $zip_path,
            'filename' => $prefix . '-' . gmdate( 'Y-m-d-His' ) . '.zip',
            'manifest' => $manifest,
        );
    }

    private function create_products_archive( $limit, $include_images = true ) {
        $limit = max( 1, min( 5000, absint( $limit ) ) );
        $anchor_to = time();
        $ids = wc_get_products( array(
            'limit'   => $limit,
            'return'  => 'ids',
            'status'  => array( 'publish', 'draft', 'pending', 'private' ),
            'orderby' => 'ID',
            'order'   => 'ASC',
        ) );
        $result = $this->create_products_archive_from_ids( $ids, $include_images, 'full', array( 'incremental_to' => gmdate( DATE_ATOM, $anchor_to ) ) );
        if ( ! is_wp_error( $result ) ) {
            $result['anchor_to'] = $anchor_to;
        }
        return $result;
    }

    private function create_incremental_archive( $limit, $include_images = true ) {
        $limit = max( 1, min( 5000, absint( $limit ) ) );
        $anchor_from = (int) get_option( 'sbop_incremental_anchor', 0 );
        $anchor_to = time();
        $args = array(
            'post_type'      => 'product',
            'post_status'    => array( 'publish', 'draft', 'pending', 'private' ),
            'posts_per_page' => $limit,
            'fields'         => 'ids',
            'orderby'        => 'modified',
            'order'          => 'ASC',
            'no_found_rows'  => true,
        );
        if ( $anchor_from > 0 ) {
            $args['date_query'] = array(
                array(
                    'column'    => 'post_modified_gmt',
                    'after'     => gmdate( 'Y-m-d H:i:s', $anchor_from ),
                    'before'    => gmdate( 'Y-m-d H:i:s', $anchor_to ),
                    'inclusive' => false,
                ),
            );
        }
        $ids = get_posts( $args );
        $result = $this->create_products_archive_from_ids(
            $ids,
            $include_images,
            'incremental',
            array(
                'incremental_from' => $anchor_from ? gmdate( DATE_ATOM, $anchor_from ) : null,
                'incremental_to'   => gmdate( DATE_ATOM, $anchor_to ),
                'baseline_created' => 0 === $anchor_from,
            )
        );
        if ( ! is_wp_error( $result ) ) {
            $result['anchor_to'] = $anchor_to;
        }
        return $result;
    }

    private function create_media_archive( $limit ) {
        $limit = max( 1, min( 10000, absint( $limit ) ) );
        $dir = $this->temp_dir( 'media' );
        $zip_path = $dir . '.zip';
        $zip = new ZipArchive();
        if ( true !== $zip->open( $zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) {
            $this->cleanup_dir( $dir );
            return new WP_Error( 'zip_create_failed', __( 'Could not create ZIP archive.', 'productshift-backup-migration' ) );
        }
        $ids = get_posts( array( 'post_type' => 'attachment', 'post_status' => 'inherit', 'posts_per_page' => $limit, 'fields' => 'ids', 'orderby' => 'ID', 'order' => 'ASC' ) );
        $media = array();
        $added = array();
        foreach ( $ids as $id ) {
            list( $row, $added ) = $this->media_record( $id, $zip, $added );
            if ( $row ) {
                $media[] = $row;
            }
        }
        $manifest = array(
            'format'            => 'sbop',
            'compatible_formats'=> array( 'sbop', 'wpmb' ),
            'format_version'    => 2,
            'plugin_version'    => self::VERSION,
            'backup_type'       => 'media',
            'created_at'        => gmdate( DATE_ATOM ),
            'site_url'          => site_url(),
            'wordpress_version' => get_bloginfo( 'version' ),
            'media_count'       => count( $media ),
        );
        $manifest['checksum_algorithm'] = 'sha256';
        $json_files = array(
            'manifest.json' => wp_json_encode( $manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ),
            'media.json'    => wp_json_encode( $media, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ),
        );
        $checksums = array();
        foreach ( $json_files as $json_name => $json_contents ) {
            $zip->addFromString( $json_name, $json_contents );
            $checksums[ $json_name ] = hash( 'sha256', $json_contents );
        }
        $zip->addFromString( 'checksums.json', wp_json_encode( array( 'algorithm' => 'sha256', 'files' => $checksums ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
        $zip->close();
        $this->cleanup_dir( $dir );
        return array(
            'path'     => $zip_path,
            'filename' => 'productshift-backup-migration-media-' . gmdate( 'Y-m-d-His' ) . '.zip',
            'manifest' => $manifest,
        );
    }

    private function temp_base() {
        $uploads = wp_upload_dir();
        $base = trailingslashit( $uploads['basedir'] ) . 'sbop-temp';
        wp_mkdir_p( $base );
        $filesystem = $this->filesystem();
        if ( ! file_exists( trailingslashit( $base ) . 'index.php' ) ) {
            $filesystem->put_contents( trailingslashit( $base ) . 'index.php', "<?php\n// Silence is golden.\n", FS_CHMOD_FILE );
        }
        if ( ! file_exists( trailingslashit( $base ) . '.htaccess' ) ) {
            $filesystem->put_contents( trailingslashit( $base ) . '.htaccess', "Deny from all\n", FS_CHMOD_FILE );
        }
        if ( ! file_exists( trailingslashit( $base ) . 'web.config' ) ) {
            $filesystem->put_contents( trailingslashit( $base ) . 'web.config', '<configuration><system.webServer><authorization><deny users="*" /></authorization></system.webServer></configuration>', FS_CHMOD_FILE );
        }
        return $base;
    }

    private function temp_dir( $prefix ) {
        $base = $this->temp_base();
        $dir = trailingslashit( $base ) . sanitize_file_name( $prefix . '-' . wp_generate_uuid4() );
        wp_mkdir_p( $dir );
        return $dir;
    }

    private function job_dir( $job_id ) {
        $job_id = preg_replace( '/[^a-zA-Z0-9_-]/', '', (string) $job_id );
        return trailingslashit( $this->temp_base() ) . 'restore-' . $job_id;
    }

    private function job_state_path( $job_id ) {
        return trailingslashit( $this->job_dir( $job_id ) ) . 'state.json';
    }

    private function cancel_key( $job_id ) {
        return 'sbop_cancel_' . md5( (string) $job_id );
    }

    private function mark_job_cancelled( $job_id ) {
        set_transient( $this->cancel_key( $job_id ), 1, 10 * MINUTE_IN_SECONDS );
    }

    private function is_job_cancelled( $job_id ) {
        return (bool) get_transient( $this->cancel_key( $job_id ) );
    }

    private function clear_job_cancelled( $job_id ) {
        delete_transient( $this->cancel_key( $job_id ) );
    }

    private function cleanup_dir( $dir ) {
        if ( ! is_dir( $dir ) ) {
            return;
        }
        $filesystem = $this->filesystem();
        $filesystem->delete( $dir, true, 'd' );
    }

    private function send_zip( $path, $filename ) {
        if ( ! file_exists( $path ) ) {
            wp_die( 'Backup file could not be created.' );
        }
        nocache_headers();
        header( 'Content-Type: application/zip' );
        header( 'Content-Disposition: attachment; filename="' . sanitize_file_name( $filename ) . '"' );
        header( 'Content-Length: ' . filesize( $path ) );
        $this->stream_file( $path );
        wp_delete_file( $path );
        exit;
    }

    private function add_file_to_zip( ZipArchive $zip, $absolute_path, $zip_path ) {
        if ( $absolute_path && file_exists( $absolute_path ) && is_file( $absolute_path ) ) {
            $zip->addFile( $absolute_path, ltrim( str_replace( '\\', '/', $zip_path ), '/' ) );
            return true;
        }
        return false;
    }

    private function attachment_custom_meta( $attachment_id ) {
        $all = get_post_meta( $attachment_id );
        $skip = array( '_wp_attached_file', '_wp_attachment_metadata', '_wp_attachment_image_alt', '_wp_attachment_backup_sizes', '_edit_lock', '_edit_last' );
        $out = array();
        foreach ( (array) $all as $key => $values ) {
            if ( in_array( $key, $skip, true ) ) {
                continue;
            }
            $safe_key = preg_replace( '/[^A-Za-z0-9_\-:.]/', '', (string) $key );
            if ( '' === $safe_key ) {
                continue;
            }
            $out[ $safe_key ] = array_map( 'maybe_unserialize', (array) $values );
        }
        return $out;
    }

    private function media_record( $attachment_id, $zip = null, $added = array() ) {
        $file = get_attached_file( $attachment_id );
        if ( ! $file || ! file_exists( $file ) ) {
            return array( null, $added );
        }
        $basename = wp_basename( $file );
        $zip_path = 'media/' . $attachment_id . '-' . $basename;
        if ( $zip instanceof ZipArchive && empty( $added[ $attachment_id ] ) ) {
            $this->add_file_to_zip( $zip, $file, $zip_path );
            $added[ $attachment_id ] = true;
        }
        $post = get_post( $attachment_id );
        $record = array(
            'old_id'      => (int) $attachment_id,
            'file'        => $zip_path,
            'filename'    => $basename,
            'source_url'  => wp_get_attachment_url( $attachment_id ),
            'mime_type'   => get_post_mime_type( $attachment_id ),
            'title'       => $post ? $post->post_title : '',
            'caption'     => $post ? $post->post_excerpt : '',
            'description' => $post ? $post->post_content : '',
            'parent_old_id'=> $post ? (int) $post->post_parent : 0,
            'post_date'    => $post ? $post->post_date : '',
            'post_date_gmt'=> $post ? $post->post_date_gmt : '',
            'alt'         => (string) get_post_meta( $attachment_id, '_wp_attachment_image_alt', true ),
            'custom_meta' => $this->attachment_custom_meta( $attachment_id ),
            'metadata'    => wp_get_attachment_metadata( $attachment_id ),
            'sha256'      => function_exists( 'hash_file' ) ? hash_file( 'sha256', $file ) : '',
        );
        return array( $record, $added );
    }

    private function attachment_ids_from_mixed( $value ) {
        $ids = array();
        if ( is_array( $value ) ) {
            foreach ( $value as $child ) { $ids = array_merge( $ids, $this->attachment_ids_from_mixed( $child ) ); }
        } elseif ( is_object( $value ) ) {
            $ids = array_merge( $ids, $this->attachment_ids_from_mixed( get_object_vars( $value ) ) );
        } elseif ( is_numeric( $value ) ) {
            $candidate = absint( $value );
            if ( $candidate && 'attachment' === get_post_type( $candidate ) ) { $ids[] = $candidate; }
        } elseif ( is_string( $value ) ) {
            $trimmed = trim( $value );
            if ( filter_var( $trimmed, FILTER_VALIDATE_URL ) ) {
                $candidate = attachment_url_to_postid( $trimmed );
                if ( $candidate ) { $ids[] = (int) $candidate; }
            } elseif ( preg_match( '/^\s*\d+(?:\s*,\s*\d+)+\s*$/', $trimmed ) ) {
                foreach ( preg_split( '/\s*,\s*/', $trimmed ) as $part ) {
                    $candidate = absint( $part );
                    if ( $candidate && 'attachment' === get_post_type( $candidate ) ) { $ids[] = $candidate; }
                }
            }
        }
        return array_values( array_unique( array_filter( $ids ) ) );
    }

    private function meta_media_id_map( $meta ) {
        $map = array();
        foreach ( (array) $meta as $key => $values ) {
            $ids = $this->attachment_ids_from_mixed( $values );
            if ( ! empty( $ids ) ) { $map[ (string) $key ] = $ids; }
        }
        return $map;
    }

    private function term_meta_snapshot( $term_id ) {
        $all = get_term_meta( $term_id );
        $out = array();
        foreach ( (array) $all as $key => $values ) {
            if ( 'thumbnail_id' === $key ) {
                continue;
            }
            $safe_key = preg_replace( '/[^A-Za-z0-9_\-:.]/', '', (string) $key );
            if ( '' === $safe_key ) {
                continue;
            }
            $out[ $safe_key ] = array_map( 'maybe_unserialize', (array) $values );
        }
        return $out;
    }

    private function term_record( $term, $taxonomy, $with_ancestors = true ) {
        if ( ! $term || is_wp_error( $term ) ) {
            return null;
        }
        $term_meta = $this->term_meta_snapshot( $term->term_id );
        $record = array(
            'old_id'         => (int) $term->term_id,
            'name'           => $term->name,
            'slug'           => $term->slug,
            'description'    => $term->description,
            'parent_slug'    => '',
            'meta'           => $term_meta,
            'meta_media_ids' => $this->meta_media_id_map( $term_meta ),
        );
        if ( $term->parent ) {
            $parent = get_term( (int) $term->parent, $taxonomy );
            if ( $parent && ! is_wp_error( $parent ) ) {
                $record['parent_slug'] = $parent->slug;
            }
        }
        if ( 'product_cat' === $taxonomy ) {
            $record['thumbnail_id'] = (int) get_term_meta( $term->term_id, 'thumbnail_id', true );
        }
        if ( $with_ancestors && is_taxonomy_hierarchical( $taxonomy ) ) {
            $ancestor_ids = array_reverse( get_ancestors( $term->term_id, $taxonomy, 'taxonomy' ) );
            $record['ancestors'] = array();
            foreach ( $ancestor_ids as $ancestor_id ) {
                $ancestor = get_term( (int) $ancestor_id, $taxonomy );
                $ancestor_record = $this->term_record( $ancestor, $taxonomy, false );
                if ( $ancestor_record ) {
                    $record['ancestors'][] = $ancestor_record;
                }
            }
        }
        return $record;
    }

    private function term_records( $product_id, $taxonomy ) {
        $terms = wp_get_post_terms( $product_id, $taxonomy );
        if ( is_wp_error( $terms ) ) {
            return array();
        }
        $out = array();
        foreach ( $terms as $term ) {
            $record = $this->term_record( $term, $taxonomy, true );
            if ( $record ) {
                $out[] = $record;
            }
        }
        return $out;
    }

    private function collect_term_media_ids( $records ) {
        $ids = array();
        foreach ( (array) $records as $record ) {
            if ( ! empty( $record['thumbnail_id'] ) ) {
                $ids[] = absint( $record['thumbnail_id'] );
            }
            foreach ( (array) ( isset( $record['meta_media_ids'] ) ? $record['meta_media_ids'] : array() ) as $meta_ids ) {
                $ids = array_merge( $ids, array_map( 'absint', (array) $meta_ids ) );
            }
            if ( ! empty( $record['ancestors'] ) ) {
                $ids = array_merge( $ids, $this->collect_term_media_ids( $record['ancestors'] ) );
            }
        }
        return array_values( array_unique( array_filter( $ids ) ) );
    }

    private function content_attachment_records( $html ) {
        $ids = array();
        $html = (string) $html;
        if ( '' === $html ) {
            return array();
        }
        if ( preg_match_all( '/wp-image-(\d+)/i', $html, $matches ) ) {
            $ids = array_merge( $ids, array_map( 'absint', $matches[1] ) );
        }
        if ( preg_match_all( '/"id"\s*:\s*(\d+)/', $html, $matches ) ) {
            foreach ( $matches[1] as $candidate ) { if ( 'attachment' === get_post_type( absint( $candidate ) ) ) { $ids[] = absint( $candidate ); } }
        }
        if ( preg_match_all( '/"ids"\s*:\s*\[([^\]]+)\]/', $html, $matches ) ) {
            foreach ( $matches[1] as $list ) { foreach ( preg_split( '/\s*,\s*/', trim( $list ) ) as $candidate ) { if ( 'attachment' === get_post_type( absint( $candidate ) ) ) { $ids[] = absint( $candidate ); } } }
        }
        if ( preg_match_all( '/\bids\s*=\s*["\']([0-9,\s]+)["\']/i', $html, $matches ) ) {
            foreach ( $matches[1] as $list ) { foreach ( preg_split( '/\s*,\s*/', trim( $list ) ) as $candidate ) { if ( 'attachment' === get_post_type( absint( $candidate ) ) ) { $ids[] = absint( $candidate ); } } }
        }
        if ( preg_match_all( "/<img[^>]+src=[\"']([^\"']+)[\"']/i", $html, $matches ) ) {
            foreach ( $matches[1] as $url ) {
                $attachment_id = attachment_url_to_postid( html_entity_decode( $url ) );
                if ( $attachment_id ) {
                    $ids[] = (int) $attachment_id;
                }
            }
        }
        $records = array();
        foreach ( array_values( array_unique( array_filter( $ids ) ) ) as $attachment_id ) {
            $url = wp_get_attachment_url( $attachment_id );
            if ( ! $url ) {
                continue;
            }
            $sizes = array();
            $metadata = wp_get_attachment_metadata( $attachment_id );
            foreach ( array_keys( isset( $metadata['sizes'] ) && is_array( $metadata['sizes'] ) ? $metadata['sizes'] : array() ) as $size_name ) {
                $size_url = wp_get_attachment_image_url( $attachment_id, $size_name );
                if ( $size_url ) {
                    $sizes[ $size_name ] = $size_url;
                }
            }
            $records[] = array( 'old_id' => (int) $attachment_id, 'url' => $url, 'sizes' => $sizes );
        }
        return $records;
    }

    private function product_content_media( $product ) {
        $records = array();
        foreach ( array( $product->get_description(), $product->get_short_description() ) as $content ) {
            foreach ( $this->content_attachment_records( $content ) as $record ) {
                $records[ (int) $record['old_id'] ] = $record;
            }
        }
        return array_values( $records );
    }

    private function collect_attribute_media_ids( $attributes ) {
        $ids = array();
        foreach ( (array) $attributes as $attribute ) {
            if ( ! empty( $attribute['option_records'] ) ) {
                $ids = array_merge( $ids, $this->collect_term_media_ids( $attribute['option_records'] ) );
            }
        }
        return array_values( array_unique( array_filter( array_map( 'absint', $ids ) ) ) );
    }

    private function attribute_definition_record( $attribute ) {
        if ( ! $attribute->is_taxonomy() || ! function_exists( 'wc_get_attribute' ) ) {
            return array();
        }
        $definition = wc_get_attribute( $attribute->get_id() );
        if ( ! $definition ) {
            return array();
        }
        return array(
            'id'           => (int) $definition->id,
            'name'         => (string) $definition->name,
            'slug'         => (string) $definition->slug,
            'type'         => (string) $definition->type,
            'order_by'     => (string) $definition->order_by,
            'has_archives' => (bool) $definition->has_archives,
        );
    }

    private function serialize_attribute( $attribute ) {
        if ( ! is_a( $attribute, 'WC_Product_Attribute' ) ) {
            return null;
        }
        $options = $attribute->get_options();
        $option_records = array();
        if ( $attribute->is_taxonomy() ) {
            $names = array();
            foreach ( $options as $term_id ) {
                $term = get_term( $term_id, $attribute->get_name() );
                if ( $term && ! is_wp_error( $term ) ) {
                    $names[] = $term->name;
                    $option_record = $this->term_record( $term, $attribute->get_name(), true );
                    if ( $option_record ) {
                        $option_records[] = $option_record;
                    }
                } else {
                    $names[] = (string) $term_id;
                }
            }
            $options = $names;
        }
        return array(
            'name'                => $attribute->get_name(),
            'options'             => array_values( $options ),
            'option_records'      => $option_records,
            'visible'             => (bool) $attribute->get_visible(),
            'variation'           => (bool) $attribute->get_variation(),
            'taxonomy'            => (bool) $attribute->is_taxonomy(),
            'position'            => (int) $attribute->get_position(),
            'taxonomy_definition' => $this->attribute_definition_record( $attribute ),
        );
    }

    private function is_reserved_product_meta_key( $key ) {
        $reserved = array(
            '_sku', '_regular_price', '_sale_price', '_sale_price_dates_from', '_sale_price_dates_to', '_price',
            '_manage_stock', '_stock', '_stock_status', '_backorders', '_sold_individually', '_low_stock_amount',
            '_weight', '_length', '_width', '_height', '_virtual', '_downloadable', '_downloadable_files',
            '_download_limit', '_download_expiry', '_tax_status', '_tax_class', '_product_image_gallery', '_thumbnail_id',
            '_product_attributes', '_default_attributes', '_upsell_ids', '_crosssell_ids', '_children', '_product_url',
            '_button_text', '_purchase_note', '_wc_average_rating', '_wc_review_count', '_wc_rating_count',
            '_visibility', '_featured', '_product_version', '_edit_lock', '_edit_last', '_wp_old_slug',
        );
        return in_array( (string) $key, $reserved, true );
    }

    private function public_custom_meta( $post_id ) {
        $all = get_post_meta( $post_id );
        $out = array();
        foreach ( $all as $key => $values ) {
            if ( $this->is_reserved_product_meta_key( $key ) ) {
                continue;
            }
            $safe_key = preg_replace( '/[^A-Za-z0-9_\-:.]/', '', (string) $key );
            if ( '' === $safe_key ) {
                continue;
            }
            $out[ $safe_key ] = array_map( 'maybe_unserialize', $values );
        }
        return $out;
    }

    private function product_custom_taxonomies( $product_id ) {
        $out = array();
        $skip = array( 'product_cat', 'product_tag', 'product_type', 'product_visibility', 'product_shipping_class' );
        foreach ( get_object_taxonomies( 'product', 'names' ) as $taxonomy ) {
            if ( in_array( $taxonomy, $skip, true ) || 0 === strpos( $taxonomy, 'pa_' ) ) {
                continue;
            }
            $records = $this->term_records( $product_id, $taxonomy );
            if ( ! empty( $records ) ) {
                $out[ $taxonomy ] = $records;
            }
        }
        return $out;
    }

    private function product_record( $product ) {
        $attrs = array();
        foreach ( $product->get_attributes() as $attribute ) {
            $row = $this->serialize_attribute( $attribute );
            if ( $row ) {
                $attrs[] = $row;
            }
        }
        $custom_meta = $this->public_custom_meta( $product->get_id() );
        return array(
            'old_id'              => $product->get_id(),
            'type'                => $product->get_type(),
            'name'                => $product->get_name(),
            'slug'                => $product->get_slug(),
            'status'              => $product->get_status(),
            'featured'            => $product->get_featured(),
            'catalog_visibility'  => $product->get_catalog_visibility(),
            'description'         => $product->get_description(),
            'short_description'   => $product->get_short_description(),
            'sku'                 => $product->get_sku(),
            'regular_price'       => $product->get_regular_price(),
            'sale_price'          => $product->get_sale_price(),
            'date_on_sale_from'   => $product->get_date_on_sale_from() ? $product->get_date_on_sale_from()->date( DATE_ATOM ) : null,
            'date_on_sale_to'     => $product->get_date_on_sale_to() ? $product->get_date_on_sale_to()->date( DATE_ATOM ) : null,
            'virtual'             => $product->get_virtual(),
            'downloadable'        => $product->get_downloadable(),
            'downloads'           => array_map( function( $download ) {
                return array( 'id' => $download->get_id(), 'name' => $download->get_name(), 'file' => $download->get_file() );
            }, $product->get_downloads() ),
            'download_limit'      => $product->get_download_limit(),
            'download_expiry'     => $product->get_download_expiry(),
            'tax_status'          => $product->get_tax_status(),
            'tax_class'           => $product->get_tax_class(),
            'manage_stock'        => $product->get_manage_stock(),
            'stock_quantity'      => $product->get_stock_quantity(),
            'stock_status'        => $product->get_stock_status(),
            'backorders'          => $product->get_backorders(),
            'sold_individually'   => $product->get_sold_individually(),
            'weight'              => $product->get_weight(),
            'length'              => $product->get_length(),
            'width'               => $product->get_width(),
            'height'              => $product->get_height(),
            'reviews_allowed'     => $product->get_reviews_allowed(),
            'purchase_note'       => $product->get_purchase_note(),
            'menu_order'          => $product->get_menu_order(),
            'date_created'        => $product->get_date_created() ? $product->get_date_created()->date( DATE_ATOM ) : null,
            'date_modified'       => $product->get_date_modified() ? $product->get_date_modified()->date( DATE_ATOM ) : null,
            'low_stock_amount'    => method_exists( $product, 'get_low_stock_amount' ) ? $product->get_low_stock_amount() : '',
            'shipping_class'      => ( $shipping_terms = $this->term_records( $product->get_id(), 'product_shipping_class' ) ) ? reset( $shipping_terms ) : null,
            'external_url'        => is_a( $product, 'WC_Product_External' ) ? $product->get_product_url() : '',
            'button_text'         => is_a( $product, 'WC_Product_External' ) ? $product->get_button_text() : '',
            'grouped_children'    => is_a( $product, 'WC_Product_Grouped' ) ? array_map( 'intval', $product->get_children() ) : array(),
            'image_id'            => $product->get_image_id(),
            'gallery_image_ids'   => $product->get_gallery_image_ids(),
            'categories'          => $this->term_records( $product->get_id(), 'product_cat' ),
            'tags'                => $this->term_records( $product->get_id(), 'product_tag' ),
            'custom_taxonomies'   => $this->product_custom_taxonomies( $product->get_id() ),
            'attributes'          => $attrs,
            'default_attributes'  => method_exists( $product, 'get_default_attributes' ) ? $product->get_default_attributes() : array(),
            'upsell_ids'          => $product->get_upsell_ids(),
            'cross_sell_ids'      => $product->get_cross_sell_ids(),
            'content_media'       => $this->product_content_media( $product ),
            'custom_meta'         => $custom_meta,
            'custom_meta_media_ids'=> $this->meta_media_id_map( $custom_meta ),
        );
    }

    private function variation_record( $variation ) {
        $custom_meta = $this->public_custom_meta( $variation->get_id() );
        return array(
            'old_id'            => $variation->get_id(),
            'parent_old_id'     => $variation->get_parent_id(),
            'status'            => $variation->get_status(),
            'sku'               => $variation->get_sku(),
            'regular_price'     => $variation->get_regular_price(),
            'sale_price'        => $variation->get_sale_price(),
            'date_on_sale_from' => $variation->get_date_on_sale_from() ? $variation->get_date_on_sale_from()->date( DATE_ATOM ) : null,
            'date_on_sale_to'   => $variation->get_date_on_sale_to() ? $variation->get_date_on_sale_to()->date( DATE_ATOM ) : null,
            'tax_class'         => $variation->get_tax_class(),
            'low_stock_amount'  => method_exists( $variation, 'get_low_stock_amount' ) ? $variation->get_low_stock_amount() : '',
            'manage_stock'      => $variation->get_manage_stock(),
            'stock_quantity'    => $variation->get_stock_quantity(),
            'stock_status'      => $variation->get_stock_status(),
            'backorders'        => $variation->get_backorders(),
            'weight'            => $variation->get_weight(),
            'length'            => $variation->get_length(),
            'width'             => $variation->get_width(),
            'height'            => $variation->get_height(),
            'virtual'           => $variation->get_virtual(),
            'downloadable'      => $variation->get_downloadable(),
            'downloads'         => array_map( function( $download ) {
                return array( 'id' => $download->get_id(), 'name' => $download->get_name(), 'file' => $download->get_file() );
            }, $variation->get_downloads() ),
            'download_limit'    => $variation->get_download_limit(),
            'download_expiry'   => $variation->get_download_expiry(),
            'shipping_class'    => ( $variation_shipping = $this->term_records( $variation->get_id(), 'product_shipping_class' ) ) ? reset( $variation_shipping ) : null,
            'menu_order'        => $variation->get_menu_order(),
            'attributes'        => $variation->get_attributes(),
            'image_id'          => $variation->get_image_id(),
            'description'       => $variation->get_description(),
            'custom_meta'       => $custom_meta,
            'custom_meta_media_ids' => $this->meta_media_id_map( $custom_meta ),
        );
    }

    private function backup_job_dir( $job_id ) {
        $job_id = preg_replace( '/[^a-zA-Z0-9_-]/', '', (string) $job_id );
        return trailingslashit( $this->temp_base() ) . 'backup-' . $job_id;
    }

    private function backup_job_state_path( $job_id ) {
        return trailingslashit( $this->backup_job_dir( $job_id ) ) . 'state.json';
    }

    private function save_backup_job_state( $state ) {
        if ( empty( $state['job_id'] ) ) {
            return false;
        }
        $path = $this->backup_job_state_path( $state['job_id'] );
        $state['last_activity'] = time();
        $tmp = $path . '.tmp';
        $filesystem = $this->filesystem();
        if ( ! $filesystem->put_contents( $tmp, wp_json_encode( $state, JSON_UNESCAPED_SLASHES ), FS_CHMOD_FILE ) ) {
            return false;
        }
        if ( file_exists( $path ) ) {
            $filesystem->delete( $path, false, 'f' );
        }
        return (bool) $filesystem->move( $tmp, $path, true );
    }

    private function load_backup_job_state( $job_id ) {
        $path = $this->backup_job_state_path( $job_id );
        if ( ! file_exists( $path ) ) {
            return null;
        }
        try {
            $file = new SplFileObject( $path, 'rb' );
            $raw = '';
            while ( ! $file->eof() ) {
                $raw .= $file->fread( 1024 * 1024 );
            }
        } catch ( RuntimeException $e ) {
            return null;
        }
        $state = json_decode( $raw, true );
        return is_array( $state ) ? $state : null;
    }

    private function append_backup_ndjson( $path, $record ) {
        try {
            $file = new SplFileObject( $path, 'ab' );
            return false !== $file->fwrite( wp_json_encode( $record, JSON_UNESCAPED_SLASHES ) . "\n" );
        } catch ( RuntimeException $e ) {
            return false;
        }
    }

    private function ndjson_to_json_file( $source, $dest ) {
        try {
            $in = file_exists( $source ) ? new SplFileObject( $source, 'rb' ) : null;
            $out = new SplFileObject( $dest, 'wb' );
            $out->fwrite( "[\n" );
            $first = true;
            if ( $in ) {
                while ( ! $in->eof() ) {
                    $line = trim( (string) $in->fgets() );
                    if ( '' === $line ) {
                        continue;
                    }
                    if ( ! $first ) {
                        $out->fwrite( ",\n" );
                    }
                    $out->fwrite( $line );
                    $first = false;
                }
            }
            $out->fwrite( "\n]\n" );
            return true;
        } catch ( RuntimeException $e ) {
            return false;
        }
    }

    private function backup_job_payload( $state ) {
        $total = max( 0, (int) ( $state['total'] ?? 0 ) );
        $index = max( 0, (int) ( $state['index'] ?? 0 ) );
        $kind = sanitize_key( (string) ( $state['kind'] ?? 'products' ) );
        $complete = ! empty( $state['complete'] );
        if ( $complete ) {
            $percent = 100;
        } elseif ( $total > 0 ) {
            $percent = min( 92, max( 3, 3 + (int) floor( 89 * min( 1, $index / $total ) ) ) );
        } else {
            $percent = 92;
        }
        if ( 'media' === $kind ) {
            $label = sprintf( 'Backing up media %1$d of %2$d…', min( $index, $total ), $total );
        } elseif ( 'incremental' === $kind ) {
            $label = sprintf( 'Backing up changed products %1$d of %2$d…', min( $index, $total ), $total );
        } else {
            $label = sprintf( 'Backing up products %1$d of %2$d…', min( $index, $total ), $total );
        }
        if ( $index >= $total && ! $complete ) {
            $label = 'Finalizing, verifying and saving the ZIP…';
            $percent = 96;
        }
        return array(
            'job_id'          => (string) ( $state['job_id'] ?? '' ),
            'kind'            => $kind,
            'percent'         => $percent,
            'stage_label'     => $label,
            'processed'       => $index,
            'total'           => $total,
            'product_count'   => (int) ( $state['counts']['products'] ?? 0 ),
            'variation_count' => (int) ( $state['counts']['variations'] ?? 0 ),
            'media_count'     => (int) ( $state['counts']['media'] ?? 0 ),
            'error_count'     => count( (array) ( $state['errors'] ?? array() ) ),
            'complete'        => $complete,
        );
    }

    private function backup_job_media_ids( $product, $product_record ) {
        $content_media_ids = wp_list_pluck( (array) $product_record['content_media'], 'old_id' );
        $category_media_ids = $this->collect_term_media_ids( (array) $product_record['categories'] );
        $tag_media_ids = $this->collect_term_media_ids( (array) $product_record['tags'] );
        $shipping_media_ids = ! empty( $product_record['shipping_class'] ) ? $this->collect_term_media_ids( array( $product_record['shipping_class'] ) ) : array();
        $custom_taxonomy_media_ids = array();
        foreach ( (array) $product_record['custom_taxonomies'] as $taxonomy_records ) {
            $custom_taxonomy_media_ids = array_merge( $custom_taxonomy_media_ids, $this->collect_term_media_ids( $taxonomy_records ) );
        }
        $attribute_media_ids = $this->collect_attribute_media_ids( (array) $product_record['attributes'] );
        $custom_meta_media_ids = array();
        foreach ( (array) $product_record['custom_meta_media_ids'] as $meta_ids ) {
            $custom_meta_media_ids = array_merge( $custom_meta_media_ids, (array) $meta_ids );
        }
        return array_filter( array_unique( array_map( 'absint', array_merge(
            array( $product->get_image_id() ),
            $product->get_gallery_image_ids(),
            $content_media_ids,
            $category_media_ids,
            $tag_media_ids,
            $shipping_media_ids,
            $custom_taxonomy_media_ids,
            $attribute_media_ids,
            $custom_meta_media_ids
        ) ) ) );
    }

    private function backup_job_add_media( &$state, ZipArchive $zip, $attachment_id ) {
        $attachment_id = absint( $attachment_id );
        if ( ! $attachment_id || ! empty( $state['added_media'][ $attachment_id ] ) ) {
            return;
        }
        $added = (array) $state['added_media'];
        list( $row, $added ) = $this->media_record( $attachment_id, $zip, $added );
        $state['added_media'] = $added;
        if ( $row ) {
            if ( ! $this->append_backup_ndjson( trailingslashit( $this->backup_job_dir( $state['job_id'] ) ) . 'media.ndjson', $row ) ) {
                throw new RuntimeException( 'Could not write media metadata to the backup job.' );
            }
            $state['counts']['media']++;
        }
    }

    private function backup_job_process_product( &$state, ZipArchive $zip, $product_id ) {
        $product = wc_get_product( $product_id );
        if ( ! $product || is_a( $product, 'WC_Product_Variation' ) ) {
            return;
        }
        $dir = trailingslashit( $this->backup_job_dir( $state['job_id'] ) );
        $record = $this->product_record( $product );
        $added_downloads = (array) $state['added_downloads'];
        $this->package_downloads_for_record( $record, $zip, $added_downloads );
        $state['added_downloads'] = $added_downloads;
        if ( ! $this->append_backup_ndjson( $dir . 'products.ndjson', $record ) ) {
            throw new RuntimeException( 'Could not write product metadata to the backup job.' );
        }
        $state['counts']['products']++;
        if ( ! empty( $state['include_images'] ) ) {
            foreach ( $this->backup_job_media_ids( $product, $record ) as $media_id ) {
                $this->backup_job_add_media( $state, $zip, $media_id );
            }
        }
        if ( $product->is_type( 'variable' ) ) {
            foreach ( $product->get_children() as $variation_id ) {
                $variation = wc_get_product( $variation_id );
                if ( ! $variation || ! is_a( $variation, 'WC_Product_Variation' ) ) {
                    continue;
                }
                $variation_record = $this->variation_record( $variation );
                $added_downloads = (array) $state['added_downloads'];
                $this->package_downloads_for_record( $variation_record, $zip, $added_downloads );
                $state['added_downloads'] = $added_downloads;
                if ( ! $this->append_backup_ndjson( $dir . 'variations.ndjson', $variation_record ) ) {
                    throw new RuntimeException( 'Could not write variation metadata to the backup job.' );
                }
                $state['counts']['variations']++;
                if ( ! empty( $state['include_images'] ) ) {
                    $variation_media_ids = array( $variation->get_image_id() );
                    if ( ! empty( $variation_record['shipping_class'] ) ) {
                        $variation_media_ids = array_merge( $variation_media_ids, $this->collect_term_media_ids( array( $variation_record['shipping_class'] ) ) );
                    }
                    foreach ( (array) $variation_record['custom_meta_media_ids'] as $meta_ids ) {
                        $variation_media_ids = array_merge( $variation_media_ids, (array) $meta_ids );
                    }
                    foreach ( array_filter( array_unique( array_map( 'absint', $variation_media_ids ) ) ) as $media_id ) {
                        $this->backup_job_add_media( $state, $zip, $media_id );
                    }
                }
            }
        }
    }

    private function finalize_backup_job( &$state ) {
        $dir = trailingslashit( $this->backup_job_dir( $state['job_id'] ) );
        $kind = sanitize_key( (string) $state['kind'] );
        $json_files = array();
        if ( 'media' === $kind ) {
            if ( ! $this->ndjson_to_json_file( $dir . 'media.ndjson', $dir . 'media.json' ) ) {
                return new WP_Error( 'metadata_finalize_failed', 'Could not finalize media metadata.' );
            }
            $manifest = array(
                'format' => 'sbop', 'compatible_formats' => array( 'sbop', 'wpmb' ), 'format_version' => 2,
                'plugin_version' => self::VERSION, 'backup_type' => 'media', 'created_at' => gmdate( DATE_ATOM ),
                'site_url' => site_url(), 'wordpress_version' => get_bloginfo( 'version' ),
                'media_count' => (int) $state['counts']['media'], 'checksum_algorithm' => 'sha256',
            );
            $filename = 'productshift-backup-migration-media-' . gmdate( 'Y-m-d-His' ) . '.zip';
            $history_type = 'media';
            $json_files['media.json'] = $dir . 'media.json';
        } else {
            if ( ! $this->ndjson_to_json_file( $dir . 'products.ndjson', $dir . 'products.json' ) ||
                 ! $this->ndjson_to_json_file( $dir . 'variations.ndjson', $dir . 'variations.json' ) ||
                 ! $this->ndjson_to_json_file( $dir . 'media.ndjson', $dir . 'media.json' ) ) {
                return new WP_Error( 'metadata_finalize_failed', 'Could not finalize product metadata.' );
            }
            $variant = 'incremental' === $kind ? 'incremental' : 'full';
            $manifest = array(
                'format' => 'sbop', 'compatible_formats' => array( 'sbop', 'wpmb' ), 'format_version' => 4,
                'plugin_version' => self::VERSION, 'backup_type' => 'products', 'archive_variant' => $variant,
                'created_at' => gmdate( DATE_ATOM ), 'site_url' => site_url(), 'wordpress_version' => get_bloginfo( 'version' ),
                'woocommerce_version' => defined( 'WC_VERSION' ) ? WC_VERSION : '',
                'product_count' => (int) $state['counts']['products'], 'variation_count' => (int) $state['counts']['variations'],
                'media_count' => (int) $state['counts']['media'], 'catalog_fidelity' => true, 'category_hierarchy' => true,
                'embedded_media' => true, 'portable_downloads' => true, 'private_custom_meta' => true,
                'checksum_algorithm' => 'sha256', 'incremental_to' => gmdate( DATE_ATOM, (int) $state['anchor_to'] ),
            );
            if ( 'incremental' === $kind ) {
                $manifest['incremental_from'] = ! empty( $state['anchor_from'] ) ? gmdate( DATE_ATOM, (int) $state['anchor_from'] ) : null;
                $manifest['baseline_created'] = empty( $state['anchor_from'] );
                $filename = 'productshift-backup-migration-incremental-' . gmdate( 'Y-m-d-His' ) . '.zip';
                $history_type = 'incremental-products';
            } else {
                $filename = 'productshift-backup-migration-products-' . gmdate( 'Y-m-d-His' ) . '.zip';
                $history_type = 'products';
            }
            $json_files['products.json'] = $dir . 'products.json';
            $json_files['variations.json'] = $dir . 'variations.json';
            $json_files['media.json'] = $dir . 'media.json';
        }
        $manifest_path = $dir . 'manifest.json';
        $filesystem = $this->filesystem();
        if ( ! $filesystem->put_contents( $manifest_path, wp_json_encode( $manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ), FS_CHMOD_FILE ) ) {
            return new WP_Error( 'manifest_write_failed', 'Could not write the backup manifest.' );
        }
        $json_files = array_merge( array( 'manifest.json' => $manifest_path ), $json_files );
        $checksums = array();
        foreach ( $json_files as $name => $path ) {
            $checksums[ $name ] = function_exists( 'hash_file' ) ? hash_file( 'sha256', $path ) : '';
        }
        $zip = new ZipArchive();
        $zip_path = $dir . 'archive.zip';
        if ( true !== $zip->open( $zip_path, ZipArchive::CREATE ) ) {
            return new WP_Error( 'zip_finalize_failed', 'Could not reopen the backup ZIP for finalization.' );
        }
        foreach ( $json_files as $name => $path ) {
            $zip->addFile( $path, $name );
        }
        $zip->addFromString( 'checksums.json', wp_json_encode( array( 'algorithm' => 'sha256', 'files' => $checksums ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
        $zip->close();
        if ( ! file_exists( $zip_path ) || filesize( $zip_path ) < 22 ) {
            return new WP_Error( 'zip_missing', 'The finalized backup ZIP is missing or empty.' );
        }
        $backup_id = $this->register_backup( $zip_path, $filename, $history_type, $manifest );
        if ( ! $backup_id ) {
            return new WP_Error( 'backup_persist_failed', 'The ZIP was created but could not be saved to Backup History. Check upload-directory permissions.' );
        }
        if ( 'media' !== $kind ) {
            update_option( 'sbop_incremental_anchor', (int) $state['anchor_to'], false );
        }
        $payload = $this->backup_client_payload( $backup_id );
        $payload['message'] = 'Backup was created, verified and saved successfully.';
        $payload['complete'] = true;
        $payload['percent'] = 100;
        delete_user_meta( (int) $state['user_id'], '_sbop_active_backup_job' );
        $state['complete'] = true;
        $this->cleanup_dir( $this->backup_job_dir( $state['job_id'] ) );
        return $payload;
    }

    private function backup_storage_preflight() {
        $uploads = wp_upload_dir();
        if ( ! empty( $uploads['error'] ) ) {
            return new WP_Error( 'uploads_unavailable', sprintf( 'WordPress uploads directory is unavailable: %s', sanitize_text_field( (string) $uploads['error'] ) ) );
        }

        $temp_base = $this->temp_base();
        $backup_base = $this->backup_base();
        $filesystem = $this->filesystem();
        if ( ! $filesystem->is_dir( $temp_base ) || ! $filesystem->is_writable( $temp_base ) ) {
            return new WP_Error( 'temp_not_writable', 'The temporary backup workspace is not writable. Check wp-content/uploads permissions.' );
        }
        if ( ! $filesystem->is_dir( $backup_base ) || ! $filesystem->is_writable( $backup_base ) ) {
            return new WP_Error( 'backup_not_writable', 'The protected backup storage folder is not writable. Check wp-content/uploads permissions.' );
        }

        // Verify that the same write/move operations used by resumable jobs work
        // before we allocate a real job id. This catches hosting restrictions early.
        $probe = trailingslashit( $temp_base ) . 'sbop-write-test-' . wp_generate_password( 12, false, false ) . '.tmp';
        $probe_moved = $probe . '.ok';
        if ( ! $filesystem->put_contents( $probe, 'ok', FS_CHMOD_FILE ) ) {
            return new WP_Error( 'temp_write_failed', 'WordPress could not write to the temporary backup workspace.' );
        }
        $moved = $filesystem->move( $probe, $probe_moved, true );
        if ( ! $moved ) {
            $filesystem->delete( $probe, false, 'f' );
            return new WP_Error( 'temp_move_failed', 'WordPress could not finalize files inside the temporary backup workspace.' );
        }
        $filesystem->delete( $probe_moved, false, 'f' );
        return true;
    }

    public function ajax_backup_start() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( array( 'message' => 'You do not have permission to create backups.' ), 403 );
        }
        check_ajax_referer( 'sbop_backup_ajax', 'nonce' );
        if ( ! class_exists( 'ZipArchive' ) ) {
            wp_send_json_error( array( 'message' => 'PHP ZipArchive is not available on this server.' ), 500 );
        }
        $preflight = $this->backup_storage_preflight();
        if ( is_wp_error( $preflight ) ) {
            wp_send_json_error( array( 'message' => $preflight->get_error_message(), 'code' => $preflight->get_error_code() ), 500 );
        }
        $user_id = get_current_user_id();
        $active_id = (string) get_user_meta( $user_id, '_sbop_active_backup_job', true );
        if ( $active_id ) {
            $active_state = $this->load_backup_job_state( $active_id );
            if ( $active_state && ( time() - (int) ( $active_state['last_activity'] ?? 0 ) ) < HOUR_IN_SECONDS ) {
                $payload = $this->backup_job_payload( $active_state );
                $payload['resuming'] = true;
                wp_send_json_success( $payload );
            }
            $this->cleanup_dir( $this->backup_job_dir( $active_id ) );
            delete_user_meta( $user_id, '_sbop_active_backup_job' );
        }
        $kind = isset( $_POST['kind'] ) ? sanitize_key( wp_unslash( $_POST['kind'] ) ) : 'products';
        if ( ! in_array( $kind, array( 'products', 'media', 'incremental' ), true ) ) {
            wp_send_json_error( array( 'message' => 'Unknown backup type.' ), 400 );
        }
        if ( 'media' !== $kind && ! class_exists( 'WooCommerce' ) ) {
            wp_send_json_error( array( 'message' => 'WooCommerce must be active for product backups.' ), 500 );
        }
        $max_limit = 'media' === $kind ? 10000 : 5000;
        $limit = isset( $_POST['limit'] ) ? max( 1, min( $max_limit, absint( $_POST['limit'] ) ) ) : 5000;
        $include_images = 'products' === $kind || ! empty( $_POST['include_images'] );
        $anchor_from = 'incremental' === $kind ? (int) get_option( 'sbop_incremental_anchor', 0 ) : 0;
        $anchor_to = time();
        if ( 'media' === $kind ) {
            $ids = get_posts( array( 'post_type' => 'attachment', 'post_status' => 'inherit', 'posts_per_page' => $limit, 'fields' => 'ids', 'orderby' => 'ID', 'order' => 'ASC', 'no_found_rows' => true ) );
        } elseif ( 'incremental' === $kind ) {
            $args = array( 'post_type' => 'product', 'post_status' => array( 'publish', 'draft', 'pending', 'private' ), 'posts_per_page' => $limit, 'fields' => 'ids', 'orderby' => 'modified', 'order' => 'ASC', 'no_found_rows' => true );
            if ( $anchor_from > 0 ) {
                $args['date_query'] = array( array( 'column' => 'post_modified_gmt', 'after' => gmdate( 'Y-m-d H:i:s', $anchor_from ), 'before' => gmdate( 'Y-m-d H:i:s', $anchor_to ), 'inclusive' => false ) );
            }
            $ids = get_posts( $args );
        } else {
            $ids = wc_get_products( array( 'limit' => $limit, 'return' => 'ids', 'status' => array( 'publish', 'draft', 'pending', 'private' ), 'orderby' => 'ID', 'order' => 'ASC' ) );
        }
        $ids = array_values( array_map( 'absint', (array) $ids ) );
        $job_id = str_replace( '-', '', wp_generate_uuid4() );
        $dir = $this->backup_job_dir( $job_id );
        $filesystem = $this->filesystem();
        if ( ! $filesystem->is_dir( $dir ) && ! $filesystem->mkdir( $dir, 0755, true ) ) {
            wp_send_json_error( array( 'message' => 'Could not create a writable backup job workspace inside wp-content/uploads.', 'code' => 'job_workspace_failed' ), 500 );
        }
        if ( ! $filesystem->is_dir( $dir ) || ! $filesystem->is_writable( $dir ) ) {
            wp_send_json_error( array( 'message' => 'Could not create a writable backup job workspace inside wp-content/uploads.', 'code' => 'job_workspace_failed' ), 500 );
        }
        foreach ( array( 'products.ndjson', 'variations.ndjson', 'media.ndjson' ) as $filename ) {
            if ( ! $filesystem->put_contents( trailingslashit( $dir ) . $filename, '', FS_CHMOD_FILE ) ) {
                $this->cleanup_dir( $dir );
                wp_send_json_error( array( 'message' => 'Could not initialize the backup job files. Check wp-content/uploads permissions.', 'code' => 'job_files_failed' ), 500 );
            }
        }
        $zip = new ZipArchive();
        if ( true !== $zip->open( trailingslashit( $dir ) . 'archive.zip', ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) {
            $this->cleanup_dir( $dir );
            wp_send_json_error( array( 'message' => 'Could not create the backup ZIP.' ), 500 );
        }
        $zip->close();
        $state = array(
            'job_id' => $job_id, 'user_id' => $user_id, 'kind' => $kind, 'include_images' => $include_images,
            'ids' => $ids, 'index' => 0, 'total' => count( $ids ), 'anchor_from' => $anchor_from, 'anchor_to' => $anchor_to,
            'counts' => array( 'products' => 0, 'variations' => 0, 'media' => 0 ), 'added_media' => array(), 'added_downloads' => array(),
            'errors' => array(), 'created_at' => time(), 'last_activity' => time(), 'complete' => false,
        );
        if ( ! $this->save_backup_job_state( $state ) ) {
            $this->cleanup_dir( $dir );
            wp_send_json_error( array( 'message' => 'Could not create the resumable backup job. Check uploads-directory permissions.' ), 500 );
        }
        update_user_meta( $user_id, '_sbop_active_backup_job', $job_id );
        wp_send_json_success( $this->backup_job_payload( $state ) );
    }

    public function ajax_backup_step() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( array( 'message' => 'You do not have permission to create backups.' ), 403 );
        }
        check_ajax_referer( 'sbop_backup_ajax', 'nonce' );
        $job_id = isset( $_POST['job_id'] ) ? sanitize_key( wp_unslash( $_POST['job_id'] ) ) : '';
        $state = $job_id ? $this->load_backup_job_state( $job_id ) : null;
        if ( ! $state || (int) $state['user_id'] !== get_current_user_id() ) {
            wp_send_json_error( array( 'message' => 'Backup job not found. Start the backup again.' ), 404 );
        }
        $dir = trailingslashit( $this->backup_job_dir( $job_id ) );
        $zip = new ZipArchive();
        if ( true !== $zip->open( $dir . 'archive.zip', ZipArchive::CREATE ) ) {
            wp_send_json_error( array( 'message' => 'Could not reopen the working ZIP. Check server disk permissions.' ), 500 );
        }
        $kind = sanitize_key( (string) $state['kind'] );
        $batch = 'media' === $kind ? (int) apply_filters( 'sbop_backup_media_batch_size', 12 ) : (int) apply_filters( 'sbop_backup_product_batch_size', 6 );
        $batch = max( 1, min( 50, $batch ) );
        $started = microtime( true );
        $processed_this_request = 0;
        while ( (int) $state['index'] < (int) $state['total'] && $processed_this_request < $batch ) {
            if ( $processed_this_request > 0 && microtime( true ) - $started > 8.0 ) {
                break;
            }
            $item_id = absint( $state['ids'][ (int) $state['index'] ] );
            try {
                if ( 'media' === $kind ) {
                    $this->backup_job_add_media( $state, $zip, $item_id );
                } else {
                    $this->backup_job_process_product( $state, $zip, $item_id );
                }
            } catch ( Throwable $e ) {
                if ( count( $state['errors'] ) < 50 ) {
                    $state['errors'][] = array( 'item' => $item_id, 'message' => sanitize_text_field( $e->getMessage() ) );
                }
            }
            $state['index']++;
            $processed_this_request++;
        }
        $zip->close();
        if ( (int) $state['index'] >= (int) $state['total'] ) {
            $state['last_activity'] = time();
            $this->save_backup_job_state( $state );
            $result = $this->finalize_backup_job( $state );
            if ( is_wp_error( $result ) ) {
                $this->save_backup_job_state( $state );
                wp_send_json_error( array( 'message' => $result->get_error_message(), 'job_id' => $job_id ), 500 );
            }
            wp_send_json_success( $result );
        }
        if ( ! $this->save_backup_job_state( $state ) ) {
            wp_send_json_error( array( 'message' => 'Could not save backup progress. The job is paused and can be resumed.', 'job_id' => $job_id ), 500 );
        }
        wp_send_json_success( $this->backup_job_payload( $state ) );
    }

    public function ajax_backup_cancel() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( array( 'message' => 'You do not have permission to cancel backups.' ), 403 );
        }
        check_ajax_referer( 'sbop_backup_ajax', 'nonce' );
        $job_id = isset( $_POST['job_id'] ) ? sanitize_key( wp_unslash( $_POST['job_id'] ) ) : '';
        $state = $job_id ? $this->load_backup_job_state( $job_id ) : null;
        if ( ! $state ) {
            wp_send_json_error( array( 'message' => 'Backup job not found.' ), 404 );
        }
        if ( (int) $state['user_id'] !== get_current_user_id() ) {
            wp_send_json_error( array( 'message' => 'This backup job belongs to another administrator.' ), 403 );
        }
        $this->cleanup_dir( $this->backup_job_dir( $job_id ) );
        $active_job = (string) get_user_meta( get_current_user_id(), '_sbop_active_backup_job', true );
        if ( hash_equals( $active_job, $job_id ) ) {
            delete_user_meta( get_current_user_id(), '_sbop_active_backup_job' );
        }
        wp_send_json_success( array( 'message' => 'Backup job cancelled.' ) );
    }

    public function export_products() {
        check_admin_referer( 'sbop_export_products' );
        if ( ! $this->can_run() || ! class_exists( 'WooCommerce' ) ) {
            $this->backup_frame_response( false, array( 'message' => __( 'WooCommerce and ZipArchive are required.', 'productshift-backup-migration' ) ) );
        }
        $limit = isset( $_POST['limit'] ) ? max( 1, min( 5000, absint( $_POST['limit'] ) ) ) : 5000;
        $result = $this->create_products_archive( $limit, true );
        if ( is_wp_error( $result ) ) {
            $this->backup_frame_response( false, array( 'message' => $result->get_error_message() ) );
        }
        $backup_id = $this->register_backup( $result['path'], $result['filename'], 'products', $result['manifest'] );
        if ( file_exists( $result['path'] ) ) {
            wp_delete_file( $result['path'] );
        }
        if ( ! $backup_id ) {
            $this->backup_frame_response( false, array( 'message' => __( 'The ZIP was created but could not be saved to protected backup storage. Check upload-directory permissions and System Status.', 'productshift-backup-migration' ) ) );
        }
        update_option( 'sbop_incremental_anchor', isset( $result['anchor_to'] ) ? (int) $result['anchor_to'] : time(), false );
        $payload = $this->backup_client_payload( $backup_id );
        $payload['message'] = __( 'Product backup was created, verified and saved successfully.', 'productshift-backup-migration' );
        $this->backup_frame_response( true, $payload );
    }

    public function export_incremental() {
        check_admin_referer( 'sbop_export_incremental' );
        if ( ! $this->can_run() || ! class_exists( 'WooCommerce' ) ) {
            $this->backup_frame_response( false, array( 'message' => __( 'WooCommerce and ZipArchive are required.', 'productshift-backup-migration' ) ) );
        }
        $limit = isset( $_POST['limit'] ) ? max( 1, min( 5000, absint( $_POST['limit'] ) ) ) : 5000;
        $include_images = ! empty( $_POST['include_images'] );
        $result = $this->create_incremental_archive( $limit, $include_images );
        if ( is_wp_error( $result ) ) {
            $this->backup_frame_response( false, array( 'message' => $result->get_error_message() ) );
        }
        $backup_id = $this->register_backup( $result['path'], $result['filename'], 'incremental-products', $result['manifest'] );
        if ( file_exists( $result['path'] ) ) {
            wp_delete_file( $result['path'] );
        }
        if ( ! $backup_id ) {
            $this->backup_frame_response( false, array( 'message' => __( 'The incremental ZIP could not be persisted in protected backup storage.', 'productshift-backup-migration' ) ) );
        }
        update_option( 'sbop_incremental_anchor', isset( $result['anchor_to'] ) ? (int) $result['anchor_to'] : time(), false );
        $payload = $this->backup_client_payload( $backup_id );
        $payload['message'] = __( 'Incremental backup was created, verified and saved successfully.', 'productshift-backup-migration' );
        $this->backup_frame_response( true, $payload );
    }

    public function export_media() {
        check_admin_referer( 'sbop_export_media' );
        if ( ! $this->can_run() ) {
            $this->backup_frame_response( false, array( 'message' => __( 'ZipArchive is required.', 'productshift-backup-migration' ) ) );
        }
        $limit = isset( $_POST['limit'] ) ? max( 1, min( 10000, absint( $_POST['limit'] ) ) ) : 5000;
        $result = $this->create_media_archive( $limit );
        if ( is_wp_error( $result ) ) {
            $this->backup_frame_response( false, array( 'message' => $result->get_error_message() ) );
        }
        $backup_id = $this->register_backup( $result['path'], $result['filename'], 'media', $result['manifest'] );
        if ( file_exists( $result['path'] ) ) {
            wp_delete_file( $result['path'] );
        }
        if ( ! $backup_id ) {
            $this->backup_frame_response( false, array( 'message' => __( 'The media ZIP could not be persisted in protected backup storage.', 'productshift-backup-migration' ) ) );
        }
        $payload = $this->backup_client_payload( $backup_id );
        $payload['message'] = __( 'Media backup was created, verified and saved successfully.', 'productshift-backup-migration' );
        $this->backup_frame_response( true, $payload );
    }

    private function safe_extract_zip( $zip_path, $dest ) {
        $zip = new ZipArchive();
        if ( true !== $zip->open( $zip_path ) ) {
            return new WP_Error( 'bad_zip', 'Could not open ZIP file.' );
        }
        if ( $zip->numFiles > 25000 ) {
            $zip->close();
            return new WP_Error( 'too_many_files', 'The ZIP contains too many files.' );
        }
        $total_uncompressed = 0;
        $max_uncompressed = (int) apply_filters( 'sbop_max_restore_uncompressed_bytes', 20 * 1024 * 1024 * 1024 );
        for ( $i = 0; $i < $zip->numFiles; $i++ ) {
            $name = (string) $zip->getNameIndex( $i );
            $normalized = str_replace( '\\', '/', $name );
            if ( '' === $normalized || strpos( $normalized, "\0" ) !== false || preg_match( '#(^|/)\.\.(/|$)#', $normalized ) || '/' === substr( $normalized, 0, 1 ) || preg_match( '#^[A-Za-z]:/#', $normalized ) ) {
                $zip->close();
                return new WP_Error( 'unsafe_zip', 'Unsafe path detected in ZIP archive.' );
            }
            $stat = $zip->statIndex( $i );
            if ( is_array( $stat ) && isset( $stat['size'] ) ) {
                $total_uncompressed += (int) $stat['size'];
                if ( $max_uncompressed > 0 && $total_uncompressed > $max_uncompressed ) {
                    $zip->close();
                    return new WP_Error( 'zip_too_large', 'The uncompressed ZIP is larger than the configured safety limit.' );
                }
            }
            $opsys = 0;
            $attr = 0;
            if ( method_exists( $zip, 'getExternalAttributesIndex' ) && $zip->getExternalAttributesIndex( $i, $opsys, $attr ) ) {
                $mode = ( $attr >> 16 ) & 0xF000;
                if ( 0xA000 === $mode ) {
                    $zip->close();
                    return new WP_Error( 'unsafe_link', 'Symbolic links are not allowed in backup ZIP files.' );
                }
            }
        }
        if ( ! wp_mkdir_p( $dest ) ) {
            $zip->close();
            return new WP_Error( 'mkdir_failed', 'Could not create a temporary restore directory.' );
        }
        $ok = $zip->extractTo( $dest );
        $zip->close();
        return $ok ? true : new WP_Error( 'extract_failed', 'Could not extract ZIP archive.' );
    }

    private function load_json( $path ) {
        if ( ! file_exists( $path ) ) {
            return array();
        }
        $raw = file_get_contents( $path );
        if ( false === $raw ) {
            return array();
        }
        $data = json_decode( $raw, true );
        return is_array( $data ) ? $data : array();
    }

    private function json_array_to_ndjson( $source, $dest ) {
        if ( ! file_exists( $source ) ) {
            $this->filesystem()->put_contents( $dest, '', FS_CHMOD_FILE );
            return 0;
        }
        try {
            $in  = new SplFileObject( $source, 'rb' );
            $out = new SplFileObject( $dest, 'wb' );
        } catch ( RuntimeException $e ) {
            return new WP_Error( 'convert_failed', 'Could not prepare backup data for resumable restore.' );
        }

        $count         = 0;
        $started_array = false;
        $item_started  = false;
        $depth         = 0;
        $in_string     = false;
        $escape        = false;
        $item          = '';

        while ( ! $in->eof() ) {
            $chunk = $in->fread( 65536 );
            if ( '' === $chunk ) {
                continue;
            }
            $len = strlen( $chunk );
            for ( $i = 0; $i < $len; $i++ ) {
                $ch = $chunk[ $i ];
                if ( ! $started_array ) {
                    if ( '[' === $ch ) {
                        $started_array = true;
                    } elseif ( ! ctype_space( $ch ) ) {
                        return new WP_Error( 'invalid_json_array', 'Backup data is not a valid JSON array.' );
                    }
                    continue;
                }
                if ( ! $item_started ) {
                    if ( ctype_space( $ch ) || ',' === $ch ) {
                        continue;
                    }
                    if ( ']' === $ch ) {
                        continue;
                    }
                    if ( '{' !== $ch ) {
                        return new WP_Error( 'invalid_record', 'Backup data contains an unsupported record.' );
                    }
                    $item_started = true;
                    $depth        = 1;
                    $item         = '{';
                    $in_string    = false;
                    $escape       = false;
                    continue;
                }

                $item .= $ch;
                if ( $in_string ) {
                    if ( $escape ) {
                        $escape = false;
                        continue;
                    }
                    if ( '\\' === $ch ) {
                        $escape = true;
                        continue;
                    }
                    if ( '"' === $ch ) {
                        $in_string = false;
                    }
                    continue;
                }
                if ( '"' === $ch ) {
                    $in_string = true;
                    continue;
                }
                if ( '{' === $ch || '[' === $ch ) {
                    $depth++;
                } elseif ( '}' === $ch || ']' === $ch ) {
                    $depth--;
                }

                if ( 0 === $depth ) {
                    $decoded = json_decode( $item, true );
                    if ( ! is_array( $decoded ) ) {
                        return new WP_Error( 'invalid_record_json', 'A backup record could not be decoded.' );
                    }
                    $out->fwrite( wp_json_encode( $decoded, JSON_UNESCAPED_SLASHES ) . "
" );
                    $count++;
                    $item_started = false;
                    $item         = '';
                }
            }
        }
        if ( $item_started || ! $started_array ) {
            return new WP_Error( 'truncated_json', 'Backup data appears to be incomplete or corrupted.' );
        }
        return $count;
    }

    private function read_ndjson_batch( $path, $offset, $limit ) {
        $rows   = array();
        $offset = max( 0, (int) $offset );
        if ( ! file_exists( $path ) ) {
            return array( 'rows' => $rows, 'offset' => $offset, 'eof' => true );
        }
        try {
            $file = new SplFileObject( $path, 'rb' );
        } catch ( RuntimeException $e ) {
            return new WP_Error( 'read_failed', 'Could not read prepared backup data.' );
        }
        if ( $offset > 0 ) {
            $file->fseek( $offset );
        }
        while ( count( $rows ) < $limit && ! $file->eof() ) {
            $line = $file->fgets();
            if ( false === $line ) {
                break;
            }
            $line = trim( $line );
            if ( '' === $line ) {
                continue;
            }
            $row = json_decode( $line, true );
            if ( is_array( $row ) ) {
                $rows[] = $row;
            }
        }
        return array( 'rows' => $rows, 'offset' => $file->ftell(), 'eof' => $file->eof() );
    }

    private function ajax_guard() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( array( 'message' => 'You do not have permission to restore backups.' ), 403 );
        }
        if ( ! class_exists( 'ZipArchive' ) ) {
            wp_send_json_error( array( 'message' => 'PHP ZipArchive is not available on this server.' ), 500 );
        }
    }

    private function save_job_state( $state ) {
        $path                   = $this->job_state_path( $state['job_id'] );
        $state['last_activity'] = time();
        $tmp                    = $path . '.tmp';
        $filesystem             = $this->filesystem();
        if ( ! $filesystem->put_contents( $tmp, wp_json_encode( $state, JSON_UNESCAPED_SLASHES ), FS_CHMOD_FILE ) ) {
            return false;
        }
        if ( file_exists( $path ) ) {
            $filesystem->delete( $path, false, 'f' );
        }
        return (bool) $filesystem->move( $tmp, $path, true );
    }

    private function load_job_state( $job_id ) {
        $path = $this->job_state_path( $job_id );
        if ( ! file_exists( $path ) ) { return null; }
        $raw = file_get_contents( $path );
        $state = json_decode( (string) $raw, true );
        return is_array( $state ) ? $state : null;
    }

    private function add_job_error( &$state, $scope, $identifier, $message ) {
        if ( ! isset( $state['errors'] ) || ! is_array( $state['errors'] ) ) { $state['errors'] = array(); }
        if ( count( $state['errors'] ) < 100 ) {
            $state['errors'][] = array(
                'scope' => sanitize_text_field( $scope ),
                'item' => sanitize_text_field( (string) $identifier ),
                'message' => sanitize_text_field( wp_strip_all_tags( (string) $message ) ),
            );
        }
        $state['counts']['errors'] = isset( $state['counts']['errors'] ) ? (int) $state['counts']['errors'] + 1 : 1;
    }

    private function upload_error_message( $code ) {
        $messages = array(
            UPLOAD_ERR_INI_SIZE   => 'The ZIP is larger than the server upload_max_filesize limit.',
            UPLOAD_ERR_FORM_SIZE  => 'The ZIP is larger than the form upload limit.',
            UPLOAD_ERR_PARTIAL    => 'The ZIP upload was interrupted. Please upload it again.',
            UPLOAD_ERR_NO_FILE    => 'Please choose a backup ZIP file.',
            UPLOAD_ERR_NO_TMP_DIR => 'The server is missing its temporary upload directory.',
            UPLOAD_ERR_CANT_WRITE => 'The server could not write the uploaded ZIP to disk.',
            UPLOAD_ERR_EXTENSION  => 'A PHP extension stopped the upload.',
        );
        return isset( $messages[ $code ] ) ? $messages[ $code ] : 'The ZIP upload failed.';
    }

    private function build_restore_preview( $dir, $prepared, $manifest, $conflict_mode ) {
        $preview = array(
            'products_total'        => (int) ( $prepared['products'] ?? 0 ),
            'variations_total'      => (int) ( $prepared['variations'] ?? 0 ),
            'media_total'           => (int) ( $prepared['media'] ?? 0 ),
            'will_create'           => 0,
            'will_update'           => 0,
            'will_skip'             => 0,
            'conflicts'             => array(),
            'affected_existing_ids' => array(),
            'source_site'           => isset( $manifest['site_url'] ) ? esc_url_raw( $manifest['site_url'] ) : '',
            'backup_created'        => isset( $manifest['created_at'] ) ? sanitize_text_field( $manifest['created_at'] ) : '',
            'archive_variant'       => isset( $manifest['archive_variant'] ) ? sanitize_key( $manifest['archive_variant'] ) : 'full',
            'warnings'              => array(),
        );
        if ( ! empty( $manifest['site_url'] ) && untrailingslashit( (string) $manifest['site_url'] ) !== untrailingslashit( site_url() ) ) {
            $preview['warnings'][] = __( 'This archive was created on a different site. IDs will be remapped during restore.', 'productshift-backup-migration' );
        }
        if ( 'incremental' === $preview['archive_variant'] ) {
            $preview['warnings'][] = __( 'This is an incremental archive. It contains only items changed since its baseline.', 'productshift-backup-migration' );
        }
        if ( 'products' !== ( $manifest['backup_type'] ?? '' ) || empty( $prepared['products'] ) ) {
            return $preview;
        }
        $offset = 0;
        $path = trailingslashit( $dir ) . 'products.ndjson';
        while ( $offset < (int) $prepared['products'] ) {
            $batch = $this->read_ndjson_batch( $path, $offset, 250 );
            if ( is_wp_error( $batch ) ) {
                $preview['warnings'][] = $batch->get_error_message();
                break;
            }
            if ( empty( $batch['rows'] ) ) {
                break;
            }
            foreach ( $batch['rows'] as $row ) {
                $sku = isset( $row['sku'] ) ? (string) $row['sku'] : '';
                $existing_id = $sku ? (int) wc_get_product_id_by_sku( $sku ) : 0;
                if ( $existing_id ) {
                    $preview['affected_existing_ids'][] = $existing_id;
                    if ( count( $preview['conflicts'] ) < 10 ) {
                        $preview['conflicts'][] = array(
                            'sku'         => sanitize_text_field( $sku ),
                            'existing_id' => $existing_id,
                            'name'        => isset( $row['name'] ) ? sanitize_text_field( $row['name'] ) : '',
                        );
                    }
                    if ( 'skip' === $conflict_mode ) {
                        $preview['will_skip']++;
                    } elseif ( 'update' === $conflict_mode ) {
                        $preview['will_update']++;
                    } else {
                        $preview['will_create']++;
                    }
                } else {
                    $preview['will_create']++;
                }
            }
            $offset = (int) $batch['offset'];
        }
        $preview['affected_existing_ids'] = array_values( array_unique( array_map( 'absint', $preview['affected_existing_ids'] ) ) );
        return $preview;
    }

    private function prepare_restore_archive( $source_zip_path, $conflict_mode, $preview_only = true, $source = 'upload' ) {
        $conflict_mode = in_array( $conflict_mode, array( 'update', 'skip', 'new' ), true ) ? $conflict_mode : 'update';
        $job_id = strtolower( wp_generate_password( 24, false, false ) );
        $this->clear_job_cancelled( $job_id );
        $dir = $this->job_dir( $job_id );
        if ( ! wp_mkdir_p( $dir ) ) {
            return new WP_Error( 'restore_dir_failed', __( 'Could not create the restore working directory.', 'productshift-backup-migration' ) );
        }
        $zip_path = trailingslashit( $dir ) . 'backup.zip';
        $filesystem = $this->filesystem();
        if ( ! $filesystem->copy( $source_zip_path, $zip_path, true, FS_CHMOD_FILE ) ) {
            $this->cleanup_dir( $dir );
            return new WP_Error( 'restore_copy_failed', __( 'Could not copy the backup ZIP into the restore working directory.', 'productshift-backup-migration' ) );
        }
        $result = $this->safe_extract_zip( $zip_path, $dir );
        wp_delete_file( $zip_path );
        if ( is_wp_error( $result ) ) {
            $this->cleanup_dir( $dir );
            return $result;
        }
        $checksums_path = trailingslashit( $dir ) . 'checksums.json';
        if ( file_exists( $checksums_path ) ) {
            $checksum_data = $this->load_json( $checksums_path );
            if ( ! empty( $checksum_data['files'] ) && is_array( $checksum_data['files'] ) ) {
                foreach ( $checksum_data['files'] as $checksum_file => $expected_hash ) {
                    $checksum_file = ltrim( str_replace( '\\', '/', (string) $checksum_file ), '/' );
                    if ( preg_match( '#(^|/)\.\.(/|$)#', $checksum_file ) ) {
                        $this->cleanup_dir( $dir );
                        return new WP_Error( 'unsafe_checksum_path', __( 'Unsafe checksum path detected in backup.', 'productshift-backup-migration' ) );
                    }
                    $checksum_path = trailingslashit( $dir ) . $checksum_file;
                    if ( ! file_exists( $checksum_path ) || ! hash_equals( strtolower( (string) $expected_hash ), strtolower( hash_file( 'sha256', $checksum_path ) ) ) ) {
                        $this->cleanup_dir( $dir );
                        /* translators: %s: file path inside the backup archive. */
                        return new WP_Error( 'checksum_failed', sprintf( __( 'Backup integrity check failed for %s.', 'productshift-backup-migration' ), sanitize_text_field( $checksum_file ) ) );
                    }
                }
            }
        }
        $manifest = $this->load_json( trailingslashit( $dir ) . 'manifest.json' );
        if ( empty( $manifest['format'] ) || ! in_array( $manifest['format'], array( 'sbop', 'wpmb' ), true ) || empty( $manifest['backup_type'] ) || ! in_array( $manifest['backup_type'], array( 'products', 'media' ), true ) ) {
            $this->cleanup_dir( $dir );
            return new WP_Error( 'invalid_archive', __( 'This ZIP is not a valid ProductShift backup archive.', 'productshift-backup-migration' ) );
        }
        $prepared = array();
        foreach ( array( 'media', 'products', 'variations' ) as $kind ) {
            $source_json = trailingslashit( $dir ) . $kind . '.json';
            $dest = trailingslashit( $dir ) . $kind . '.ndjson';
            $count = $this->json_array_to_ndjson( $source_json, $dest );
            if ( is_wp_error( $count ) ) {
                $this->cleanup_dir( $dir );
                return new WP_Error( 'prepare_failed', $count->get_error_message() . ' (' . $kind . ')' );
            }
            $prepared[ $kind ] = (int) $count;
        }
        if ( 'products' === $manifest['backup_type'] && ! class_exists( 'WooCommerce' ) ) {
            $this->cleanup_dir( $dir );
            return new WP_Error( 'woocommerce_required', __( 'WooCommerce must be active before restoring a product backup.', 'productshift-backup-migration' ) );
        }
        $next_stage = $prepared['media'] > 0 ? 'media' : ( 'products' === $manifest['backup_type'] && $prepared['products'] > 0 ? 'products' : ( 'products' === $manifest['backup_type'] && $prepared['variations'] > 0 ? 'variations' : 'complete' ) );
        $preview = $this->build_restore_preview( $dir, $prepared, $manifest, $conflict_mode );
        $state = array(
            'job_id'               => $job_id,
            'owner_user_id'        => get_current_user_id(),
            'backup_type'          => $manifest['backup_type'],
            'conflict_mode'        => $conflict_mode,
            'stage'                => $preview_only ? 'preview' : $next_stage,
            'next_stage'           => $next_stage,
            'source'               => sanitize_key( $source ),
            'manifest'             => $manifest,
            'preview'              => $preview,
            'offsets'              => array( 'media' => 0, 'products' => 0, 'variations' => 0 ),
            'counts'               => array(
                'media_total' => $prepared['media'], 'media_done' => 0,
                'products_total' => $prepared['products'], 'products_done' => 0,
                'variations_total' => $prepared['variations'], 'variations_done' => 0,
                'skipped' => 0, 'errors' => 0,
            ),
            'media_map'            => array(),
            'media_parent_map'     => array(),
            'product_map'          => array(),
            'variation_map'        => array(),
            'variation_parent_ids' => array(),
            'relations'            => array(),
            'errors'               => array(),
            'created_product_ids'  => array(),
            'created_variation_ids'=> array(),
            'created_media_ids'    => array(),
            'updated_product_ids'  => array(),
            'updated_variation_ids'=> array(),
            'rollback_backup_id'   => '',
            'rollback_ids'         => ( 'products' === $manifest['backup_type'] && 'update' === $conflict_mode ) ? array_values( array_unique( array_map( 'absint', (array) ( $preview['affected_existing_ids'] ?? array() ) ) ) ) : array(),
            'rollback_index'       => 0,
            'rollback_variations'  => 0,
            'rollback_started_at'  => 0,
            'created_at'           => time(),
            'last_activity'        => time(),
        );
        if ( ! $this->save_job_state( $state ) ) {
            $this->cleanup_dir( $dir );
            return new WP_Error( 'state_failed', __( 'Could not save the restore job state.', 'productshift-backup-migration' ) );
        }
        update_user_meta( get_current_user_id(), '_sbop_active_restore_job', $job_id );
        return $state;
    }

    public function ajax_restore_start() {
        check_ajax_referer( 'sbop_restore_ajax', 'nonce' );
        $this->ajax_guard();
        if ( empty( $_FILES['backup_zip'] ) || ! isset( $_FILES['backup_zip']['error'] ) ) {
            wp_send_json_error( array( 'message' => 'Please choose a backup ZIP file.' ), 400 );
        }
        $upload_error = absint( wp_unslash( $_FILES['backup_zip']['error'] ) );
        if ( UPLOAD_ERR_OK !== $upload_error ) {
            wp_send_json_error( array( 'message' => $this->upload_error_message( $upload_error ) ), 400 );
        }
        $name = isset( $_FILES['backup_zip']['name'] ) ? sanitize_file_name( wp_unslash( $_FILES['backup_zip']['name'] ) ) : 'backup.zip';
        if ( 'zip' !== strtolower( pathinfo( $name, PATHINFO_EXTENSION ) ) ) {
            wp_send_json_error( array( 'message' => 'Please upload a .zip backup created by this plugin.' ), 400 );
        }
        $conflict_mode_raw = isset( $_POST['conflict_mode'] ) ? sanitize_key( wp_unslash( $_POST['conflict_mode'] ) ) : 'update';
        $conflict_mode = in_array( $conflict_mode_raw, array( 'update', 'skip', 'new' ), true ) ? $conflict_mode_raw : 'update';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        $file = array(
            'name'     => $name,
            'type'     => isset( $_FILES['backup_zip']['type'] ) ? sanitize_mime_type( wp_unslash( $_FILES['backup_zip']['type'] ) ) : 'application/zip',
            'tmp_name' => isset( $_FILES['backup_zip']['tmp_name'] ) ? sanitize_text_field( wp_unslash( $_FILES['backup_zip']['tmp_name'] ) ) : '',
            'error'    => $upload_error,
            'size'     => isset( $_FILES['backup_zip']['size'] ) ? absint( wp_unslash( $_FILES['backup_zip']['size'] ) ) : 0,
        );
        $handled = wp_handle_upload( $file, array( 'test_form' => false, 'mimes' => array( 'zip' => 'application/zip' ) ) );
        if ( isset( $handled['error'] ) || empty( $handled['file'] ) ) {
            wp_send_json_error( array( 'message' => isset( $handled['error'] ) ? sanitize_text_field( $handled['error'] ) : 'Could not store the uploaded ZIP.' ), 500 );
        }
        $state = $this->prepare_restore_archive( $handled['file'], $conflict_mode, true, 'upload' );
        wp_delete_file( $handled['file'] );
        if ( is_wp_error( $state ) ) {
            wp_send_json_error( array( 'message' => $state->get_error_message() ), 400 );
        }
        $this->log_activity( 'restore', 'Restore dry run prepared.', array( 'job_id' => $state['job_id'], 'type' => $state['backup_type'] ) );
        wp_send_json_success( $this->job_payload( $state ) );
    }

    public function ajax_restore_confirm() {
        check_ajax_referer( 'sbop_restore_ajax', 'nonce' );
        $this->ajax_guard();
        $job_id = isset( $_POST['job_id'] ) ? sanitize_key( wp_unslash( $_POST['job_id'] ) ) : '';
        $state = $this->load_job_state( $job_id );
        if ( ! $state ) {
            wp_send_json_error( array( 'message' => 'The restore preview could not be found. Please analyze the ZIP again.' ), 404 );
        }
        if ( (int) $state['owner_user_id'] !== get_current_user_id() ) {
            wp_send_json_error( array( 'message' => 'This restore job belongs to another administrator.' ), 403 );
        }
        if ( 'preview' !== $state['stage'] ) {
            wp_send_json_success( $this->job_payload( $state ) );
        }

        // Do not build a potentially large rollback ZIP inside this confirmation
        // request. The rollback snapshot is now created in small resumable AJAX
        // batches by ajax_restore_step(). This makes Confirm Restore return quickly
        // even when hundreds or thousands of products will be updated.
        $rollback_ids = array_values( array_filter( array_unique( array_map( 'absint', (array) ( $state['rollback_ids'] ?? array() ) ) ) ) );
        $state['rollback_ids']        = $rollback_ids;
        $state['rollback_index']      = 0;
        $state['rollback_variations'] = 0;
        $state['rollback_started_at'] = time();
        $state['confirmed_at']        = time();
        $state['last_activity']       = time();
        $state['stage']               = ! empty( $rollback_ids ) ? 'rollback' : ( isset( $state['next_stage'] ) ? $state['next_stage'] : 'complete' );

        if ( ! $this->save_job_state( $state ) ) {
            wp_send_json_error( array( 'message' => 'Could not save the confirmed restore state.' ), 500 );
        }
        $this->log_activity( 'restore', 'Restore confirmed after dry run.', array( 'job_id' => $job_id, 'rollback_items' => count( $rollback_ids ) ) );
        if ( 'complete' === $state['stage'] ) {
            wp_send_json_success( $this->finish_job( $state ) );
        }
        wp_send_json_success( $this->job_payload( $state ) );
    }

    public function ajax_restore_status() {
        check_ajax_referer( 'sbop_restore_ajax', 'nonce' );
        $this->ajax_guard();
        $job_id = isset( $_POST['job_id'] ) ? sanitize_key( wp_unslash( $_POST['job_id'] ) ) : '';
        $state = $this->load_job_state( $job_id );
        if ( ! $state ) {
            wp_send_json_error( array( 'message' => 'The restore job could not be found. It may have expired or been cancelled.' ), 404 );
        }
        if ( (int) $state['owner_user_id'] !== get_current_user_id() ) {
            wp_send_json_error( array( 'message' => 'This restore job belongs to another administrator.' ), 403 );
        }
        wp_send_json_success( $this->job_payload( $state ) );
    }

    private function finalize_rollback_snapshot( &$state ) {
        $dir = trailingslashit( $this->job_dir( $state['job_id'] ) );
        $products_ndjson = $dir . 'rollback-products.ndjson';
        $variations_ndjson = $dir . 'rollback-variations.ndjson';
        $products_json = $dir . 'rollback-products.json';
        $variations_json = $dir . 'rollback-variations.json';

        if ( ! file_exists( $products_ndjson ) ) {
            $filesystem = $this->filesystem();
            $filesystem->put_contents( $products_ndjson, '', FS_CHMOD_FILE );
        }
        if ( ! file_exists( $variations_ndjson ) ) {
            $filesystem = $this->filesystem();
            $filesystem->put_contents( $variations_ndjson, '', FS_CHMOD_FILE );
        }
        if ( ! $this->ndjson_to_json_file( $products_ndjson, $products_json ) || ! $this->ndjson_to_json_file( $variations_ndjson, $variations_json ) ) {
            return new WP_Error( 'rollback_finalize_failed', 'Could not finalize the recovery-point metadata.' );
        }

        $manifest = array(
            'format'              => 'sbop',
            'compatible_formats'  => array( 'sbop', 'wpmb' ),
            'format_version'      => 4,
            'plugin_version'      => self::VERSION,
            'backup_type'         => 'products',
            'archive_variant'     => 'rollback',
            'purpose'             => 'rollback',
            'source_job'          => sanitize_key( (string) $state['job_id'] ),
            'created_at'          => gmdate( DATE_ATOM ),
            'site_url'            => site_url(),
            'wordpress_version'   => get_bloginfo( 'version' ),
            'woocommerce_version' => defined( 'WC_VERSION' ) ? WC_VERSION : '',
            'product_count'       => count( (array) ( $state['rollback_ids'] ?? array() ) ),
            'variation_count'     => (int) ( $state['rollback_variations'] ?? 0 ),
            'media_count'         => 0,
            'lightweight_rollback'=> true,
            'checksum_algorithm'  => 'sha256',
        );
        $manifest_json = wp_json_encode( $manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
        $media_json = "[]\n";
        $checksums = array(
            'manifest.json'   => hash( 'sha256', $manifest_json ),
            'products.json'   => hash_file( 'sha256', $products_json ),
            'variations.json' => hash_file( 'sha256', $variations_json ),
            'media.json'      => hash( 'sha256', $media_json ),
        );
        $zip_path = $dir . 'rollback-point.zip';
        $zip = new ZipArchive();
        if ( true !== $zip->open( $zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) {
            return new WP_Error( 'rollback_zip_failed', 'Could not create the recovery-point ZIP.' );
        }
        $zip->addFromString( 'manifest.json', $manifest_json );
        $zip->addFile( $products_json, 'products.json' );
        $zip->addFile( $variations_json, 'variations.json' );
        $zip->addFromString( 'media.json', $media_json );
        $zip->addFromString( 'checksums.json', wp_json_encode( array( 'algorithm' => 'sha256', 'files' => $checksums ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
        if ( ! $zip->close() || ! file_exists( $zip_path ) || filesize( $zip_path ) < 22 ) {
            return new WP_Error( 'rollback_zip_failed', 'The recovery-point ZIP could not be finalized.' );
        }
        $filename = 'productshift-backup-migration-rollback-' . gmdate( 'Y-m-d-His' ) . '.zip';
        $backup_id = $this->register_backup( $zip_path, $filename, 'rollback', $manifest );
        if ( ! $backup_id ) {
            return new WP_Error( 'rollback_save_failed', 'Could not save the recovery point in protected backup storage.' );
        }
        $state['rollback_backup_id'] = $backup_id;
        return true;
    }

    private function process_restore_rollback_batch( &$state ) {
        $ids = array_values( array_filter( array_map( 'absint', (array) ( $state['rollback_ids'] ?? array() ) ) ) );
        $index = max( 0, (int) ( $state['rollback_index'] ?? 0 ) );
        $batch_size = max( 1, min( 25, (int) apply_filters( 'sbop_restore_rollback_batch_size', 8 ) ) );
        $batch = array_slice( $ids, $index, $batch_size );
        $dir = trailingslashit( $this->job_dir( $state['job_id'] ) );

        foreach ( $batch as $product_id ) {
            $product = wc_get_product( $product_id );
            if ( $product && ! is_a( $product, 'WC_Product_Variation' ) ) {
                $record = $this->product_record( $product );
                if ( ! $this->append_backup_ndjson( $dir . 'rollback-products.ndjson', $record ) ) {
                    return new WP_Error( 'rollback_write_failed', 'Could not write product recovery metadata.' );
                }
                if ( $product->is_type( 'variable' ) ) {
                    foreach ( $product->get_children() as $variation_id ) {
                        $variation = wc_get_product( $variation_id );
                        if ( $variation && is_a( $variation, 'WC_Product_Variation' ) ) {
                            if ( ! $this->append_backup_ndjson( $dir . 'rollback-variations.ndjson', $this->variation_record( $variation ) ) ) {
                                return new WP_Error( 'rollback_write_failed', 'Could not write variation recovery metadata.' );
                            }
                            $state['rollback_variations'] = (int) ( $state['rollback_variations'] ?? 0 ) + 1;
                        }
                    }
                }
            }
            $state['rollback_index'] = min( count( $ids ), (int) $state['rollback_index'] + 1 );
        }

        if ( (int) $state['rollback_index'] >= count( $ids ) ) {
            $finalized = $this->finalize_rollback_snapshot( $state );
            if ( is_wp_error( $finalized ) ) {
                return $finalized;
            }
            $state['stage'] = isset( $state['next_stage'] ) ? $state['next_stage'] : 'complete';
            // Persist the recovery-point ID and stage transition immediately so
            // a lost HTTP response cannot create duplicate rollback archives.
            if ( ! $this->save_job_state( $state ) ) {
                return new WP_Error( 'rollback_state_failed', 'The recovery point was created but the restore checkpoint could not be saved.' );
            }
        }
        return true;
    }

    private function resolve_restore_media_id( $old_id, &$state ) {
        $old_id = absint( $old_id );
        if ( ! $old_id ) {
            return 0;
        }
        $key = (string) $old_id;
        if ( isset( $state['media_map'][ $key ] ) ) {
            return (int) $state['media_map'][ $key ];
        }
        // Lightweight rollback archives intentionally do not copy media binaries.
        // They point back to the original attachment IDs on the same site, which
        // remain in the Media Library because a restore never deletes old media.
        if ( isset( $state['source'] ) && 'rollback' === $state['source'] && 'attachment' === get_post_type( $old_id ) ) {
            return $old_id;
        }
        return 0;
    }

    private function reusable_media_id( $record ) {
        $old_id = ! empty( $record['old_id'] ) ? absint( $record['old_id'] ) : 0;
        $expected_hash = ! empty( $record['sha256'] ) ? strtolower( sanitize_text_field( $record['sha256'] ) ) : '';
        if ( ! $expected_hash ) {
            return 0;
        }

        $matches_hash = function( $attachment_id ) use ( $expected_hash ) {
            $path = get_attached_file( $attachment_id );
            if ( ! $path || ! file_exists( $path ) || ! is_file( $path ) || ! function_exists( 'hash_file' ) ) {
                return false;
            }
            $actual = strtolower( (string) hash_file( 'sha256', $path ) );
            return $actual && hash_equals( $expected_hash, $actual );
        };

        // Same-site restore: the original attachment often still exists. Reuse it
        // instead of duplicating the file and regenerating image sizes.
        if ( $old_id && 'attachment' === get_post_type( $old_id ) && $matches_hash( $old_id ) ) {
            return $old_id;
        }

        // Re-running the same backup can reuse media imported by an earlier restore.
        // Keep a compact hash-to-attachment index in an option so this lookup does
        // not require a meta_key/meta_value query on the large postmeta table.
        $hash_map = get_option( 'sbop_media_hash_map', array() );
        if ( is_array( $hash_map ) && ! empty( $hash_map[ $expected_hash ] ) ) {
            $attachment_id = absint( $hash_map[ $expected_hash ] );
            if ( $attachment_id && 'attachment' === get_post_type( $attachment_id ) && $matches_hash( $attachment_id ) ) {
                return $attachment_id;
            }
            unset( $hash_map[ $expected_hash ] );
            update_option( 'sbop_media_hash_map', $hash_map, false );
        }
        return 0;
    }

    private function import_media_record( $record, $extract_dir, &$reused = false ) {
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';
        $reused = false;
        $reusable_id = $this->reusable_media_id( $record );
        if ( $reusable_id ) {
            $reused = true;
            return (int) $reusable_id;
        }
        if ( empty( $record['old_id'] ) || empty( $record['file'] ) ) {
            return new WP_Error( 'bad_media_record', 'The media record is missing its ID or file path.' );
        }
        $relative = ltrim( str_replace( '\\', '/', (string) $record['file'] ), '/' );
        if ( preg_match( '#(^|/)\.\.(/|$)#', $relative ) ) {
            return new WP_Error( 'unsafe_media_path', 'Unsafe media path.' );
        }
        $source = trailingslashit( $extract_dir ) . $relative;
        $real_source = realpath( $source );
        $real_dir = realpath( $extract_dir );
        if ( ! $real_source || ! $real_dir || 0 !== strpos( str_replace( '\\', '/', $real_source ), trailingslashit( str_replace( '\\', '/', $real_dir ) ) ) || ! is_file( $real_source ) ) {
            return new WP_Error( 'media_missing', 'The media file is missing from the extracted backup.' );
        }
        if ( ! empty( $record['sha256'] ) && function_exists( 'hash_file' ) ) {
            $actual_hash = hash_file( 'sha256', $real_source );
            if ( ! hash_equals( strtolower( (string) $record['sha256'] ), strtolower( (string) $actual_hash ) ) ) {
                return new WP_Error( 'media_checksum_failed', 'The media file failed its SHA-256 integrity check.' );
            }
        }
        $tmp = wp_tempnam( isset( $record['filename'] ) ? $record['filename'] : 'sbop-media' );
        $filesystem = $this->filesystem();
        if ( ! $tmp || ! $filesystem->copy( $real_source, $tmp, true, FS_CHMOD_FILE ) ) {
            return new WP_Error( 'media_copy_failed', 'Could not prepare the media file for WordPress.' );
        }
        $file_array = array(
            'name' => sanitize_file_name( isset( $record['filename'] ) ? $record['filename'] : wp_basename( $real_source ) ),
            'tmp_name' => $tmp,
            'type' => isset( $record['mime_type'] ) ? sanitize_mime_type( $record['mime_type'] ) : '',
            'error' => 0,
            'size' => filesize( $tmp ),
        );
        $post_data = array(
            'post_title' => isset( $record['title'] ) ? sanitize_text_field( $record['title'] ) : '',
            'post_excerpt' => isset( $record['caption'] ) ? wp_kses_post( $record['caption'] ) : '',
            'post_content' => isset( $record['description'] ) ? wp_kses_post( $record['description'] ) : '',
        );
        $new_id = media_handle_sideload( $file_array, 0, null, $post_data );
        if ( is_wp_error( $new_id ) ) {
            wp_delete_file( $tmp );
            return $new_id;
        }
        if ( isset( $record['alt'] ) ) {
            update_post_meta( $new_id, '_wp_attachment_image_alt', sanitize_text_field( $record['alt'] ) );
        }
        if ( ! empty( $record['sha256'] ) ) {
            $source_hash = strtolower( sanitize_text_field( $record['sha256'] ) );
            update_post_meta( $new_id, '_sbop_source_sha256', $source_hash );
            $hash_map = get_option( 'sbop_media_hash_map', array() );
            if ( ! is_array( $hash_map ) ) {
                $hash_map = array();
            }
            $hash_map[ $source_hash ] = (int) $new_id;
            if ( count( $hash_map ) > 5000 ) {
                $hash_map = array_slice( $hash_map, -5000, null, true );
            }
            update_option( 'sbop_media_hash_map', $hash_map, false );
        }
        if ( ! empty( $record['old_id'] ) ) {
            update_post_meta( $new_id, '_sbop_source_attachment_id', absint( $record['old_id'] ) );
        }
        foreach ( (array) ( isset( $record['custom_meta'] ) ? $record['custom_meta'] : array() ) as $meta_key => $values ) {
            $safe_key = preg_replace( '/[^A-Za-z0-9_\-:.]/', '', (string) $meta_key );
            if ( '' === $safe_key ) { continue; }
            delete_post_meta( $new_id, $safe_key );
            foreach ( (array) $values as $value ) { add_post_meta( $new_id, $safe_key, $value ); }
        }
        if ( ! empty( $record['post_date'] ) ) {
            wp_update_post( array(
                'ID'            => (int) $new_id,
                'post_date'     => sanitize_text_field( $record['post_date'] ),
                'post_date_gmt' => ! empty( $record['post_date_gmt'] ) ? sanitize_text_field( $record['post_date_gmt'] ) : get_gmt_from_date( sanitize_text_field( $record['post_date'] ) ),
            ) );
        }
        return (int) $new_id;
    }

    private function remap_meta_media_value( $value, $allowed_ids, &$state ) {
        $allowed = array_fill_keys( array_map( 'strval', array_map( 'absint', (array) $allowed_ids ) ), true );
        if ( is_array( $value ) ) {
            foreach ( $value as $key => $child ) { $value[ $key ] = $this->remap_meta_media_value( $child, $allowed_ids, $state ); }
            return $value;
        }
        if ( is_object( $value ) ) {
            foreach ( get_object_vars( $value ) as $key => $child ) { $value->{$key} = $this->remap_meta_media_value( $child, $allowed_ids, $state ); }
            return $value;
        }
        if ( is_numeric( $value ) ) {
            $old = absint( $value );
            if ( isset( $allowed[ (string) $old ] ) ) {
                $resolved = $this->resolve_restore_media_id( $old, $state );
                if ( $resolved ) {
                    return is_string( $value ) ? (string) $resolved : (int) $resolved;
                }
            }
        }
        if ( is_string( $value ) && preg_match( '/^\s*\d+(?:\s*,\s*\d+)+\s*$/', $value ) ) {
            $parts = preg_split( '/\s*,\s*/', trim( $value ) );
            foreach ( $parts as $i => $part ) {
                $old = absint( $part );
                if ( isset( $allowed[ (string) $old ] ) ) {
                    $resolved = $this->resolve_restore_media_id( $old, $state );
                    if ( $resolved ) { $parts[ $i ] = (string) $resolved; }
                }
            }
            return implode( ',', $parts );
        }
        return $value;
    }

    private function restore_term_meta( $term_id, $record, &$state ) {
        foreach ( (array) ( isset( $record['meta'] ) ? $record['meta'] : array() ) as $key => $values ) {
            $safe_key = preg_replace( '/[^A-Za-z0-9_\-:.]/', '', (string) $key );
            if ( '' === $safe_key || 'thumbnail_id' === $safe_key ) {
                continue;
            }
            delete_term_meta( $term_id, $safe_key );
            $allowed_ids = isset( $record['meta_media_ids'][ $key ] ) ? (array) $record['meta_media_ids'][ $key ] : array();
            foreach ( (array) $values as $value ) {
                add_term_meta( $term_id, $safe_key, $this->remap_meta_media_value( $value, $allowed_ids, $state ) );
            }
        }
        if ( array_key_exists( 'thumbnail_id', (array) $record ) ) {
            $old_thumbnail = absint( $record['thumbnail_id'] );
            $new_thumbnail = $this->resolve_restore_media_id( $old_thumbnail, $state );
            if ( $new_thumbnail ) {
                update_term_meta( $term_id, 'thumbnail_id', $new_thumbnail );
            } elseif ( 0 === $old_thumbnail ) {
                delete_term_meta( $term_id, 'thumbnail_id' );
            }
        }
    }

    private function ensure_term_record( $record, $taxonomy, &$state ) {
        $name = isset( $record['name'] ) ? sanitize_text_field( $record['name'] ) : '';
        $slug = isset( $record['slug'] ) ? sanitize_title( $record['slug'] ) : sanitize_title( $name );
        if ( '' === $name ) {
            return 0;
        }
        $parent_id = 0;
        foreach ( (array) ( isset( $record['ancestors'] ) ? $record['ancestors'] : array() ) as $ancestor ) {
            $ancestor['ancestors'] = array();
            if ( $parent_id ) {
                $ancestor['parent_slug'] = '';
            }
            $ancestor_id = $this->ensure_term_record( $ancestor, $taxonomy, $state );
            if ( $ancestor_id ) {
                if ( $parent_id && is_taxonomy_hierarchical( $taxonomy ) ) {
                    wp_update_term( $ancestor_id, $taxonomy, array( 'parent' => $parent_id ) );
                }
                $parent_id = $ancestor_id;
            }
        }
        if ( ! $parent_id && ! empty( $record['parent_slug'] ) && is_taxonomy_hierarchical( $taxonomy ) ) {
            $parent = get_term_by( 'slug', sanitize_title( $record['parent_slug'] ), $taxonomy );
            if ( $parent && ! is_wp_error( $parent ) ) {
                $parent_id = (int) $parent->term_id;
            }
        }
        $existing = term_exists( $slug, $taxonomy );
        $args = array(
            'slug'        => $slug,
            'description' => isset( $record['description'] ) ? wp_kses_post( $record['description'] ) : '',
        );
        if ( is_taxonomy_hierarchical( $taxonomy ) ) {
            $args['parent'] = $parent_id;
        }
        if ( ! $existing ) {
            $existing = wp_insert_term( $name, $taxonomy, $args );
        } elseif ( ! is_wp_error( $existing ) ) {
            $term_id = (int) ( is_array( $existing ) ? $existing['term_id'] : $existing );
            $update_args = $args;
            $update_args['name'] = $name;
            $updated = wp_update_term( $term_id, $taxonomy, $update_args );
            if ( ! is_wp_error( $updated ) ) {
                $existing = $updated;
            }
        }
        if ( is_wp_error( $existing ) ) {
            return 0;
        }
        $term_id = (int) ( is_array( $existing ) ? $existing['term_id'] : $existing );
        $this->restore_term_meta( $term_id, $record, $state );
        return $term_id;
    }

    private function ensure_terms( $terms, $taxonomy, &$state ) {
        $ids = array();
        foreach ( (array) $terms as $term ) {
            $term_id = $this->ensure_term_record( $term, $taxonomy, $state );
            if ( $term_id ) {
                $ids[] = $term_id;
            }
        }
        return array_values( array_unique( $ids ) );
    }

    private function ensure_attribute_taxonomy( $taxonomy, $definition = array() ) {
        if ( 0 !== strpos( $taxonomy, 'pa_' ) || ! function_exists( 'wc_create_attribute' ) ) {
            return taxonomy_exists( $taxonomy );
        }
        $slug = substr( $taxonomy, 3 );
        if ( ! $slug ) {
            return false;
        }
        $attribute_id = function_exists( 'wc_attribute_taxonomy_id_by_name' ) ? wc_attribute_taxonomy_id_by_name( $taxonomy ) : 0;
        $args = array(
            'name'         => ! empty( $definition['name'] ) ? sanitize_text_field( $definition['name'] ) : ucwords( str_replace( array( '-', '_' ), ' ', $slug ) ),
            'slug'         => $slug,
            'type'         => ! empty( $definition['type'] ) ? sanitize_key( $definition['type'] ) : 'select',
            'order_by'     => ! empty( $definition['order_by'] ) ? sanitize_key( $definition['order_by'] ) : 'menu_order',
            'has_archives' => ! empty( $definition['has_archives'] ),
        );
        if ( ! $attribute_id ) {
            $created = wc_create_attribute( $args );
            if ( is_wp_error( $created ) ) {
                return false;
            }
            $attribute_id = (int) $created;
            delete_transient( 'wc_attribute_taxonomies' );
        } elseif ( function_exists( 'wc_update_attribute' ) && ! empty( $definition ) ) {
            wc_update_attribute( $attribute_id, $args );
            delete_transient( 'wc_attribute_taxonomies' );
        }
        if ( ! taxonomy_exists( $taxonomy ) ) {
            register_taxonomy( $taxonomy, array( 'product' ), array( 'hierarchical' => false, 'show_ui' => false, 'query_var' => true, 'rewrite' => false, 'public' => false ) );
        }
        return taxonomy_exists( $taxonomy );
    }

    private function apply_attributes( $product, $records, &$state ) {
        $attrs = array();
        $fallback_position = 0;
        foreach ( (array) $records as $record ) {
            if ( empty( $record['name'] ) ) {
                continue;
            }
            $name = sanitize_text_field( $record['name'] );
            $attr = new WC_Product_Attribute();
            if ( ! empty( $record['taxonomy'] ) && $this->ensure_attribute_taxonomy( $name, isset( $record['taxonomy_definition'] ) ? (array) $record['taxonomy_definition'] : array() ) ) {
                $attr_id = function_exists( 'wc_attribute_taxonomy_id_by_name' ) ? wc_attribute_taxonomy_id_by_name( $name ) : 0;
                $term_ids = array();
                if ( ! empty( $record['option_records'] ) ) {
                    foreach ( (array) $record['option_records'] as $option_record ) {
                        $term_id = $this->ensure_term_record( $option_record, $name, $state );
                        if ( $term_id ) {
                            $term_ids[] = $term_id;
                        }
                    }
                } else {
                    foreach ( (array) ( isset( $record['options'] ) ? $record['options'] : array() ) as $option ) {
                        $option = sanitize_text_field( $option );
                        if ( '' === $option ) {
                            continue;
                        }
                        $term = term_exists( $option, $name );
                        if ( ! $term ) {
                            $term = wp_insert_term( $option, $name, array( 'slug' => sanitize_title( $option ) ) );
                        }
                        if ( ! is_wp_error( $term ) ) {
                            $term_ids[] = (int) ( is_array( $term ) ? $term['term_id'] : $term );
                        }
                    }
                }
                $attr->set_id( (int) $attr_id );
                $attr->set_name( $name );
                $attr->set_options( array_values( array_unique( $term_ids ) ) );
            } else {
                $attr->set_id( 0 );
                $attr->set_name( $name );
                $attr->set_options( array_values( array_filter( array_map( 'sanitize_text_field', (array) ( isset( $record['options'] ) ? $record['options'] : array() ) ) ) ) );
            }
            $attr->set_position( isset( $record['position'] ) ? intval( $record['position'] ) : $fallback_position );
            $fallback_position++;
            $attr->set_visible( ! empty( $record['visible'] ) );
            $attr->set_variation( ! empty( $record['variation'] ) );
            $attrs[] = $attr;
        }
        $product->set_attributes( $attrs );
    }

    private function make_product_object( $type, $existing_id = 0 ) {
        $type = sanitize_key( $type );
        $id = (int) $existing_id;
        switch ( $type ) {
            case 'variable': return new WC_Product_Variable( $id );
            case 'external': return new WC_Product_External( $id );
            case 'grouped': return new WC_Product_Grouped( $id );
            case 'simple': return new WC_Product_Simple( $id );
        }
        if ( class_exists( 'WC_Product_Factory' ) && method_exists( 'WC_Product_Factory', 'get_product_classname' ) ) {
            $classname = WC_Product_Factory::get_product_classname( $id, $type, 'product' );
            if ( $classname && class_exists( $classname ) && is_subclass_of( $classname, 'WC_Product' ) ) {
                return new $classname( $id );
            }
        }
        return new WC_Product_Simple( $id );
    }

    private function restore_custom_meta( $post_id, $meta, &$state = array(), $meta_media_ids = array() ) {
        foreach ( (array) $meta as $key => $values ) {
            $safe_key = preg_replace( '/[^A-Za-z0-9_\-:.]/', '', (string) $key );
            if ( '' === $safe_key || $this->is_reserved_product_meta_key( $safe_key ) ) {
                continue;
            }
            delete_post_meta( $post_id, $safe_key );
            $allowed_ids = isset( $meta_media_ids[ $key ] ) ? (array) $meta_media_ids[ $key ] : array();
            foreach ( (array) $values as $value ) {
                add_post_meta( $post_id, $safe_key, $this->remap_meta_media_value( $value, $allowed_ids, $state ) );
            }
        }
    }

    private function restore_packaged_download( $row, $state ) {
        if ( empty( $row['backup_file'] ) || empty( $state['job_id'] ) ) {
            return isset( $row['file'] ) ? esc_url_raw( $row['file'] ) : '';
        }
        $relative = ltrim( str_replace( '\\', '/', (string) $row['backup_file'] ), '/' );
        if ( preg_match( '#(^|/)\.\.(/|$)#', $relative ) ) {
            return '';
        }
        $source = trailingslashit( $this->job_dir( $state['job_id'] ) ) . $relative;
        $real_source = realpath( $source );
        $real_dir = realpath( $this->job_dir( $state['job_id'] ) );
        if ( ! $real_source || ! $real_dir || 0 !== strpos( wp_normalize_path( $real_source ), trailingslashit( wp_normalize_path( $real_dir ) ) ) || ! is_file( $real_source ) ) {
            return '';
        }
        if ( ! empty( $row['sha256'] ) && function_exists( 'hash_file' ) ) {
            $actual = hash_file( 'sha256', $real_source );
            if ( ! hash_equals( strtolower( (string) $row['sha256'] ), strtolower( (string) $actual ) ) ) {
                return '';
            }
        }
        $uploads = wp_upload_dir();
        $subdir = 'smart-backup-only-pro-downloads';
        $destination_dir = trailingslashit( $uploads['basedir'] ) . $subdir;
        wp_mkdir_p( $destination_dir );
        $filename = sanitize_file_name( ! empty( $row['filename'] ) ? $row['filename'] : wp_basename( $real_source ) );
        $filename = wp_unique_filename( $destination_dir, $filename );
        $destination = trailingslashit( $destination_dir ) . $filename;
        $filesystem = $this->filesystem();
        if ( ! $filesystem->copy( $real_source, $destination, true, FS_CHMOD_FILE ) ) {
            return '';
        }
        return trailingslashit( $uploads['baseurl'] ) . $subdir . '/' . rawurlencode( $filename );
    }

    private function set_product_downloads( $product, $downloads, $state = array() ) {
        if ( ! class_exists( 'WC_Product_Download' ) ) {
            return;
        }
        $objects = array();
        foreach ( (array) $downloads as $row ) {
            $file = $this->restore_packaged_download( $row, $state );
            if ( ! $file ) {
                $file = ! empty( $row['file'] ) ? esc_url_raw( $row['file'] ) : '';
            }
            if ( ! $file ) {
                continue;
            }
            $download = new WC_Product_Download();
            if ( ! empty( $row['id'] ) ) {
                $download->set_id( sanitize_text_field( $row['id'] ) );
            }
            $download->set_name( isset( $row['name'] ) ? sanitize_text_field( $row['name'] ) : '' );
            $download->set_file( $file );
            $objects[ $download->get_id() ? $download->get_id() : md5( $download->get_file() ) ] = $download;
        }
        $product->set_downloads( $objects );
    }

    private function remap_product_content( $html, $content_media, &$state ) {
        $html = (string) $html;
        foreach ( (array) $content_media as $record ) {
            $old_id = isset( $record['old_id'] ) ? absint( $record['old_id'] ) : 0;
            if ( ! $old_id ) {
                continue;
            }
            $new_id = $this->resolve_restore_media_id( $old_id, $state );
            if ( ! $new_id ) {
                continue;
            }
            $new_url = wp_get_attachment_url( $new_id );
            if ( ! empty( $record['url'] ) && $new_url ) {
                $html = str_replace( (string) $record['url'], $new_url, $html );
            }
            foreach ( (array) ( isset( $record['sizes'] ) ? $record['sizes'] : array() ) as $size_name => $old_size_url ) {
                $new_size_url = wp_get_attachment_image_url( $new_id, $size_name );
                if ( $old_size_url && $new_size_url ) {
                    $html = str_replace( (string) $old_size_url, $new_size_url, $html );
                }
            }
            $html = str_replace( 'wp-image-' . $old_id, 'wp-image-' . $new_id, $html );
            $html = preg_replace( '/("id"\s*:\s*)' . preg_quote( (string) $old_id, '/' ) . '(?=\s*[,}])/', '$1' . $new_id, $html );
            $html = preg_replace_callback( '/(\bids\s*=\s*["\'])([0-9,\s]+)(["\'])/i', function( $matches ) use ( $old_id, $new_id ) {
                $parts = preg_split( '/\s*,\s*/', trim( $matches[2] ) );
                foreach ( $parts as $index => $part ) { if ( (int) $part === $old_id ) { $parts[ $index ] = (string) $new_id; } }
                return $matches[1] . implode( ',', $parts ) . $matches[3];
            }, $html );
            $html = preg_replace_callback( '/("ids"\s*:\s*\[)([^\]]+)(\])/', function( $matches ) use ( $old_id, $new_id ) {
                $parts = preg_split( '/\s*,\s*/', trim( $matches[2] ) );
                foreach ( $parts as $index => $part ) { if ( (int) $part === $old_id ) { $parts[ $index ] = (string) $new_id; } }
                return $matches[1] . implode( ',', $parts ) . $matches[3];
            }, $html );
        }
        return $html;
    }

    private function restore_product_record( $row, &$state ) {
        $old_id = isset( $row['old_id'] ) ? (int) $row['old_id'] : 0;
        $type = isset( $row['type'] ) ? sanitize_key( $row['type'] ) : 'simple';
        $sku = isset( $row['sku'] ) ? (string) $row['sku'] : '';
        $existing_id = $sku ? (int) wc_get_product_id_by_sku( $sku ) : 0;
        if ( $existing_id && 'skip' === $state['conflict_mode'] ) {
            if ( $old_id ) { $state['product_map'][ $old_id ] = $existing_id; }
            $state['counts']['skipped']++;
            return;
        }
        if ( $existing_id && 'new' === $state['conflict_mode'] ) {
            $existing_id = 0;
            $sku = '';
        }
        $update_id = ( 'update' === $state['conflict_mode'] ) ? $existing_id : 0;
        if ( $update_id ) {
            wp_set_object_terms( $update_id, $type, 'product_type' );
            clean_post_cache( $update_id );
            if ( function_exists( 'wc_delete_product_transients' ) ) { wc_delete_product_transients( $update_id ); }
        }
        $product = $this->make_product_object( $type, $update_id );
        $product->set_name( isset( $row['name'] ) ? wp_strip_all_tags( $row['name'] ) : 'Imported Product' );
        if ( ! empty( $row['slug'] ) ) { $product->set_slug( sanitize_title( $row['slug'] ) ); }
        $status = isset( $row['status'] ) ? sanitize_key( $row['status'] ) : 'draft';
        if ( ! in_array( $status, array( 'publish', 'draft', 'pending', 'private' ), true ) ) { $status = 'draft'; }
        $product->set_status( $status );
        $product->set_featured( ! empty( $row['featured'] ) );
        $visibility = isset( $row['catalog_visibility'] ) ? sanitize_key( $row['catalog_visibility'] ) : 'visible';
        if ( ! in_array( $visibility, array( 'visible', 'catalog', 'search', 'hidden' ), true ) ) { $visibility = 'visible'; }
        $product->set_catalog_visibility( $visibility );
        $content_media = isset( $row['content_media'] ) ? (array) $row['content_media'] : array();
        $product->set_description( isset( $row['description'] ) ? wp_kses_post( $this->remap_product_content( $row['description'], $content_media, $state ) ) : '' );
        $product->set_short_description( isset( $row['short_description'] ) ? wp_kses_post( $this->remap_product_content( $row['short_description'], $content_media, $state ) ) : '' );
        if ( $sku ) { $product->set_sku( $sku ); }
        if ( isset( $row['regular_price'] ) ) { $product->set_regular_price( wc_format_decimal( $row['regular_price'] ) ); }
        if ( isset( $row['sale_price'] ) ) { $product->set_sale_price( wc_format_decimal( $row['sale_price'] ) ); }
        if ( ! empty( $row['date_on_sale_from'] ) ) { $product->set_date_on_sale_from( $row['date_on_sale_from'] ); }
        if ( ! empty( $row['date_on_sale_to'] ) ) { $product->set_date_on_sale_to( $row['date_on_sale_to'] ); }
        $product->set_virtual( ! empty( $row['virtual'] ) );
        $product->set_downloadable( ! empty( $row['downloadable'] ) );
        if ( isset( $row['downloads'] ) ) { $this->set_product_downloads( $product, $row['downloads'], $state ); }
        if ( isset( $row['download_limit'] ) ) { $product->set_download_limit( intval( $row['download_limit'] ) ); }
        if ( isset( $row['download_expiry'] ) ) { $product->set_download_expiry( intval( $row['download_expiry'] ) ); }
        $tax_status = isset( $row['tax_status'] ) ? sanitize_key( $row['tax_status'] ) : 'taxable';
        if ( ! in_array( $tax_status, array( 'taxable', 'shipping', 'none' ), true ) ) { $tax_status = 'taxable'; }
        $product->set_tax_status( $tax_status );
        if ( isset( $row['tax_class'] ) ) { $product->set_tax_class( sanitize_title( $row['tax_class'] ) ); }
        $product->set_manage_stock( ! empty( $row['manage_stock'] ) );
        if ( isset( $row['stock_quantity'] ) && null !== $row['stock_quantity'] && '' !== $row['stock_quantity'] ) { $product->set_stock_quantity( wc_stock_amount( $row['stock_quantity'] ) ); }
        $stock_status = isset( $row['stock_status'] ) ? sanitize_key( $row['stock_status'] ) : 'instock';
        if ( ! in_array( $stock_status, array( 'instock', 'outofstock', 'onbackorder' ), true ) ) { $stock_status = 'instock'; }
        $product->set_stock_status( $stock_status );
        $backorders = isset( $row['backorders'] ) ? sanitize_key( $row['backorders'] ) : 'no';
        if ( ! in_array( $backorders, array( 'no', 'notify', 'yes' ), true ) ) { $backorders = 'no'; }
        $product->set_backorders( $backorders );
        $product->set_sold_individually( ! empty( $row['sold_individually'] ) );
        if ( isset( $row['weight'] ) ) { $product->set_weight( wc_format_decimal( $row['weight'] ) ); }
        if ( isset( $row['length'] ) ) { $product->set_length( wc_format_decimal( $row['length'] ) ); }
        if ( isset( $row['width'] ) ) { $product->set_width( wc_format_decimal( $row['width'] ) ); }
        if ( isset( $row['height'] ) ) { $product->set_height( wc_format_decimal( $row['height'] ) ); }
        if ( isset( $row['reviews_allowed'] ) ) { $product->set_reviews_allowed( (bool) $row['reviews_allowed'] ); }
        if ( isset( $row['purchase_note'] ) ) { $product->set_purchase_note( wp_kses_post( $row['purchase_note'] ) ); }
        if ( isset( $row['menu_order'] ) ) { $product->set_menu_order( intval( $row['menu_order'] ) ); }
        if ( isset( $row['low_stock_amount'] ) && method_exists( $product, 'set_low_stock_amount' ) ) { $product->set_low_stock_amount( '' === $row['low_stock_amount'] ? '' : wc_stock_amount( $row['low_stock_amount'] ) ); }
        if ( ! empty( $row['date_created'] ) && method_exists( $product, 'set_date_created' ) ) { $product->set_date_created( $row['date_created'] ); }
        if ( ! empty( $row['date_modified'] ) && method_exists( $product, 'set_date_modified' ) ) { $product->set_date_modified( $row['date_modified'] ); }
        if ( is_a( $product, 'WC_Product_External' ) ) {
            if ( isset( $row['external_url'] ) ) { $product->set_product_url( esc_url_raw( $row['external_url'] ) ); }
            if ( isset( $row['button_text'] ) ) { $product->set_button_text( sanitize_text_field( $row['button_text'] ) ); }
        }
        if ( ! empty( $row['shipping_class'] ) ) {
            $shipping_ids = $this->ensure_terms( array( $row['shipping_class'] ), 'product_shipping_class', $state );
            if ( ! empty( $shipping_ids ) && method_exists( $product, 'set_shipping_class_id' ) ) { $product->set_shipping_class_id( (int) reset( $shipping_ids ) ); }
        }
        $this->apply_attributes( $product, isset( $row['attributes'] ) ? $row['attributes'] : array(), $state );
        if ( method_exists( $product, 'set_default_attributes' ) && isset( $row['default_attributes'] ) ) { $product->set_default_attributes( (array) $row['default_attributes'] ); }
        if ( ! empty( $row['image_id'] ) ) {
            $resolved_image_id = $this->resolve_restore_media_id( $row['image_id'], $state );
            if ( $resolved_image_id ) { $product->set_image_id( $resolved_image_id ); }
        }
        $gallery = array();
        foreach ( (array) ( isset( $row['gallery_image_ids'] ) ? $row['gallery_image_ids'] : array() ) as $old_media_id ) {
            $resolved_gallery_id = $this->resolve_restore_media_id( $old_media_id, $state );
            if ( $resolved_gallery_id ) { $gallery[] = $resolved_gallery_id; }
        }
        $product->set_gallery_image_ids( $gallery );
        $new_id = (int) $product->save();
        if ( ! $new_id ) { throw new Exception( 'WooCommerce did not return a product ID after save.' ); }
        if ( $update_id ) {
            $state['updated_product_ids'][] = $new_id;
        } else {
            $state['created_product_ids'][] = $new_id;
        }
        if ( $old_id ) { $state['product_map'][ (string) $old_id ] = $new_id; }
        wp_set_object_terms( $new_id, $this->ensure_terms( isset( $row['categories'] ) ? $row['categories'] : array(), 'product_cat', $state ), 'product_cat' );
        wp_set_object_terms( $new_id, $this->ensure_terms( isset( $row['tags'] ) ? $row['tags'] : array(), 'product_tag', $state ), 'product_tag' );
        foreach ( (array) ( isset( $row['custom_taxonomies'] ) ? $row['custom_taxonomies'] : array() ) as $taxonomy => $term_records ) {
            $taxonomy = sanitize_key( $taxonomy );
            if ( ! $taxonomy || ! taxonomy_exists( $taxonomy ) ) {
                $this->add_job_error( $state, 'Taxonomy', $taxonomy ? $taxonomy : '?', 'The destination site does not have this product taxonomy registered.' );
                continue;
            }
            wp_set_object_terms( $new_id, $this->ensure_terms( $term_records, $taxonomy, $state ), $taxonomy );
        }
        $this->restore_custom_meta( $new_id, isset( $row['custom_meta'] ) ? $row['custom_meta'] : array(), $state, isset( $row['custom_meta_media_ids'] ) ? $row['custom_meta_media_ids'] : array() );
        $state['relations'][] = array(
            'new_id' => $new_id,
            'upsell_ids' => isset( $row['upsell_ids'] ) ? array_map( 'intval', (array) $row['upsell_ids'] ) : array(),
            'cross_sell_ids' => isset( $row['cross_sell_ids'] ) ? array_map( 'intval', (array) $row['cross_sell_ids'] ) : array(),
            'grouped_children' => isset( $row['grouped_children'] ) ? array_map( 'intval', (array) $row['grouped_children'] ) : array(),
        );
    }

    private function normalize_variation_attributes( $attributes ) {
        $normalized = array();
        foreach ( (array) $attributes as $key => $value ) {
            $key = sanitize_title( preg_replace( '/^attribute_/', '', (string) $key ) );
            if ( '' === $key ) {
                continue;
            }
            $normalized[ $key ] = sanitize_title( (string) $value );
        }
        ksort( $normalized );
        return $normalized;
    }

    private function find_variation_by_attributes( $parent_id, $attributes ) {
        $parent_id = absint( $parent_id );
        if ( ! $parent_id ) {
            return 0;
        }
        $target = $this->normalize_variation_attributes( $attributes );
        if ( empty( $target ) ) {
            return 0;
        }
        $parent = wc_get_product( $parent_id );
        if ( ! $parent || ! method_exists( $parent, 'get_children' ) ) {
            return 0;
        }
        foreach ( (array) $parent->get_children() as $child_id ) {
            $child_id = absint( $child_id );
            if ( ! $child_id || 'product_variation' !== get_post_type( $child_id ) ) {
                continue;
            }
            $candidate = wc_get_product( $child_id );
            if ( ! $candidate || ! is_a( $candidate, 'WC_Product_Variation' ) ) {
                continue;
            }
            if ( $target === $this->normalize_variation_attributes( $candidate->get_attributes() ) ) {
                return $child_id;
            }
        }
        return 0;
    }

    private function resolve_existing_variation_id( $row, $parent_id, &$state, &$sku_conflict_id = 0 ) {
        $sku_conflict_id = 0;
        $old_id = isset( $row['old_id'] ) ? absint( $row['old_id'] ) : 0;
        $sku = isset( $row['sku'] ) ? wc_clean( (string) $row['sku'] ) : '';

        // Same-site restore: prefer the original variation when it still belongs to the mapped parent.
        if ( $old_id && 'product_variation' === get_post_type( $old_id ) ) {
            $candidate = wc_get_product( $old_id );
            if ( $candidate && is_a( $candidate, 'WC_Product_Variation' ) && (int) $candidate->get_parent_id() === (int) $parent_id ) {
                if ( '' === $sku || (string) $candidate->get_sku() === $sku ) {
                    return $old_id;
                }
            }
        }

        // Cross-site restore: SKU is a strong match only when it resolves to a real variation.
        if ( '' !== $sku ) {
            $sku_id = (int) wc_get_product_id_by_sku( $sku );
            if ( $sku_id ) {
                if ( 'product_variation' === get_post_type( $sku_id ) ) {
                    $candidate = wc_get_product( $sku_id );
                    if ( $candidate && is_a( $candidate, 'WC_Product_Variation' ) ) {
                        return $sku_id;
                    }
                } else {
                    $sku_conflict_id = $sku_id;
                }
            }
        }

        // Empty-SKU variations are common. Match them by parent + normalized attributes.
        return $this->find_variation_by_attributes( $parent_id, isset( $row['attributes'] ) ? $row['attributes'] : array() );
    }

    private function restore_variation_record( $row, &$state ) {
        $old_id = isset( $row['old_id'] ) ? (int) $row['old_id'] : 0;
        $old_parent = isset( $row['parent_old_id'] ) ? (int) $row['parent_old_id'] : 0;
        $parent_key = (string) $old_parent;
        if ( ! $old_parent || ! isset( $state['product_map'][ $parent_key ] ) ) {
            throw new Exception( 'Parent product was not restored or mapped.' );
        }
        $parent_id = (int) $state['product_map'][ $parent_key ];
        $parent = wc_get_product( $parent_id );
        if ( ! $parent || is_a( $parent, 'WC_Product_Variation' ) ) {
            throw new Exception( 'Mapped parent ID does not correspond to a valid parent product.' );
        }

        $sku = isset( $row['sku'] ) ? wc_clean( (string) $row['sku'] ) : '';
        $sku_conflict_id = 0;
        $existing_id = $this->resolve_existing_variation_id( $row, $parent_id, $state, $sku_conflict_id );

        if ( $existing_id && 'skip' === $state['conflict_mode'] ) {
            if ( $old_id ) { $state['variation_map'][ (string) $old_id ] = $existing_id; }
            $state['variation_parent_ids'][] = $parent_id;
            $state['counts']['skipped']++;
            return;
        }
        if ( 'new' === $state['conflict_mode'] ) {
            $existing_id = 0;
            if ( '' !== $sku && wc_get_product_id_by_sku( $sku ) ) {
                $sku = '';
            }
        }

        $variation_is_update = $existing_id && 'update' === $state['conflict_mode'];
        $variation = null;
        if ( $variation_is_update ) {
            clean_post_cache( $existing_id );
            if ( function_exists( 'wc_delete_product_transients' ) ) { wc_delete_product_transients( $existing_id ); }
            $variation = wc_get_product( $existing_id );
            if ( ! $variation || ! is_a( $variation, 'WC_Product_Variation' ) ) {
                // Never pass an unverified product ID into WC_Product_Variation().
                $variation = null;
                $variation_is_update = false;
                $existing_id = 0;
            }
        }
        if ( ! $variation ) {
            $variation = new WC_Product_Variation();
        }

        $variation->set_parent_id( $parent_id );
        $status = isset( $row['status'] ) ? sanitize_key( $row['status'] ) : 'publish';
        if ( ! in_array( $status, array( 'publish', 'private' ), true ) ) { $status = 'publish'; }
        $variation->set_status( $status );

        // If a normal product owns the requested SKU, do not crash the variation restore.
        // Preserve the variation and report the SKU conflict as a recoverable item error.
        if ( $sku_conflict_id ) {
            $this->add_job_error( $state, 'Variation SKU', $sku, sprintf( 'SKU is already used by product ID %d. The variation was restored without that SKU.', $sku_conflict_id ) );
            $sku = '';
        }
        if ( $sku ) { $variation->set_sku( $sku ); }
        if ( isset( $row['regular_price'] ) ) { $variation->set_regular_price( wc_format_decimal( $row['regular_price'] ) ); }
        if ( isset( $row['sale_price'] ) ) { $variation->set_sale_price( wc_format_decimal( $row['sale_price'] ) ); }
        if ( ! empty( $row['date_on_sale_from'] ) ) { $variation->set_date_on_sale_from( $row['date_on_sale_from'] ); }
        if ( ! empty( $row['date_on_sale_to'] ) ) { $variation->set_date_on_sale_to( $row['date_on_sale_to'] ); }
        if ( isset( $row['tax_class'] ) ) { $variation->set_tax_class( sanitize_title( $row['tax_class'] ) ); }
        if ( isset( $row['low_stock_amount'] ) && method_exists( $variation, 'set_low_stock_amount' ) ) { $variation->set_low_stock_amount( '' === $row['low_stock_amount'] ? '' : wc_stock_amount( $row['low_stock_amount'] ) ); }
        $variation->set_manage_stock( ! empty( $row['manage_stock'] ) );
        if ( isset( $row['stock_quantity'] ) && null !== $row['stock_quantity'] && '' !== $row['stock_quantity'] ) { $variation->set_stock_quantity( wc_stock_amount( $row['stock_quantity'] ) ); }
        $stock_status = isset( $row['stock_status'] ) ? sanitize_key( $row['stock_status'] ) : 'instock';
        if ( ! in_array( $stock_status, array( 'instock', 'outofstock', 'onbackorder' ), true ) ) { $stock_status = 'instock'; }
        $variation->set_stock_status( $stock_status );
        $backorders = isset( $row['backorders'] ) ? sanitize_key( $row['backorders'] ) : 'no';
        if ( ! in_array( $backorders, array( 'no', 'notify', 'yes' ), true ) ) { $backorders = 'no'; }
        $variation->set_backorders( $backorders );
        if ( isset( $row['weight'] ) ) { $variation->set_weight( wc_format_decimal( $row['weight'] ) ); }
        if ( isset( $row['length'] ) ) { $variation->set_length( wc_format_decimal( $row['length'] ) ); }
        if ( isset( $row['width'] ) ) { $variation->set_width( wc_format_decimal( $row['width'] ) ); }
        if ( isset( $row['height'] ) ) { $variation->set_height( wc_format_decimal( $row['height'] ) ); }
        $variation->set_virtual( ! empty( $row['virtual'] ) );
        $variation->set_downloadable( ! empty( $row['downloadable'] ) );
        if ( isset( $row['downloads'] ) ) { $this->set_product_downloads( $variation, $row['downloads'], $state ); }
        if ( isset( $row['download_limit'] ) ) { $variation->set_download_limit( intval( $row['download_limit'] ) ); }
        if ( isset( $row['download_expiry'] ) ) { $variation->set_download_expiry( intval( $row['download_expiry'] ) ); }
        if ( isset( $row['menu_order'] ) ) { $variation->set_menu_order( intval( $row['menu_order'] ) ); }
        if ( ! empty( $row['shipping_class'] ) ) {
            $variation_shipping_ids = $this->ensure_terms( array( $row['shipping_class'] ), 'product_shipping_class', $state );
            if ( ! empty( $variation_shipping_ids ) && method_exists( $variation, 'set_shipping_class_id' ) ) { $variation->set_shipping_class_id( (int) reset( $variation_shipping_ids ) ); }
        }
        if ( isset( $row['attributes'] ) ) {
            $variation->set_attributes( $this->normalize_variation_attributes( $row['attributes'] ) );
        }
        if ( ! empty( $row['image_id'] ) ) {
            $resolved_variation_image = $this->resolve_restore_media_id( $row['image_id'], $state );
            if ( $resolved_variation_image ) { $variation->set_image_id( $resolved_variation_image ); }
        }
        if ( isset( $row['description'] ) ) { $variation->set_description( wp_kses_post( $row['description'] ) ); }
        $vid = (int) $variation->save();
        if ( ! $vid || 'product_variation' !== get_post_type( $vid ) ) { throw new Exception( 'WooCommerce did not return a valid variation ID after save.' ); }
        if ( $variation_is_update ) {
            $state['updated_variation_ids'][] = $vid;
        } else {
            $state['created_variation_ids'][] = $vid;
        }
        if ( $old_id ) { $state['variation_map'][ (string) $old_id ] = $vid; }
        $state['variation_parent_ids'][] = $parent_id;
        $this->restore_custom_meta( $vid, isset( $row['custom_meta'] ) ? $row['custom_meta'] : array(), $state, isset( $row['custom_meta_media_ids'] ) ? $row['custom_meta_media_ids'] : array() );
    }

    private function process_relations( &$state ) {
        foreach ( (array) $state['relations'] as $relation ) {
            $new_id = isset( $relation['new_id'] ) ? (int) $relation['new_id'] : 0;
            try {
                $product = wc_get_product( $new_id );
                if ( ! $product ) { continue; }
                $upsells = array();
                foreach ( (array) $relation['upsell_ids'] as $old_id ) {
                    $key = (string) (int) $old_id;
                    if ( isset( $state['product_map'][ $key ] ) ) { $upsells[] = (int) $state['product_map'][ $key ]; }
                }
                $cross = array();
                foreach ( (array) $relation['cross_sell_ids'] as $old_id ) {
                    $key = (string) (int) $old_id;
                    if ( isset( $state['product_map'][ $key ] ) ) { $cross[] = (int) $state['product_map'][ $key ]; }
                }
                if ( method_exists( $product, 'set_upsell_ids' ) ) { $product->set_upsell_ids( $upsells ); }
                if ( method_exists( $product, 'set_cross_sell_ids' ) ) { $product->set_cross_sell_ids( $cross ); }
                if ( is_a( $product, 'WC_Product_Grouped' ) && method_exists( $product, 'set_children' ) ) {
                    $children = array();
                    foreach ( (array) ( isset( $relation['grouped_children'] ) ? $relation['grouped_children'] : array() ) as $old_child_id ) {
                        $child_key = (string) (int) $old_child_id;
                        if ( isset( $state['product_map'][ $child_key ] ) ) { $children[] = (int) $state['product_map'][ $child_key ]; }
                    }
                    $product->set_children( $children );
                }
                $product->save();
            } catch ( Throwable $e ) {
                $this->add_job_error( $state, 'Relations', $new_id, $e->getMessage() );
            }
        }
        $state['relations'] = array();
        foreach ( array_values( array_unique( array_map( 'absint', (array) ( isset( $state['variation_parent_ids'] ) ? $state['variation_parent_ids'] : array() ) ) ) ) as $variation_parent_id ) {
            if ( ! $variation_parent_id ) { continue; }
            try {
                if ( class_exists( 'WC_Product_Variable' ) && is_callable( array( 'WC_Product_Variable', 'sync' ) ) ) {
                    WC_Product_Variable::sync( $variation_parent_id );
                }
                clean_post_cache( $variation_parent_id );
                if ( function_exists( 'wc_delete_product_transients' ) ) { wc_delete_product_transients( $variation_parent_id ); }
            } catch ( Throwable $e ) {
                $this->add_job_error( $state, 'Variation sync', $variation_parent_id, $e->getMessage() );
            }
        }
        $state['variation_parent_ids'] = array();
        foreach ( (array) ( isset( $state['media_parent_map'] ) ? $state['media_parent_map'] : array() ) as $old_media_id => $old_parent_id ) {
            $media_key = (string) (int) $old_media_id;
            $parent_key = (string) (int) $old_parent_id;
            if ( isset( $state['media_map'][ $media_key ], $state['product_map'][ $parent_key ] ) ) {
                wp_update_post( array( 'ID' => (int) $state['media_map'][ $media_key ], 'post_parent' => (int) $state['product_map'][ $parent_key ] ) );
            }
        }
        $state['media_parent_map'] = array();
    }

    private function advance_stage_if_needed( &$state ) {
        if ( 'media' === $state['stage'] && $state['counts']['media_done'] >= $state['counts']['media_total'] ) {
            $state['stage'] = ( 'products' === $state['backup_type'] && $state['counts']['products_total'] > 0 ) ? 'products' : ( 'products' === $state['backup_type'] && $state['counts']['variations_total'] > 0 ? 'variations' : 'relations' );
        }
        if ( 'products' === $state['stage'] && $state['counts']['products_done'] >= $state['counts']['products_total'] ) {
            $state['stage'] = $state['counts']['variations_total'] > 0 ? 'variations' : 'relations';
        }
        if ( 'variations' === $state['stage'] && $state['counts']['variations_done'] >= $state['counts']['variations_total'] ) {
            $state['stage'] = 'relations';
        }
        if ( 'relations' === $state['stage'] ) {
            $this->process_relations( $state );
            $state['stage'] = 'complete';
        }
    }

    public function ajax_restore_step() {
        check_ajax_referer( 'sbop_restore_ajax', 'nonce' );
        $this->ajax_guard();
        $job_id = isset( $_POST['job_id'] ) ? sanitize_key( wp_unslash( $_POST['job_id'] ) ) : '';
        $state = $this->load_job_state( $job_id );
        if ( ! $state ) { wp_send_json_error( array( 'message' => 'The restore job could not be found. It may have expired or been cancelled.' ), 404 ); }
        if ( (int) $state['owner_user_id'] !== get_current_user_id() ) { wp_send_json_error( array( 'message' => 'This restore job belongs to another administrator.' ), 403 ); }
        if ( $this->is_job_cancelled( $job_id ) ) { wp_send_json_error( array( 'message' => 'Restore job cancelled.', 'cancelled' => true ), 409 ); }
        if ( 'complete' === $state['stage'] ) { wp_send_json_success( $this->finish_job( $state ) ); }
        $media_batch = max( 1, (int) apply_filters( 'sbop_restore_media_batch_size', 2 ) );
        $product_batch = max( 1, (int) apply_filters( 'sbop_restore_product_batch_size', 8 ) );
        $variation_batch = max( 1, (int) apply_filters( 'sbop_restore_variation_batch_size', 16 ) );
        $dir = $this->job_dir( $job_id );
        try {
            if ( 'rollback' === $state['stage'] ) {
                $rollback_result = $this->process_restore_rollback_batch( $state );
                if ( is_wp_error( $rollback_result ) ) { throw new Exception( $rollback_result->get_error_message() ); }
            } elseif ( 'media' === $state['stage'] ) {
                $batch = $this->read_ndjson_batch( trailingslashit( $dir ) . 'media.ndjson', $state['offsets']['media'], $media_batch );
                if ( is_wp_error( $batch ) ) { throw new Exception( $batch->get_error_message() ); }
                foreach ( $batch['rows'] as $row ) {
                    $old_id = isset( $row['old_id'] ) ? (int) $row['old_id'] : 0;
                    try {
                        $media_reused = false;
                        $new_id = $this->import_media_record( $row, $dir, $media_reused );
                        if ( is_wp_error( $new_id ) ) { throw new Exception( $new_id->get_error_message() ); }
                        if ( ! $media_reused ) { $state['created_media_ids'][] = (int) $new_id; }
                        if ( $old_id ) {
                            $state['media_map'][ (string) $old_id ] = (int) $new_id;
                            if ( ! empty( $row['parent_old_id'] ) ) { $state['media_parent_map'][ (string) $old_id ] = (int) $row['parent_old_id']; }
                        }
                    } catch ( Throwable $e ) {
                        $this->add_job_error( $state, 'Media', $old_id, $e->getMessage() );
                    }
                    $state['counts']['media_done']++;
                }
                $state['offsets']['media'] = $batch['offset'];
            } elseif ( 'products' === $state['stage'] ) {
                $batch = $this->read_ndjson_batch( trailingslashit( $dir ) . 'products.ndjson', $state['offsets']['products'], $product_batch );
                if ( is_wp_error( $batch ) ) { throw new Exception( $batch->get_error_message() ); }
                foreach ( $batch['rows'] as $row ) {
                    $identifier = ! empty( $row['sku'] ) ? $row['sku'] : ( isset( $row['old_id'] ) ? $row['old_id'] : '?' );
                    try { $this->restore_product_record( $row, $state ); }
                    catch ( Throwable $e ) { $this->add_job_error( $state, 'Product', $identifier, $e->getMessage() ); }
                    $state['counts']['products_done']++;
                }
                $state['offsets']['products'] = $batch['offset'];
            } elseif ( 'variations' === $state['stage'] ) {
                $batch = $this->read_ndjson_batch( trailingslashit( $dir ) . 'variations.ndjson', $state['offsets']['variations'], $variation_batch );
                if ( is_wp_error( $batch ) ) { throw new Exception( $batch->get_error_message() ); }
                foreach ( $batch['rows'] as $row ) {
                    $identifier = ! empty( $row['sku'] ) ? $row['sku'] : ( isset( $row['old_id'] ) ? $row['old_id'] : '?' );
                    try { $this->restore_variation_record( $row, $state ); }
                    catch ( Throwable $e ) { $this->add_job_error( $state, 'Variation', $identifier, $e->getMessage() ); }
                    $state['counts']['variations_done']++;
                }
                $state['offsets']['variations'] = $batch['offset'];
            }
            $this->advance_stage_if_needed( $state );
        } catch ( Throwable $e ) {
            $this->add_job_error( $state, 'Restore', $state['stage'], $e->getMessage() );
            if ( ! $this->save_job_state( $state ) ) { wp_send_json_error( array( 'message' => 'Restore failed and its progress could not be saved.' ), 500 ); }
            wp_send_json_error( array( 'message' => 'Restore step failed: ' . $e->getMessage(), 'job' => $this->job_payload( $state ) ), 500 );
        }

        if ( $this->is_job_cancelled( $job_id ) ) {
            $this->cleanup_dir( $dir );
            delete_user_meta( get_current_user_id(), '_sbop_active_restore_job' );
            wp_send_json_error( array( 'message' => 'Restore job cancelled.', 'cancelled' => true ), 409 );
        }
        if ( 'complete' === $state['stage'] ) { wp_send_json_success( $this->finish_job( $state ) ); }
        if ( ! $this->save_job_state( $state ) ) { wp_send_json_error( array( 'message' => 'Could not save restore progress. Please retry this step.' ), 500 ); }
        wp_send_json_success( $this->job_payload( $state ) );
    }

    private function finish_job( $state ) {
        $payload = $this->job_payload( $state );
        $payload['complete'] = true;
        $payload['stage'] = 'complete';
        delete_user_meta( (int) $state['owner_user_id'], '_sbop_active_restore_job' );
        $this->clear_job_cancelled( $state['job_id'] );
        $dir = $this->job_dir( $state['job_id'] );
        $this->cleanup_dir( $dir );
        $counts = isset( $state['counts'] ) && is_array( $state['counts'] ) ? $state['counts'] : array();
        $is_rollback = isset( $state['source'] ) && 'rollback' === $state['source'];
        if ( $is_rollback ) {
            delete_option( 'sbop_last_restore_rollback' );
        } else {
            update_option( 'sbop_last_restore_rollback', array(
                'job_id'                => sanitize_key( (string) $state['job_id'] ),
                'rollback_backup_id'     => isset( $state['rollback_backup_id'] ) ? sanitize_text_field( (string) $state['rollback_backup_id'] ) : '',
                'created_product_ids'    => array_values( array_unique( array_map( 'absint', (array) ( $state['created_product_ids'] ?? array() ) ) ) ),
                'created_variation_ids'  => array_values( array_unique( array_map( 'absint', (array) ( $state['created_variation_ids'] ?? array() ) ) ) ),
                'created_media_ids'      => array_values( array_unique( array_map( 'absint', (array) ( $state['created_media_ids'] ?? array() ) ) ) ),
                'updated_product_ids'    => array_values( array_unique( array_map( 'absint', (array) ( $state['updated_product_ids'] ?? array() ) ) ) ),
                'updated_variation_ids'  => array_values( array_unique( array_map( 'absint', (array) ( $state['updated_variation_ids'] ?? array() ) ) ) ),
                'completed_at'           => time(),
            ), false );
        }
        $message = sprintf( $is_rollback ? 'Rollback completed: %d products, %d variations, %d media, %d errors.' : 'Restore completed: %d products, %d variations, %d media, %d errors.', (int) ( $counts['products_done'] ?? 0 ), (int) ( $counts['variations_done'] ?? 0 ), (int) ( $counts['media_done'] ?? 0 ), (int) ( $counts['errors'] ?? 0 ) );
        $this->log_activity( $is_rollback ? 'rollback' : 'success', $message, array( 'job_id' => $state['job_id'] ) );
        $this->maybe_send_admin_email( $is_rollback ? 'ProductShift – Backup & Migration for WooCommerce: rollback complete' : 'ProductShift – Backup & Migration for WooCommerce: restore complete', $message );
        return $payload;
    }

    private function job_payload( $state ) {
        $media_total = (int) ( $state['counts']['media_total'] ?? 0 );
        $products_total = (int) ( $state['counts']['products_total'] ?? 0 );
        $variations_total = (int) ( $state['counts']['variations_total'] ?? 0 );
        $media_done = (int) ( $state['counts']['media_done'] ?? 0 );
        $products_done = (int) ( $state['counts']['products_done'] ?? 0 );
        $variations_done = (int) ( $state['counts']['variations_done'] ?? 0 );
        $total = $media_total + $products_total + $variations_total;
        $done = $media_done + $products_done + $variations_done;
        $rollback_total = count( (array) ( $state['rollback_ids'] ?? array() ) );
        $rollback_done = min( $rollback_total, max( 0, (int) ( $state['rollback_index'] ?? 0 ) ) );
        $stage = isset( $state['stage'] ) ? sanitize_key( $state['stage'] ) : 'preview';

        if ( 'preview' === $stage ) {
            $percent = 0;
        } elseif ( 'rollback' === $stage ) {
            $percent = $rollback_total > 0 ? min( 10, 2 + (int) floor( 8 * ( $rollback_done / $rollback_total ) ) ) : 10;
        } elseif ( 'complete' === $stage ) {
            $percent = 100;
        } else {
            $base = $rollback_total > 0 ? 10 : 0;
            $percent = $total > 0 ? min( 99, $base + (int) floor( ( 100 - $base ) * min( 1, $done / $total ) ) ) : 99;
        }

        $labels = array(
            'preview'    => 'Dry run ready',
            'rollback'   => $rollback_total > 0 ? sprintf( 'Creating recovery point %1$d of %2$d', $rollback_done, $rollback_total ) : 'Creating recovery point',
            'media'      => 'Restoring media',
            'products'   => 'Restoring products',
            'variations' => 'Restoring variations',
            'relations'  => 'Linking images and related products',
            'complete'   => 'Restore complete',
        );
        $preview = isset( $state['preview'] ) && is_array( $state['preview'] ) ? $state['preview'] : array();
        unset( $preview['affected_existing_ids'] );
        $counts = isset( $state['counts'] ) && is_array( $state['counts'] ) ? $state['counts'] : array();
        $counts['rollback_total'] = $rollback_total;
        $counts['rollback_done'] = $rollback_done;
        return array(
            'job_id' => $state['job_id'],
            'stage' => $stage,
            'stage_label' => isset( $labels[ $stage ] ) ? $labels[ $stage ] : 'Restoring',
            'percent' => $percent,
            'counts' => $counts,
            'errors' => array_slice( (array) ( $state['errors'] ?? array() ), -10 ),
            'preview' => $preview,
            'preview_ready' => 'preview' === $stage,
            'source' => isset( $state['source'] ) ? sanitize_key( $state['source'] ) : 'upload',
            'conflict_mode' => isset( $state['conflict_mode'] ) ? sanitize_key( $state['conflict_mode'] ) : 'update',
            'rollback_available' => ! empty( $state['rollback_backup_id'] ),
            'complete' => 'complete' === $stage,
        );
    }

    public function rollback_last_restore() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( esc_html__( 'You do not have permission to roll back restores.', 'productshift-backup-migration' ) );
        }
        check_admin_referer( 'sbop_rollback_last_restore' );
        if ( ! function_exists( 'wc_get_product' ) ) {
            wp_die( esc_html__( 'WooCommerce must be active to roll back product restores.', 'productshift-backup-migration' ) );
        }
        $rollback = get_option( 'sbop_last_restore_rollback', array() );
        if ( ! is_array( $rollback ) || empty( $rollback ) ) {
            wp_safe_redirect( admin_url( 'admin.php?page=sbop-backup-recovery' ) );
            exit;
        }
        $backup_id = isset( $rollback['rollback_backup_id'] ) ? sanitize_text_field( (string) $rollback['rollback_backup_id'] ) : '';
        $prepared_state = null;
        if ( $backup_id ) {
            $entry = $this->find_backup( $backup_id );
            $path = $entry && ! empty( $entry['file'] ) ? trailingslashit( $this->backup_base() ) . wp_basename( $entry['file'] ) : '';
            if ( ! $path || ! file_exists( $path ) ) {
                wp_die( esc_html__( 'The saved rollback point is missing. No rollback changes were made.', 'productshift-backup-migration' ) );
            }
            $prepared_state = $this->prepare_restore_archive( $path, 'update', false, 'rollback' );
            if ( is_wp_error( $prepared_state ) ) {
                wp_die( esc_html( 'Rollback could not be prepared: ' . $prepared_state->get_error_message() ) );
            }
        }
        foreach ( array_reverse( (array) ( $rollback['created_variation_ids'] ?? array() ) ) as $variation_id ) {
            $variation = wc_get_product( absint( $variation_id ) );
            if ( $variation ) {
                $variation->delete( true );
            }
        }
        foreach ( array_reverse( (array) ( $rollback['created_product_ids'] ?? array() ) ) as $product_id ) {
            $product = wc_get_product( absint( $product_id ) );
            if ( $product ) {
                $product->delete( true );
            }
        }
        if ( $prepared_state ) {
            foreach ( (array) ( $rollback['updated_product_ids'] ?? array() ) as $product_id ) {
                $product = wc_get_product( absint( $product_id ) );
                if ( $product && method_exists( $product, 'get_children' ) ) {
                    foreach ( (array) $product->get_children() as $child_id ) {
                        $child = wc_get_product( $child_id );
                        if ( $child && is_a( $child, 'WC_Product_Variation' ) ) {
                            $child->delete( true );
                        }
                    }
                }
            }
        }
        foreach ( array_reverse( (array) ( $rollback['created_media_ids'] ?? array() ) ) as $media_id ) {
            wp_delete_attachment( absint( $media_id ), true );
        }
        if ( $prepared_state ) {
            $this->log_activity( 'rollback', 'Rollback job prepared.', array( 'job_id' => $prepared_state['job_id'], 'backup_id' => $backup_id ) );
            wp_safe_redirect( wp_nonce_url( admin_url( 'admin.php?page=sbop-backup-restore&sbop_rollback_started=1' ), 'sbop_admin_notice' ) );
            exit;
        }
        delete_option( 'sbop_last_restore_rollback' );
        $this->log_activity( 'rollback', 'Last restore was rolled back by removing objects created by that restore.' );
        wp_safe_redirect( admin_url( 'admin.php?page=sbop-backup-recovery' ) );
        exit;
    }

    public function ajax_restore_cancel() {
        check_ajax_referer( 'sbop_restore_ajax', 'nonce' );
        $this->ajax_guard();
        $job_id = isset( $_POST['job_id'] ) ? sanitize_key( wp_unslash( $_POST['job_id'] ) ) : '';
        $state = $job_id ? $this->load_job_state( $job_id ) : null;
        if ( ! $state ) {
            wp_send_json_error( array( 'message' => 'Restore job not found.' ), 404 );
        }
        if ( (int) $state['owner_user_id'] !== get_current_user_id() ) {
            wp_send_json_error( array( 'message' => 'This restore job belongs to another administrator.' ), 403 );
        }
        $this->mark_job_cancelled( $job_id );
        $this->cleanup_dir( $this->job_dir( $job_id ) );
        $active_job = (string) get_user_meta( get_current_user_id(), '_sbop_active_restore_job', true );
        if ( hash_equals( $active_job, $job_id ) ) {
            delete_user_meta( get_current_user_id(), '_sbop_active_restore_job' );
        }
        $this->log_activity( 'cancel', 'Restore job cancelled.', array( 'job_id' => $job_id ) );
        wp_send_json_success( array( 'message' => 'Restore job cancelled.' ) );
    }

}

register_activation_hook( __FILE__, array( 'SBOP_Backup_Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'SBOP_Backup_Plugin', 'deactivate' ) );
new SBOP_Backup_Plugin();
