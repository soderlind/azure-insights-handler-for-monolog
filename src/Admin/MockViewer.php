<?php
namespace AzureInsightsWonolog\Admin;

use AzureInsightsWonolog\Plugin;
use AzureInsightsWonolog\Telemetry\MockTelemetryClient;

class MockViewer {
	const PAGE_SLUG = 'aiw-mock-telemetry';

	public function register(): void {
		if ( ! function_exists( 'add_action' ) || ! function_exists( 'add_submenu_page' ) )
			return;
		add_action( 'admin_menu', function () {
			add_submenu_page(
				'options-general.php',
				'AIW Mock Telemetry',
				'AIW Mock Telemetry',
				'manage_options',
				self::PAGE_SLUG,
				[ $this, 'render' ]
			);
		} );
	}

	private function esc_html( $v ) {
		return function_exists( 'esc_html' ) ? esc_html( $v ) : htmlspecialchars( (string) $v, ENT_QUOTES, 'UTF-8' );
	}
	private function esc_attr( $v ) {
		return function_exists( 'esc_attr' ) ? esc_attr( $v ) : htmlspecialchars( (string) $v, ENT_QUOTES, 'UTF-8' );
	}
	private function esc_url( $v ) {
		return function_exists( 'esc_url' ) ? esc_url( $v ) : htmlspecialchars( (string) $v, ENT_QUOTES, 'UTF-8' );
	}

