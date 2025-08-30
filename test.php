<?php
do_action( 'wonolog.log', 'debug', 'AIW debug sample', [ 'env' => 'local' ] );
do_action( 'wonolog.log', 'info', 'AIW info sample', [ 'feature' => 'telemetry' ] );
do_action( 'wonolog.log', 'notice', 'AIW notice sample' );
do_action( 'wonolog.log', 'warning', 'AIW warning sample', [ 'threshold_ms' => 250 ] );
do_action( 'wonolog.log', 'error', 'AIW error sample', [ 'detail' => 'Something went wrong' ] );
try {
	throw new RuntimeException( 'Synthetic test exception' );
} catch (Throwable $e) {
	do_action( 'wonolog.log', 'error', 'Caught exception', [ 'exception' => $e, 'context' => 'synthetic' ] );
}
aiw_event( 'UserDidSomething', [ 'action' => 'test_run', 'channel' => 'manual' ] );
aiw_metric( 'hook_duration_ms', 123, [ 'hook' => 'init' ] );
// Additional sample metrics exercising newer dimensions / panels
aiw_metric( 'db_slow_query_ms', 345, [ 'query' => 'SELECT * FROM wp_posts WHERE ID = ?', 'rows' => 1 ] );
aiw_metric( 'db_slow_query_count', 1, [ 'window' => 'sample' ] );
aiw_metric( 'cron_run_duration_ms', 789, [ 'cron' => 'manual_trigger' ] );
aiw_event( 'StatusPanelDemo', [ 'source' => 'test.php', 'note' => 'Extended metrics emitted' ] );