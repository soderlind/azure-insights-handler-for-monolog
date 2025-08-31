<?php
// Example usage helpers (direct helper functions rather than legacy triggers)
try {
	throw new RuntimeException( 'Synthetic test exception' );
} catch (Throwable $e) {
}
aiw_event( 'UserDidSomething', [ 'action' => 'test_run', 'channel' => 'manual' ] );
aiw_metric( 'hook_duration_ms', 123, [ 'hook' => 'init' ] );
// Additional sample metrics exercising newer dimensions / panels
aiw_metric( 'db_slow_query_ms', 345, [ 'query' => 'SELECT * FROM wp_posts WHERE ID = ?', 'rows' => 1 ] );
aiw_metric( 'db_slow_query_count', 1, [ 'window' => 'sample' ] );
aiw_metric( 'cron_run_duration_ms', 789, [ 'cron' => 'manual_trigger' ] );
aiw_event( 'StatusPanelDemo', [ 'source' => 'test.php', 'note' => 'Extended metrics emitted' ] );