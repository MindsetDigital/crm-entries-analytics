<?php
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

if ( function_exists( 'wp_roles' ) ) {
    $roles = wp_roles();
    if ( $roles ) {
        foreach ( $roles->role_objects as $role ) {
            $role->remove_cap( 'cea_view_analytics' );
        }
    }
}
