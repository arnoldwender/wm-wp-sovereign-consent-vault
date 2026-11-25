<?php
/**
 * WM Suite Sovereign Consent Vault — Uninstaller
 *
 * Triggered when the plugin is deleted via WordPress Admin. By default, options and both consent
 * tables are preserved so recorded consent chains are never destroyed unintentionally. Data and
 * tables are removed only when `wm_consent_delete_data_on_uninstall` is explicitly enabled.
 *
 * @package WMCyber\Consent\Vault
 */

declare(strict_types=1);

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

$wm_consent_vault_delete_opt_in = get_option( 'wm_consent_delete_data_on_uninstall', '0' );
if ( true !== $wm_consent_vault_delete_opt_in && 1 !== $wm_consent_vault_delete_opt_in && '1' !== $wm_consent_vault_delete_opt_in ) {
	return;
}

global $wpdb;

// 1. Options the plugin writes (prefixed names: this file runs in the global scope).
$wm_consent_vault_options = [
	'wm_consent_chain_tip',
	'wm_consent_vault_db_version',
	'wm_consent_banner_config',
	'wm_consent_settings',
	'wm_consent_services',
	'wm_consent_delete_data_on_uninstall',
];

foreach ( $wm_consent_vault_options as $wm_consent_vault_option ) {
	delete_option( $wm_consent_vault_option );
	delete_site_option( $wm_consent_vault_option );
}

// 2. Rate-limit buckets of the REST routes.
$wm_consent_vault_like = $wpdb->esc_like( '_transient_wm_cv_rl_' ) . '%';
$wm_consent_vault_to   = $wpdb->esc_like( '_transient_timeout_wm_cv_rl_' ) . '%';
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE option_name LIKE %s OR option_name LIKE %s', $wpdb->options, $wm_consent_vault_like, $wm_consent_vault_to ) );

// 2b. Drafts of the service form (a submitted service that failed validation, kept for one minute per user).
$wm_consent_vault_draft    = $wpdb->esc_like( '_transient_wm_consent_service_draft_' ) . '%';
$wm_consent_vault_draft_to = $wpdb->esc_like( '_transient_timeout_wm_consent_service_draft_' ) . '%';
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE (option_name LIKE %s) OR (option_name LIKE %s)', $wpdb->options, $wm_consent_vault_draft, $wm_consent_vault_draft_to ) );

// 3. Drop the consent tables when explicitly opted in.
$wm_consent_vault_records = $wpdb->prefix . 'wm_consent_records';
$wm_consent_vault_merkle  = $wpdb->prefix . 'wm_consent_merkle_nodes';

$wpdb->query( "DROP TABLE IF EXISTS {$wm_consent_vault_merkle}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.SchemaChange -- table names from $wpdb->prefix.
$wpdb->query( "DROP TABLE IF EXISTS {$wm_consent_vault_records}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.SchemaChange -- table names from $wpdb->prefix.
