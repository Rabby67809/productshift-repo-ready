<?php
/**
 * Uninstall handler for ProductShift – Backup & Migration for WooCommerce.
 *
 * @package ProductShift_Backup_Migration
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

/**
 * Remove plugin-owned files and options when the administrator opted in.
 *
 * @return void
 */
function sbop_run_uninstall_cleanup() {
    wp_clear_scheduled_hook( 'sbop_scheduled_backup' );
    wp_clear_scheduled_hook( 'sbop_remote_upload' );

    if ( ! get_option( 'sbop_delete_data_on_uninstall', false ) ) {
        return;
    }

    require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-base.php';
    require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-direct.php';
    $sbop_filesystem = new WP_Filesystem_Direct( false );
    $sbop_uploads    = wp_upload_dir();
    $sbop_bases = array(
        trailingslashit( $sbop_uploads['basedir'] ) . 'smart-backup-only-pro',
        trailingslashit( $sbop_uploads['basedir'] ) . 'product-backup-migrate',
        trailingslashit( $sbop_uploads['basedir'] ) . 'productshift-backup-migration',
    );

    foreach ( $sbop_bases as $sbop_base ) {
        if ( is_dir( $sbop_base ) ) {
            $sbop_filesystem->delete( $sbop_base, true, 'd' );
        }
    }

    $sbop_options = array(
        'sbop_backup_history',
        'sbop_activity_log',
        'sbop_schedule',
        'sbop_retention',
        'sbop_scheduled_limit',
        'sbop_last_schedule_run',
        'sbop_last_schedule_error',
        'sbop_delete_data_on_uninstall',
        'sbop_email_notifications',
        'sbop_notification_email',
        'sbop_incremental_anchor',
        'sbop_last_restore_rollback',
        'sbop_remote_enabled',
        'sbop_remote_provider',
        'sbop_remote_url',
        'sbop_remote_user',
        'sbop_remote_password',
        'sbop_remote_last_status',
    );

    foreach ( $sbop_options as $sbop_option ) {
        delete_option( $sbop_option );
    }

    delete_metadata( 'user', 0, '_sbop_active_restore_job', '', true );
}

sbop_run_uninstall_cleanup();
