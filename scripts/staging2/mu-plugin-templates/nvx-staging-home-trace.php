<?php
/**
 * NUVANX Staging Home Status Tracer — DIAGNOSTIC ONLY, MUST NOT MERGE TO MASTER.
 *
 * Token: __NVX_TRACE_TOKEN__
 *
 * Traces HTTP status across WordPress boot hooks for the canonical
 * boundary-verifier request. Ignores the fatal-capture UA so it does not
 * consume the trace before the primary verifier.
 */

$_nvx_ua = (string) ( $_SERVER['HTTP_USER_AGENT'] ?? '' );

// Ignore the secondary fatal-capture request introduced in #828.
if ( $_nvx_ua === 'NUVANX-Staging-Fatal-Capture/1.1' ) {
	return;
}

// Only activate for the primary boundary verifier.
if ( $_nvx_ua !== 'NUVANX-Staging-Boundary/1.2' ) {
	// Auto-cleanup if stale (> 5 min since deployment).
	$_nvx_mtime = @filemtime( __FILE__ );
	if ( false !== $_nvx_mtime && ( time() - (int) $_nvx_mtime ) > 300 ) {
		@unlink( __FILE__ );
	}
	return;
}

// This is the target request — self-destruct immediately.
@unlink( __FILE__ );

define( 'NVX_TRACE_RESULT_FILE', '/tmp/nvx-home-trace-__NVX_TRACE_TOKEN__.json' );

$nvx_trace_status    = 200;           // current tracked HTTP status
$nvx_trace_sh_calls  = [];            // status_header() call log
$nvx_trace_hooks     = [];            // hook → status at fire time

// Intercept status_header() calls (WordPress action since WP 3.2).
add_action(
	'status_header',
	static function ( $status_header, $code ) use ( &$nvx_trace_status, &$nvx_trace_sh_calls ) {
		$int = (int) $code;
		$nvx_trace_sh_calls[] = $int;
		$nvx_trace_status     = $int;
	},
	1,
	2
);

// Record which hooks fire and what the HTTP status is at that moment.
foreach ( [ 'init', 'wp_loaded', 'send_headers', 'wp', 'template_redirect', 'template_include', 'shutdown' ] as $_nvx_hook ) {
	add_action(
		$_nvx_hook,
		static function () use ( $_nvx_hook, &$nvx_trace_hooks, &$nvx_trace_status ) {
			$nvx_trace_hooks[ $_nvx_hook ] = $nvx_trace_status;
		},
		PHP_INT_MAX
	);
}

// Emit partial trace (hooks up to and including send_headers) in X-Robots-Tag.
// This header is visible to the external verifier even in a 500 response.
add_filter(
	'wp_headers',
	static function ( array $headers ) use ( &$nvx_trace_status, &$nvx_trace_sh_calls, &$nvx_trace_hooks ) {
		$sh_part    = empty( $nvx_trace_sh_calls ) ? 'none' : implode( '+', $nvx_trace_sh_calls );
		$hooks_part = [];
		foreach ( [ 'init', 'wp_loaded', 'wp' ] as $_h ) {
			if ( isset( $nvx_trace_hooks[ $_h ] ) ) {
				$hooks_part[] = $_h . ':' . $nvx_trace_hooks[ $_h ];
			}
		}
		$hook_str = $hooks_part ? implode( ',', $hooks_part ) : 'pending';

		$trace = 'nvx-hs=' . $nvx_trace_status . ';sh=' . $sh_part . ';pre=' . $hook_str;

		// Preserve existing X-Robots-Tag contract (noindex,nofollow from staging).
		$existing = $headers['X-Robots-Tag'] ?? '';
		$headers['X-Robots-Tag'] = ( '' !== $existing ? $existing . ', ' : '' ) . $trace;
		return $headers;
	},
	PHP_INT_MAX
);

// Write the complete trace (including post-render hooks) to a temp file
// so the CI can retrieve it via SSH after the request completes.
register_shutdown_function(
	static function () use ( &$nvx_trace_status, &$nvx_trace_sh_calls, &$nvx_trace_hooks ) {
		$payload = [
			'schema'              => 1,
			'final_http_status'   => $nvx_trace_status,
			'status_header_calls' => $nvx_trace_sh_calls,
			'hooks'               => $nvx_trace_hooks,
			'last_error'          => error_get_last(),
			'traced_at'           => date( 'c' ),
		];
		@file_put_contents( NVX_TRACE_RESULT_FILE, json_encode( $payload, JSON_UNESCAPED_SLASHES ) . "\n", LOCK_EX );
	}
);
