<?php
// If uninstall not called from WordPress, exit.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// Allow preservation via constant.
if ( defined( 'AIW_PRESERVE_SETTINGS' ) && AIW_PRESERVE_SETTINGS ) {
	return;
}

$options = [ 
	'aiw_connection_string',
	'aiw_instrumentation_key',
	'aiw_use_mock',
	'aiw_min_level',
	'aiw_sampling_rate',
	'aiw_batch_max_size',
	'aiw_batch_flush_interval',
	'aiw_redact_additional_keys',
	'aiw_redact_patterns',
	'aiw_last_send_time',
	'aiw_last_error_code',
	'aiw_last_error_message',
	'aiw_retry_queue_v1',
	'aiw_slow_hook_threshold_ms',
];

foreach ( $options as $opt ) {
	if ( function_exists( 'delete_option' ) ) {
		delete_option( $opt );
	}
}
