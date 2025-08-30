<?php
declare(strict_types=1);
namespace AzureInsightsWonolog\Admin;

/** Network-level settings page for multisite installations. */
class NetworkSettingsPage {
	public function register(): void {
		if ( ! function_exists( 'add_action' ) ) return;
		add_action( 'network_admin_menu', function () {
			if ( ! function_exists( 'add_menu_page' ) ) return;
			add_menu_page(
				'Azure Insights (Network)',
				'Azure Insights',
				'manage_network_options',
				'aiw-network-settings',
				[ $this, 'render' ],
				'dashicons-chart-line'
			);
		} );
		add_action( 'network_admin_edit_aiw_network_save', [ $this, 'save' ] );
	}

	private function site_get( string $key, $default = '' ) {
		return function_exists( 'get_site_option' ) ? get_site_option( $key, $default ) : $default;
	}

	public function render(): void {
		if ( function_exists( 'current_user_can' ) && ! current_user_can( 'manage_network_options' ) ) {
			if ( function_exists( 'wp_die' ) ) wp_die( function_exists( 'esc_html__' ) ? esc_html__( 'Insufficient permissions', 'azure-insights-wonolog' ) : 'Insufficient permissions' );
			return;
		}
		$fields = [
			'aiw_connection_string'    => 'Connection String',
			'aiw_instrumentation_key'  => 'Instrumentation Key (legacy)',
			'aiw_min_level'            => 'Minimum Log Level',
			'aiw_sampling_rate'        => 'Sampling Rate (0-1)',
			'aiw_batch_max_size'       => 'Batch Max Size',
			'aiw_batch_flush_interval' => 'Batch Flush Interval (s)',
			'aiw_async_enabled'        => 'Async Dispatch Enabled (0/1)',
		];
		echo '<div class="wrap"><h1>Azure Insights – Network Settings</h1>';
		$action = function_exists( 'network_admin_url' ) ? network_admin_url( 'edit.php?action=aiw_network_save' ) : ''; 
		$formAction = function_exists( 'esc_url' ) ? esc_url( $action ) : $action;
		echo '<form method="post" action="' . $formAction . '">';
		if ( function_exists( 'wp_nonce_field' ) ) wp_nonce_field( 'aiw_network_save', '_aiw_ns' );
		echo '<table class="form-table" role="presentation">';
		foreach ( $fields as $k => $label ) {
			$rawVal = (string) $this->site_get( $k, '' );
			$val    = function_exists( 'esc_attr' ) ? esc_attr( $rawVal ) : $rawVal;
			$for = function_exists( 'esc_attr' ) ? esc_attr( $k ) : $k;
			$lab = function_exists( 'esc_html' ) ? esc_html( $label ) : $label;
			echo '<tr><th scope="row"><label for="' . $for . '">' . $lab . '</label></th><td>';
			$inputType = 'text';
			if ( $k === 'aiw_sampling_rate' ) $inputType = 'number';
			if ( $k === 'aiw_async_enabled' ) $inputType = 'number';
			$nameAttr = function_exists( 'esc_attr' ) ? esc_attr( $k ) : $k;
			$typeAttr = function_exists( 'esc_attr' ) ? esc_attr( $inputType ) : $inputType;
			echo '<input name="' . $nameAttr . '" id="' . $nameAttr . '" type="' . $typeAttr . '" value="' . $val . '" class="regular-text" />';
			echo '</td></tr>';
		}
		$saveTxt = function_exists( 'esc_html__' ) ? esc_html__( 'Save Changes', 'azure-insights-wonolog' ) : 'Save Changes';
		echo '</table><p class="submit"><button type="submit" class="button-primary">' . $saveTxt . '</button></p></form>';
		echo '<p><em>Per-site settings pages are suppressed while network settings are active. Environment constants / vars still override values.</em></p>';
		echo '</div>';
	}

	public function save(): void {
		if ( function_exists( 'current_user_can' ) && ! current_user_can( 'manage_network_options' ) ) {
			if ( function_exists( 'wp_die' ) ) wp_die( function_exists( 'esc_html__' ) ? esc_html__( 'Insufficient permissions', 'azure-insights-wonolog' ) : 'Insufficient permissions' );
			return;
		}
		if ( function_exists( 'check_admin_referer' ) ) check_admin_referer( 'aiw_network_save', '_aiw_ns' );
		$keys = [ 'aiw_connection_string','aiw_instrumentation_key','aiw_min_level','aiw_sampling_rate','aiw_batch_max_size','aiw_batch_flush_interval','aiw_async_enabled' ];
		foreach ( $keys as $k ) {
			$raw = isset( $_POST[$k] ) ? wp_unslash( $_POST[$k] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$value = is_string( $raw ) ? trim( $raw ) : $raw;
			if ( function_exists( 'update_site_option' ) ) update_site_option( $k, $value );
		}
		if ( function_exists( 'network_admin_url' ) ) {
			$url = network_admin_url( 'admin.php' );
			if ( function_exists( 'add_query_arg' ) ) {
				$url = add_query_arg( [ 'page' => 'aiw-network-settings', 'updated' => 'true' ], $url );
			}
			if ( function_exists( 'wp_safe_redirect' ) ) {
				wp_safe_redirect( $url );
				exit;
			}
		}
	}
}