	public function render(): void {
		if ( ! function_exists( 'current_user_can' ) || ! current_user_can( 'manage_options' ) )
			return;
		$client = Plugin::instance()->telemetry();
		echo '<div class="wrap"><h1>Mock Telemetry</h1>';
		if ( ! ( $client instanceof MockTelemetryClient ) ) {
			echo '<p>The mock client is not active. Enable "Use Mock" in Azure Insights settings.</p></div>';
			return;
		}

		if ( isset( $_POST[ 'aiw_mock_clear' ] ) && function_exists( 'check_admin_referer' ) ) {
			check_admin_referer( 'aiw_mock_clear_action', 'aiw_mock_clear_nonce' );
			if ( function_exists( 'update_option' ) )
				update_option( 'aiw_mock_telemetry_items', [] );
			$ref = new \ReflectionClass( $client );
			if ( $ref->hasProperty( 'sent' ) ) {
				$p = $ref->getProperty( 'sent' );
				$p->setAccessible( true );
				$p->setValue( $client, [] );
			}
			echo '<div class="updated"><p>Cleared mock telemetry items.</p></div>';
		}

		$items    = $client->sent_items();
		$get      = function ($k) {
			return isset( $_GET[ $k ] ) ? $_GET[ $k ] : null; };
		$san      = function ($v) {
			return function_exists( 'sanitize_text_field' ) ? sanitize_text_field( function_exists( 'wp_unslash' ) ? wp_unslash( $v ) : $v ) : (string) $v; };
		$search   = $get( 's' ) !== null ? $san( $get( 's' ) ) : '';
		$type_f   = $get( 'type' ) !== null ? $san( $get( 'type' ) ) : '';
		$sev_min  = $get( 'sev' ) !== null && $get( 'sev' ) !== '' ? (int) $get( 'sev' ) : null;
		$auto     = $get( 'auto' ) !== null ? (int) $get( 'auto' ) : 0;
		$page     = $get( 'paged' ) !== null ? max( 1, (int) $get( 'paged' ) ) : 1;
		$per_page = 50;

		$filtered = array_filter( $items, function ($it) use ($search, $type_f, $sev_min) {
			$baseType = $it[ 'data' ][ 'baseType' ] ?? '';
			$baseData = $it[ 'data' ][ 'baseData' ] ?? [];
			$sev = $baseData[ 'severityLevel' ] ?? null;
			if ( $type_f && strcasecmp( $baseType, $type_f ) !== 0 )
				return false;
			if ( $sev_min !== null && $sev !== null && (int) $sev < $sev_min )
				return false;
			if ( $search ) {
				$hay = strtolower( json_encode( $baseData ) . ' ' . ( $it[ 'name' ] ?? '' ) );
				if ( strpos( $hay, strtolower( $search ) ) === false )
					return false;
			}
			return true;
		} );

		$total_filtered = count( $filtered );
		$total_pages    = max( 1, (int) ceil( $total_filtered / $per_page ) );
		$offset         = ( $page - 1 ) * $per_page;
		$page_items     = array_slice( array_values( $filtered ), $offset, $per_page );

		$types = [];
		foreach ( $items as $it ) {
			$types[ $it[ 'data' ][ 'baseType' ] ?? '' ] = true;
		}
		ksort( $types );
		if ( $auto > 0 && $auto <= 60 )
			echo '<meta http-equiv="refresh" content="' . (int) $auto . '">';

		if ( function_exists( 'wp_nonce_field' ) ) {
			echo '<form method="post" style="margin:1em 0;display:inline-block;">';
			wp_nonce_field( 'aiw_mock_clear_action', 'aiw_mock_clear_nonce' );
			echo '<button class="button" name="aiw_mock_clear" value="1">Clear</button>';
			echo '</form>';
		}
		echo ' <button type="button" class="button" id="aiw-expand-all">Expand All</button> <button type="button" class="button" id="aiw-collapse-all">Collapse All</button>';

		echo '<form method="get" style="margin:1em 0;display:flex;gap:8px;flex-wrap:wrap;align-items:flex-end;">';
		echo '<input type="hidden" name="page" value="' . self::PAGE_SLUG . '">';
		echo '<label>Search <input type="text" name="s" value="' . $this->esc_attr( $search ) . '" placeholder="text or property"/></label>';
		echo '<label>Type <select name="type"><option value="">All</option>';
		foreach ( array_keys( $types ) as $t ) {
			if ( $t === '' )
				continue;
			$sel = strcasecmp( $t, $type_f ) === 0 ? 'selected' : '';
			echo '<option value="' . $this->esc_attr( $t ) . '" ' . $sel . '>' . $this->esc_html( $t ) . '</option>';
		}
		echo '</select></label>';
		$sev_opts = [ '' => 'Any', 0 => 'Debug', 1 => 'Info', 2 => 'Warn', 3 => 'Error', 4 => 'Crit' ];
		echo '<label>Min Sev <select name="sev">';
		foreach ( $sev_opts as $sv => $lab ) {
			$sel = ( $sv === '' && $sev_min === null ) || ( (string) $sv !== ' ' && (string) $sv === (string) $sev_min ) ? 'selected' : '';
			echo '<option value="' . $this->esc_attr( $sv ) . '" ' . $sel . '>' . $this->esc_html( $lab ) . '</option>';
		}
		echo '</select></label>';
		$auto_opts = [ 0 => 'No Auto', 5 => '5s', 10 => '10s', 30 => '30s', 60 => '60s' ];
		echo '<label>Auto <select name="auto">';
		foreach ( $auto_opts as $sec => $lab ) {
			$sel = (int) $sec === $auto ? 'selected' : '';
			echo '<option value="' . $sec . '" ' . $sel . '>' . $this->esc_html( $lab ) . '</option>';
		}
		echo '</select></label>';
		echo '<button class="button button-primary">Apply</button>';
		echo '</form>';

		echo '<p style="margin-top:0;">Total items: ' . count( $items ) . ' &mdash; Showing ' . count( $page_items ) . ' of ' . $total_filtered . ' (filtered). Page ' . $page . ' / ' . $total_pages . '</p>';
		if ( empty( $page_items ) ) {
			echo '<p>No telemetry items match current filter.</p></div>';
			return;
		}

		echo '<style>.aiw-mock-table td,.aiw-mock-table th{vertical-align:top;font-family:monospace;font-size:12px;} .aiw-badge{display:inline-block;padding:2px 6px;border-radius:3px;color:#fff;font-size:11px;line-height:1;} .aiw-sev-0{background:#646970}.aiw-sev-1{background:#2271b1}.aiw-sev-2{background:#dba617}.aiw-sev-3{background:#d63638}.aiw-sev-4{background:#8a1c1c} .aiw-json{max-height:240px;overflow:auto;background:#f6f7f7;padding:6px;border:1px solid #ccd0d4;margin-top:4px;} .aiw-toggle{cursor:pointer;color:#2271b1;text-decoration:underline;display:inline-block;} .aiw-copy{cursor:pointer;color:#2271b1;margin-left:8px;} .aiw-sticky-head thead th{position:sticky;top:0;background:#fff;box-shadow:0 2px 2px rgba(0,0,0,.04);} </style>';

		echo '<table class="widefat striped aiw-mock-table aiw-sticky-head"><thead><tr><th>#</th><th>Name</th><th>Time</th><th>Type</th><th>Sev</th><th style="width:55%">Data</th></tr></thead><tbody>';
		foreach ( $page_items as $i => $it ) {
			$rowIndex = $offset + $i + 1;
			$name     = $this->esc_html( $it[ 'name' ] ?? '' );
			$time     = $this->esc_html( $it[ 'time' ] ?? '' );
			$type     = $this->esc_html( $it[ 'data' ][ 'baseType' ] ?? '' );
			$baseData = $it[ 'data' ][ 'baseData' ] ?? [];
			$sevLevel = $baseData[ 'severityLevel' ] ?? null;
			$sevBadge = $sevLevel !== null ? '<span class="aiw-badge aiw-sev-' . (int) $sevLevel . '">' . (int) $sevLevel . '</span>' : '';
			$jsonRaw  = json_encode( $baseData, JSON_PRETTY_PRINT );
			$json     = $this->esc_html( $jsonRaw );
			echo '<tr><td>' . $rowIndex . '</td><td>' . $name . '</td><td>' . $time . '</td><td>' . $type . '</td><td>' . $sevBadge . '</td><td><div><span class="aiw-toggle" data-target="aiw-json-' . $rowIndex . '">show</span><span class="aiw-copy" data-copy="' . htmlspecialchars( $jsonRaw, ENT_QUOTES, 'UTF-8' ) . '">copy</span></div><div id="aiw-json-' . $rowIndex . '" class="aiw-json" style="display:none;"><pre>' . $json . '</pre></div></td></tr>';
		}
		echo '</tbody></table>';

		if ( $total_pages > 1 && function_exists( 'admin_url' ) ) {
			echo '<div class="tablenav"><div class="tablenav-pages">Page: ';
			for ( $p = 1; $p <= $total_pages; $p++ ) {
				if ( $p == $page ) {
					echo '<span class="current" style="margin:0 4px;">' . $p . '</span>';
					continue;
				}
				$query          = $_GET;
				$query[ 'paged' ] = $p;
				$query[ 'page' ]  = self::PAGE_SLUG;
				$url            = admin_url( 'options-general.php?' . http_build_query( $query ) );
				echo '<a style="margin:0 4px;" href="' . $this->esc_url( $url ) . '">' . $p . '</a>';
			}
			echo '</div></div>';
		}

		$js = '(function(){';
		$js .= 'const d=document;';
		$js .= 'function toggle(el){const id=el.getAttribute("data-target"),box=d.getElementById(id);if(!box)return;if(box.style.display==="none"){box.style.display="block";el.textContent="hide";}else{box.style.display="none";el.textContent="show";}}';
		$js .= 'd.addEventListener("click",function(e){var t=e.target;';
		$js .= 'if(t.classList.contains("aiw-toggle")){toggle(t);}';
		$js .= 'if(t.classList.contains("aiw-copy")){navigator.clipboard.writeText(t.getAttribute("data-copy"));t.textContent="copied";setTimeout(function(){t.textContent="copy";},1500);}';
		$js .= 'if(t.id==="aiw-expand-all"){d.querySelectorAll(".aiw-toggle").forEach(function(x){var id=x.getAttribute("data-target"),box=d.getElementById(id);if(box&&box.style.display==="none"){toggle(x);}});}';
		$js .= 'if(t.id==="aiw-collapse-all"){d.querySelectorAll(".aiw-toggle").forEach(function(x){var id=x.getAttribute("data-target"),box=d.getElementById(id);if(box&&box.style.display!=="none"){toggle(x);}});}';
		$js .= '});})();';
		echo '<script>' . $js . '</script>';
		echo '<p style="margin-top:1em;"><em>Remove viewer: delete src/Admin/MockViewer.php and registration code.</em></p>';
		echo '</div>';
	}
}
