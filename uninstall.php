<?php
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

if ( function_exists( 'wp_roles' ) ) {
    $roles = wp_roles();
    if ( $roles ) {
        foreach ( $roles->role_objects as $role ) {
            $role->remove_cap( 'cea_view_analytics' );
            $role->remove_cap( 'cea_migrate_entries' );
        }
    }
}

global $wpdb;
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}cea_migration_map" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
delete_option( 'cea_migration_db_version' );
delete_option( 'cea_site_uuid' );
delete_option( 'cea_migration_lock' );
delete_metadata( 'user', 0, 'cea_export_prefs', '', true );
