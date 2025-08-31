<?php
use AzureInsightsMonolog\Plugin;

if ( ! function_exists( 'aiw_current_trace_id' ) ) {
	function aiw_current_trace_id(): ?string {
		return Plugin::instance()->correlation() ? Plugin::instance()->correlation()->trace_id() : null;
	}
}

if ( ! function_exists( 'aiw_current_span_id' ) ) {
	function aiw_current_span_id(): ?string {
		return Plugin::instance()->correlation() ? Plugin::instance()->correlation()->span_id() : null;
	}
}

if ( ! function_exists( 'aiw_event' ) ) {
	function aiw_event( string $name, array $properties = [], array $measurements = [] ) {
		if ( function_exists( 'get_option' ) && ! get_option( 'aiw_enable_events_api', true ) )
			return;
		$plugin = Plugin::instance();
		if ( ! $plugin || ! method_exists( $plugin, 'telemetry' ) )
			return;
		if ( ! $plugin->telemetry() )
			return;
		$corr = $plugin->correlation();
		$item = $plugin->telemetry()->build_event_item( $name, $properties, $measurements, $corr->trace_id(), $corr->span_id() );
		$plugin->telemetry()->add( $item );
	}
}

if ( ! function_exists( 'aiw_metric' ) ) {
	function aiw_metric( string $name, float $value, array $properties = [] ) {
		if ( function_exists( 'get_option' ) && ! get_option( 'aiw_enable_events_api', true ) )
			return;
		$plugin = Plugin::instance();
		if ( ! function_exists( 'aiw_privacy_notice' ) ) {
			function aiw_privacy_notice(): string {
				$txt = 'Application telemetry (performance, errors, limited request metadata) is sent to Azure Application Insights. Sensitive fields are redacted.';
				if ( function_exists( 'apply_filters' ) ) {
					$txt = apply_filters( 'aiw_privacy_notice_text', $txt );
				}
				return $txt;
			}
		}
		if ( ! $plugin || ! method_exists( $plugin, 'telemetry' ) )
			return;
		if ( ! $plugin->telemetry() )
			return;
		$corr = $plugin->correlation();
		$item = $plugin->telemetry()->build_metric_item( $name, $value, $properties, $corr->trace_id(), $corr->span_id() );
		$plugin->telemetry()->add( $item );
	}
}
