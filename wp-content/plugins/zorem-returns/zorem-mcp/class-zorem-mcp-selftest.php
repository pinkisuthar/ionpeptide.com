<?php
// phpcs:disable PluginCheck.Security.DirectDB.UnescapedDBParameter -- every query in this file binds its values through wpdb->prepare(); the parameter the sniff flags is the table prefix or a placeholder string, both of which are safe.
// phpcs:disable WordPress.WP.I18n.TextDomainMismatch -- this library owns the 'zorem-mcp' domain and loads it itself; Plugin Check compares against the host plugin's folder name, which is a different plugin every time this folder ships.
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals -- this package is shared across the Zorem plugin family; its own zorem_mcp_ prefix is the point.
/**
 * Zorem MCP - does the link actually work from outside?
 *
 * WHY THIS EXISTS
 *
 * A merchant pastes their link into Claude and Claude says 403. The link is fine; the
 * store's own security plugin refused the request. Nothing on the AI Assistant screen can
 * see that, because the screen only knows what happens INSIDE WordPress -- and a firewall
 * that runs before WordPress is, by design, invisible from in here.
 *
 * So this calls the store's own link and reports what came back. That catches the common
 * failures outright -- a link that was never issued, a route that is off, a server error.
 *
 * It does NOT settle the firewall question, and must not pretend to. The call goes from the
 * server to its own address, which on most hosts never leaves the machine; and a firewall
 * that judges callers by address or user agent has no quarrel with its own server. A pass
 * here therefore means "the link works", not "an outside service can reach it" -- and when
 * a security plugin is installed, the result says exactly that.
 *
 * WHAT IT CANNOT DO
 *
 * It cannot fix the block. A plugin cannot talk its way past a firewall protecting the
 * application it is part of -- Wordfence's WAF runs as an auto_prepend_file and All In One
 * WP Security writes .htaccess rules, so both answer before a single line of this plugin
 * has run. Pretending otherwise would mean shipping a "fix" that quietly does nothing.
 *
 * What it can do is turn "Claude says 403 and I have no idea why" into "your security
 * plugin blocked it, here is the path to allow" -- which is the difference between a
 * support ticket and a merchant fixing it in a minute.
 *
 * @package Zorem_MCP
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Zorem_MCP_Selftest {

	/**
	 * AJAX action the settings screen calls.
	 */
	const AJAX = 'zorem_mcp_selftest';

	/**
	 * The daily check's hook.
	 */
	const CHECK_HOOK = 'zorem_mcp_connection_check';

	/**
	 * Remembers the last fault written to the log, so the same one is not written daily.
	 */
	const LOGGED = 'zorem_mcp_logged_fault';

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'wp_ajax_' . self::AJAX, array( __CLASS__, 'ajax_run' ) );
		add_action( self::CHECK_HOOK, array( __CLASS__, 'scheduled_check' ) );
		add_action( 'admin_init', array( __CLASS__, 'schedule' ) );
	}

	/**
	 * Keep the daily check on the calendar.
	 *
	 * @return void
	 */
	public static function schedule() {
		if ( ! wp_next_scheduled( self::CHECK_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CHECK_HOOK );
		}
	}

	/**
	 * Run the check without anybody asking, and write down what it found.
	 *
	 * The reason this is not left to the button: a merchant only presses the button once
	 * they already suspect something, and the failure this catches gives them nothing to
	 * suspect. Their assistant connects, lists its tools, and simply cannot be signed into.
	 * Nothing appears in any log, because the refusal happens in Apache and this plugin
	 * never runs. Checking on a schedule is the only way the store finds out on its own.
	 *
	 * @return void
	 */
	public static function scheduled_check() {
		if ( ! Zorem_MCP_Settings::enabled() ) {
			return;
		}

		self::log( self::run() );
	}

	/**
	 * Write a verdict into the audit log.
	 *
	 * Only the bad ones, and only when the same thing is not already the most recent entry:
	 * a daily check that logs "still fine" every day buries the entry that matters, and one
	 * that logs the same fault every day does the same thing more slowly.
	 *
	 * @param array $result A verdict from run().
	 * @return void
	 */
	protected static function log( $result ) {
		if ( ! class_exists( 'Zorem_MCP_Audit' ) || empty( $result['status'] ) ) {
			return;
		}

		if ( in_array( $result['status'], array( 'ok', 'off' ), true ) ) {
			return;
		}

		$message = trim( $result['message'] . ' ' . ( isset( $result['detail'] ) ? $result['detail'] : '' ) );

		if ( get_transient( self::LOGGED ) === $result['status'] ) {
			return;
		}

		set_transient( self::LOGGED, $result['status'], DAY_IN_SECONDS );

		Zorem_MCP_Audit::record(
			'connection-check',
			'zorem-link',
			new WP_Error( 'zorem_mcp_' . str_replace( '-', '_', $result['status'] ), $message ),
			__( 'This store', 'zorem-mcp' )
		);
	}

	/**
	 * Run the test and answer the screen.
	 *
	 * @return void
	 */
	public static function ajax_run() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'You are not allowed to do that.', 'zorem-mcp' ) ), 403 );
		}

		check_ajax_referer( Zorem_MCP_Woo_Key::NONCE, Zorem_MCP_Woo_Key::NONCE . '_nonce' );

		$result = self::run();

		// Firewall links only where a firewall is actually implicated. Offered against a
		// verdict they cannot fix -- a connection that simply went quiet, say -- they read
		// as the suggested next move and send the merchant into Wordfence for nothing.
		$firewall = array( 'blocked', 'nothing-arrived', 'unreachable', 'error', 'agent-filtered', 'closed-door' );
		$links    = in_array( $result['status'], $firewall, true ) ? self::fix_links() : array();

		// A verdict that already names the culprit does not need the other plugins offered
		// beside it, and a blank refusal means no plugin sent it -- so pointing at any of
		// their screens is pointing away from the answer.
		if ( in_array( $result['status'], array( 'agent-filtered', 'closed-door' ), true ) ) {
			// Blocklists are written from settings screens, so those stay. The blocked-request
			// log goes: the call this verdict describes was refused seconds ago and is already
			// in it, so opening it tells the merchant nothing they have not just been told --
			// and when the refusal was unsigned, that log will not even hold it.
			$links = array_values(
				array_filter(
					$links,
					static function ( $link ) {
						return empty( $link['log'] );
					}
				)
			);
		}

		$result['fix'] = ! empty( $result['fixable'] );

		unset( $result['server'], $result['fixable'] );

		$result['links'] = $links;

		// Pressing the button leaves the same line in the log a scheduled check would, so a
		// merchant who tests today and opens a ticket next week has the finding on record
		// rather than a memory of a message that has long since scrolled away.
		self::log( $result );

		wp_send_json_success( $result );
	}

	/**
	 * Call our own link from outside and work out what happened.
	 *
	 * @return array {
	 *     @type bool   $ok      Whether an AI client would get through.
	 *     @type string $status  'ok' | 'blocked' | 'agent-filtered' | 'unreachable' | 'off' | 'error'
	 *     @type string $message What to tell the merchant.
	 *     @type string $culprit Name of the security plugin, when one is active.
	 * }
	 */
	public static function run() {
		$url = Zorem_MCP_Server::endpoint_url();

		if ( '' === $url ) {
			return array(
				'ok'      => false,
				'status'  => 'off',
				'message' => __( 'The connection is switched off.', 'zorem-mcp' ),
				'detail'  => __( 'Switch it on, then test.', 'zorem-mcp' ),
				'culprit' => '',
			);
		}

		$response = self::probe( $url );

		$culprit = self::security_plugin();

		// A transport failure is not a firewall: the request never got an answer at all.
		if ( is_wp_error( $response ) ) {
			return array(
				'ok'      => false,
				'status'  => 'unreachable',
				'message' => __( 'The test could not run: this store cannot call its own link.', 'zorem-mcp' ),
				/* translators: %s: the error WordPress reported. */
				'detail'  => sprintf( __( 'Your host reported: %s. That is a networking problem on the server, not your link and not your AI assistant.', 'zorem-mcp' ), $response->get_error_message() ),
				'culprit' => $culprit,
			);
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		if ( 200 === $code && isset( $body['result']['serverInfo'] ) ) {
			// A pass proves the link works. It does NOT prove an outside service can reach
			// it: this request came from the server to its own address, which on most hosts
			// never leaves the machine, and a firewall that judges callers by their address
			// or their user agent has no reason to object to its own server. So when
			// something is guarding the door, say what was and was not tested rather than
			// handing back a clean bill of health the test cannot support.
			// The link works. Whether an AI service can REACH it is a different question,
			// and the record of what has actually arrived answers it better than this test
			// can -- a call from the server to its own address usually never leaves the
			// machine, so it proves nothing about outside callers.
			return array_merge(
				array( 'ok' => true ),
				self::verdict_from_attempts( $culprit, $url )
			);
		}

		// 403 with no JSON-RPC body is the signature of something in front of WordPress:
		// this server never answers a valid POST that way.
		if ( 403 === $code || 406 === $code || ( $code >= 500 && ! is_array( $body ) ) ) {
			list( $headline, $detail ) = self::blocked_message( $culprit, $code );

			return array(
				'ok'      => false,
				'status'  => 'blocked',
				'message' => $headline,
				'detail'  => $detail,
				'culprit' => $culprit,
			);
		}

		return array(
			'ok'      => false,
			'status'  => 'error',
			/* translators: %d: the HTTP status code the link answered with. */
			'message' => sprintf( __( 'The link answered with %d instead of the expected reply.', 'zorem-mcp' ), $code ),
			'detail'  => __( 'Not a firewall - something answered, it just answered wrongly. Send that number to support; it says what went wrong.', 'zorem-mcp' ),
			'culprit' => $culprit,
		);
	}

	/**
	 * Call the link once, optionally pretending to be somebody else.
	 *
	 * @param string $url   Endpoint to call.
	 * @param string $agent User agent to send, or '' for WordPress's own.
	 * @return array|WP_Error
	 */
	protected static function probe( $url, $agent = '' ) {
		$args = array(
			'timeout' => 20,
			'headers' => array( 'Content-Type' => 'application/json' ),
			'body'    => wp_json_encode(
				array(
					'jsonrpc' => '2.0',
					'id'      => 1,
					'method'  => 'initialize',
					'params'  => array(
						'protocolVersion' => '2025-06-18',
						'capabilities'    => new stdClass(),
						'clientInfo'      => array(
							'name'    => 'Zorem link test',
							'version' => '1.0',
						),
					),
				)
			),
		);

		if ( '' !== $agent ) {
			$args['user-agent'] = $agent;
		}

		return wp_remote_post( $url, $args );
	}

	/**
	 * Ask the same question twice under two names, and see whether the name mattered.
	 *
	 * The failure that brings merchants here is invisible to a single probe, because a probe
	 * sent under WordPress's own user agent is exactly the caller nothing objects to. Many
	 * hosts and security plugins ship a list of user agents to refuse -- the old
	 * scraper-blocking lists, which name Python and Perl HTTP libraries -- and the services
	 * behind AI assistants are written in those languages. The link is fine, the firewall
	 * rule is fine, and the two of them together refuse the only caller that matters.
	 *
	 * So the test calls the link a second time under a name off those lists. One 200 and one
	 * 403 from the same URL, seconds apart, is proof rather than suspicion: it is not the
	 * link, not the path, not the token -- it is who the caller says it is. That is a
	 * different setting from the one a merchant is usually sent to, which is why saying so
	 * matters: URL allowlists do not fix it.
	 *
	 * Nothing is claimed when both calls agree. A refusal of both was already caught above,
	 * and a pass on both means user agent is not the axis being judged.
	 *
	 * @param string $url Endpoint to call.
	 * @return array|null Verdict when the name decided it, null otherwise.
	 */
	protected static function agent_filter_check( $url ) {
		// A name off the blocklists these rules are built from, and honest about what it is:
		// this call really is a link test, and a merchant reading their own traffic log
		// should find it labelled rather than have to work out who it was.
		$agent    = 'python-requests/2.31.0 (Zorem link test)';
		$response = self::probe( $url, $agent );

		if ( is_wp_error( $response ) ) {
			return null;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );

		if ( 403 !== $code && 406 !== $code ) {
			return null;
		}

		// Who actually said no, read off the refusal itself rather than off the plugin list.
		// A security plugin refuses with a page that says so; a rule in .htaccess, a host or
		// a CDN refuses with nothing at all. That difference is worth reading, because naming
		// every security plugin on the site sends the merchant to look inside the innocent
		// one -- and finding nothing there, they conclude the test is wrong.
		$body    = trim( (string) wp_remote_retrieve_body( $response ) );
		$culprit = '';

		// Whether the CDN in front of the site is the thing that refused this.
		//
		// Its presence is NOT the test. Every response from a site behind Cloudflare carries
		// its headers, refusals it had nothing to do with included, so reading those alone
		// blames the CDN for everything and sends merchants to the one place that cannot
		// help. A CDN that turns a request away says so in the page it returns; an empty
		// body did not come from one.
		$edge = '';

		if ( '' !== (string) wp_remote_retrieve_header( $response, 'cf-mitigated' )
			|| ( '' !== $body && false !== stripos( $body, 'cloudflare' ) ) ) {
			$edge = 'Cloudflare';
		}

		// Whether anything in WordPress could be responsible.
		//
		// Also not readable off one header: the link header only appears on some responses
		// even when everything is fine. So the question is asked of the site instead -- does
		// a plain file, which never touches WordPress, get through under the same name? If it
		// does, whatever is refusing only refuses WordPress, and lives inside it.
		$reached_wp = self::static_file_passes();

		foreach ( self::known_plugins() as $name => $sign ) {
			if ( false !== stripos( $body, $sign['needle'] ) ) {
				$culprit = $name;
				break;
			}
		}

		return array(
			'ok'      => false,
			'status'  => 'agent-filtered',
			'culprit' => $culprit,
			'server'  => ( '' === $culprit && '' === $body ),
			// Whether this is a problem the merchant can be spared going to look for.
			//
			// Not when a CDN turned the request away before WordPress saw it. The button
			// edits .htaccess, which that request never reaches, so offering it there is
			// offering a fix that is guaranteed to fail -- and the merchant spends their
			// confidence on it instead of on the one place that can actually help.
			'fixable' => class_exists( 'Zorem_MCP_Htaccess' )
				&& Zorem_MCP_Htaccess::possible()
				&& ! Zorem_MCP_Htaccess::present()
				&& '' === $edge
				&& ! $reached_wp,
			'message' => __( 'Found it. Your server refuses callers by name.', 'zorem-mcp' ),
			'detail'  => self::agent_filter_detail( $culprit, $code, '' === $body, $edge, $reached_wp ),
		);
	}

	/**
	 * Whether a plain file gets through under a name the site refuses.
	 *
	 * The one question that separates a block inside WordPress from a block in front of it,
	 * and it costs one request. A file on disk is served by the web server with no PHP at
	 * all: if THAT is refused too, whatever is doing the refusing sits in front of everything
	 * and no plugin setting will change it. If it comes back fine while WordPress requests
	 * are refused, the refusal can only be coming from inside WordPress -- which is where the
	 * merchant's security plugins are, and where this can actually be fixed.
	 *
	 * @return bool
	 */
	protected static function static_file_passes() {
		$response = wp_remote_get(
			includes_url( 'js/wp-embed.min.js' ),
			array(
				'timeout'    => 15,
				'user-agent' => 'python-requests/2.31.0 (Zorem link test)',
			)
		);

		if ( is_wp_error( $response ) ) {
			return false;
		}

		return 200 === (int) wp_remote_retrieve_response_code( $response );
	}

	/**
	 * Say where to go, given what the refusal gave away about who sent it.
	 *
	 * @param string $culprit    Plugin that signed the refusal, or an empty string.
	 * @param int    $code       HTTP status.
	 * @param bool   $silent     Whether the refusal carried no page at all.
	 * @param string $edge       CDN that handled it, or an empty string.
	 * @param bool   $reached_wp Whether WordPress generated the refusal.
	 * @return string
	 */
	protected static function agent_filter_detail( $culprit, $code, $silent, $edge = '', $reached_wp = true ) {
		// One instruction, not a diagnosis. Everything this method knows is interesting, and
		// almost none of it is the merchant's to act on: they own a shop, and what they need
		// off this screen is which list to open and what to take out of it. The forensics --
		// which layer answered, whether anything signed it -- earn a clause each at most.
		$lead = sprintf(
			/* translators: 1: HTTP status, 2: the user agent that was refused. */
			__( 'The same request was refused (%1$d) when it called itself %2$s, which is the kind of name AI services use.', 'zorem-mcp' ),
			$code,
			'python-requests'
		);

		$closing = __( 'Allowing the URL will not help.', 'zorem-mcp' );

		// Checked first, because it settles the question. A refusal that never reached
		// WordPress cannot have come from a plugin or from .htaccess, however many of either
		// the store has -- and sending the merchant to look through them would cost them an
		// afternoon and end where it started.
		if ( '' !== $edge ) {
			return $lead . ' ' . sprintf(
				/* translators: %s: the CDN's name, e.g. Cloudflare. */
				__( '%1$s turned it away before it reached your site, so nothing here can undo it. In %1$s, look under Security for bot protection — Bot Fight Mode is the usual one — and turn that off, or add a rule that skips it for this address.', 'zorem-mcp' ),
				$edge
			) . ' ' . $closing;
		}

		// A plain file gets through while WordPress requests do not, so the refusal is coming
		// from something running inside WordPress. That narrows it to the plugins the
		// merchant can actually open -- and sometimes to one switch inside one of them.
		if ( $reached_wp ) {
			$switch = self::named_switch();

			if ( $switch ) {
				return $lead . ' ' . sprintf(
					/* translators: 1: the setting's name, 2: where to find it. */
					__( 'It is this one: turn off %1$s, under %2$s. That is the only change needed — every other rule there keeps working, and so does everyone already connected.', 'zorem-mcp' ),
					'"' . $switch['label'] . '"',
					$switch['where']
				);
			}

			return $lead . ' ' . __( 'A plain file on this site is served to that same caller without complaint, so the refusal is coming from inside WordPress — a security plugin, not your host and not .htaccess. Turn your security plugins off one at a time and test again; the one that lets it through is the one with the list.', 'zorem-mcp' ) . ' ' . $closing;
		}

		if ( '' !== $culprit ) {
			$where = sprintf(
				/* translators: %s: security plugin name. */
				__( '%s sent the refusal: open it and take the Python and Perl entries out of its bad-bot or user agent list.', 'zorem-mcp' ),
				$culprit
			);
		} elseif ( $silent ) {
			$writer = self::htaccess_writer();

			$where = '' !== $writer
				? sprintf(
					/* translators: %s: the security plugin that writes .htaccess rules. */
					__( 'Nothing signed the refusal, so it came from .htaccess — and on this store %s is what writes rules there. Open it, find the bad-bot or user agent list, and take the Python and Perl entries out of it.', 'zorem-mcp' ),
					$writer
				)
				: __( 'Nothing signed it, so it is an .htaccess rule or your host. Find the bad-bot or user agent list and take the Python and Perl entries out of it.', 'zorem-mcp' );
		} else {
			$where = __( 'Find your bad-bot or user agent list, in a security plugin or at your host, and take the Python and Perl entries out of it.', 'zorem-mcp' );
		}

		return $lead . ' ' . $where . ' ' . $closing;
	}

	/**
	 * Read the inbound record and say what it shows.
	 *
	 * This is the part that answers "what went wrong and where". Three cases, and they are
	 * genuinely different problems:
	 *
	 *   Something arrived and was answered  -- the link works and callers are getting in.
	 *   Something arrived and was refused   -- the link reaching us is not the current one.
	 *                                          Reconnect with the link on this screen.
	 *   Nothing arrived at all              -- either nothing has been connected yet, or a
	 *                                          request that never reached WordPress was
	 *                                          stopped in front of it.
	 *
	 * @param string $culprit Security plugin name, or an empty string.
	 * @param string $url     The endpoint, for the further probing the last case needs.
	 * @return array
	 */
	protected static function verdict_from_attempts( $culprit, $url ) {
		$attempts = Zorem_MCP_Settings::attempts();
		$outside  = array();

		/*
		 * Only callers that could be somebody's AI assistant.
		 *
		 * Dropped: the store talking to itself, this test's own calls, and link-preview bots.
		 * That last group is new and it broke this badly. The connection link is an address
		 * rather than a password now, so it gets pasted into chat -- and every chat app sends
		 * a bot to fetch what was pasted. Those fetches carry no credential and are turned
		 * away, correctly, and this screen read one of them as the merchant's own assistant
		 * and told them their AI app was holding an old link. It was Slack.
		 */
		foreach ( $attempts as $attempt ) {
			if ( function_exists( 'zorem_mcp_is_caller' ) && ! zorem_mcp_is_caller( $attempt['agent'] ) ) {
				continue;
			}

			$outside[] = $attempt;
		}

		$path = '/wp-json/' . Zorem_MCP_Server::REST_NS . '/';

		if ( empty( $outside ) ) {
			// Before reporting silence, check the other door.
			//
			// A store can be using WooCommerce's own MCP route and nothing else -- many were,
			// long before this plugin offered a link. Their assistant works, their tools
			// answer, and this screen knew nothing about any of it, so it told them nothing
			// had ever reached them. That is a screen calling a working store broken.
			$elsewhere = class_exists( 'Zorem_MCP_Audit' ) ? Zorem_MCP_Audit::clients_seen( 'woocommerce-mcp' ) : array();

			if ( ! empty( $elsewhere ) ) {
				$when = max( $elsewhere );
				$who  = array_search( $when, $elsewhere, true );

				return array(
					'status'  => 'other-route',
					'culprit' => '',
					'message' => __( 'Nothing has used this link — but your tools are being used, through WooCommerce MCP.', 'zorem-mcp' ),
					'detail'  => sprintf(
						/* translators: 1: the app's name, 2: how long ago. */
						__( 'The last was %1$s, %2$s ago. That route works and there is nothing to fix. Use the link on this screen only if you also want to connect from claude.ai or a phone, which WooCommerce MCP cannot do.', 'zorem-mcp' ),
						'' !== (string) $who ? $who : __( 'an AI client', 'zorem-mcp' ),
						human_time_diff( (int) $when, time() )
					),
				);
			}

			$blocked = self::wordfence_block();

			if ( $blocked ) {
				return array(
					'status'  => 'nothing-arrived',
					'culprit' => 'Wordfence',
					'message' => sprintf(
						/* translators: %s: how long ago. */
						__( 'Found it. Wordfence blocked a request to your link %s ago.', 'zorem-mcp' ),
						human_time_diff( (int) $blocked['ctime'], time() )
					),
					'detail'  => sprintf(
						/* translators: 1: the rule Wordfence reported, 2: the caller's user agent, 3: URL path to allow. */
						__( 'Its reason: %1$s. The caller was %2$s. In Wordfence, allow %3$s or allow that caller, then test again.', 'zorem-mcp' ),
						'' !== (string) $blocked['actionDescription'] ? $blocked['actionDescription'] : $blocked['action'],
						'' !== (string) $blocked['UA'] ? $blocked['UA'] : __( 'not recorded', 'zorem-mcp' ),
						$path
					),
				);
			}

			// Only now is it worth asking whether the door judges callers by name. Asked
			// earlier -- of a store whose assistant is connected and working -- the same true
			// answer becomes a false alarm: a rule can refuse the name this probe borrows and
			// still let the real assistant through, which is exactly what happens when the
			// rule only caught the connector check. Real traffic getting in outranks anything
			// a synthetic call can suggest, so the probe speaks only into a silence.
			$filtered = self::agent_filter_check( $url );

			if ( is_array( $filtered ) ) {
				return $filtered;
			}

			if ( '' !== $culprit ) {
				return array(
					'status'  => 'nothing-arrived',
					'culprit' => $culprit,
					'message' => __( 'Your link works, but nothing from outside has ever reached it.', 'zorem-mcp' ),
					'detail'  => sprintf(
						/* translators: 1: security plugin name, 2: URL path to allow. */
						__( 'Have you connected an AI assistant yet? If not, nothing is wrong - connect it and test again. If you have, %1$s is the likely cause: open its blocked-request log, find the refusal, and allow what it names. The path is %2$s.', 'zorem-mcp' ),
						$culprit,
						$path
					),
				);
			}

			return array(
				'status'  => 'nothing-arrived',
				'culprit' => '',
				'message' => __( 'Your link works, but nothing from outside has ever reached it.', 'zorem-mcp' ),
				'detail'  => sprintf(
					/* translators: %s: URL path to allow. */
					__( 'Have you connected an AI assistant yet? If not, nothing is wrong - connect it and test again. If you have, something in front of WordPress is stopping it: your host, your CDN, or a security rule. The path to allow is %s.', 'zorem-mcp' ),
					$path
				),
			);
		}

		// Did anything actually get through? That, not the most recent line in the record, is
		// what separates a working connection from a broken one.
		//
		// It used to be read off the newest attempt alone, which was fair while the link was
		// the credential: a refusal then meant somebody was holding the wrong one. It is not
		// fair now. Whoever has not signed in yet is refused as a matter of course, so a
		// store where everything works still shows refusals -- and one of them being newest
		// is a matter of timing, not of health.
		$through = null;

		foreach ( $outside as $attempt ) {
			if ( 'ok' === $attempt['outcome'] ) {
				$through = $attempt;
				break;
			}
		}

		// Asked whatever the record shows, because the two questions are not the same one.
		//
		// "Is anything working" and "can anybody new connect" have different answers, and a
		// store can have a healthy connection and a closed door at the same time: the people
		// already signed in keep working on tokens they were issued before the door shut.
		// Only checking when nothing works meant the screen went green the moment one person
		// connected and stayed green while the store quietly refused everyone after them.
		$filtered = self::agent_filter_check( $url );

		if ( is_array( $filtered ) ) {
			if ( ! $through ) {
				return $filtered;
			}

			// Something IS working, so this is not a broken store -- it is a store that has
			// stopped accepting new arrivals. Said plainly, and in that order, because the
			// merchant's first question on seeing a warning is whether what they have has
			// just broken.
			//
			// "Nobody new can connect" was the first wording and it was too strong. An
			// assistant that has introduced itself to this store once does not have to do it
			// again, and the introduction is the only part that gets refused -- the sign-in
			// itself happens in the person's own browser, which nothing here objects to. So
			// somebody who has connected before can reconnect, and only a first-time arrival
			// is actually turned away. A warning that overstates its case gets tested once,
			// found wrong, and then ignored when it is right.
			return array(
				'status'  => 'closed-door',
				'culprit' => isset( $filtered['culprit'] ) ? $filtered['culprit'] : '',
				'fixable' => ! empty( $filtered['fixable'] ),
				'message' => __( 'Working for whoever is already connected — but somebody connecting for the first time will be turned away.', 'zorem-mcp' ),
				'detail'  => $filtered['detail'] . ' ' . __( 'Anyone whose assistant has connected to this store before can still reconnect: the step that gets refused only happens the first time.', 'zorem-mcp' ),
			);
		}

		$last    = $through ? $through : $outside[0];
		$when    = human_time_diff( (int) $last['time'], time() );
		$refused = ! $through;

		if ( $refused ) {
			return array(
				'status'  => 'refused',
				'culprit' => '',
				'message' => sprintf(
					/* translators: %s: how long ago. */
					__( 'Callers are reaching your link but none has been let in. The last was %s ago.', 'zorem-mcp' ),
					$when
				),
				'detail'  => sprintf(
					/* translators: %s: the caller's address. */
					__( 'If you have not finished connecting an AI assistant yet, this is what that looks like — press Connect in it and sign in. If you have, it is holding an older link: copy the link above into it again. The last caller was %s.', 'zorem-mcp' ),
					$last['ip']
				),
			);
		}

		// Deliberately not the word "Working". This test can prove two things -- the link
		// answers, and callers from outside are not being turned away -- and it cannot prove
		// the third thing the merchant actually came to ask, which is whether their assistant
		// is still connected. That is held at the other end: removing a connector in claude.ai
		// leaves no mark here, so the last success would otherwise sit on this screen being
		// green for a connection that was taken away months ago.
		//
		// An expiry on the evidence was the first attempt at this and was the wrong shape: it
		// only made the wrong answer arrive later. A sentence that claims exactly what was
		// measured, and names who owns the rest, cannot go stale at all.
		return array(
			'status'  => 'ok',
			'culprit' => '',
			'message' => __( 'Your link answers correctly, and callers from outside are getting through.', 'zorem-mcp' ),
			'detail'  => sprintf(
				/* translators: 1: the app's name or user agent, 2: how long ago. */
				__( 'The last was %1$s, %2$s ago. Whether your AI assistant is still connected is held at its end, not here - if it reports an error, add the link to it again.', 'zorem-mcp' ),
				'' !== $last['agent'] ? $last['agent'] : __( 'an AI client', 'zorem-mcp' ),
				$when
			),
		);
	}

	/**
	 * The most recent request Wordfence refused for this plugin's path, if it kept one.
	 *
	 * Read straight out of its own table. Wordfence records every refusal with the URL, the
	 * caller and the rule that fired, which is exactly the evidence this screen is missing --
	 * and reading it is the difference between "something is blocking you" and "this rule
	 * blocked this caller at this time".
	 *
	 * Guarded at every step because it is somebody else's table: absent when Wordfence is
	 * not installed, and free to change shape when it is.
	 *
	 * @return array|null
	 */
	protected static function wordfence_block() {
		global $wpdb;

		if ( ! class_exists( 'wordfence' ) && ! defined( 'WORDFENCE_VERSION' ) ) {
			return null;
		}

		$table = $wpdb->base_prefix . 'wfHits';

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- another plugin's table, read once on a button press.
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
			return null;
		}

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT ctime, UA, action, actionDescription FROM {$table} WHERE URL LIKE %s AND action LIKE %s ORDER BY ctime DESC LIMIT 1",
				'%' . $wpdb->esc_like( '/wp-json/' . Zorem_MCP_Server::REST_NS ) . '%',
				'blocked%'
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return is_array( $row ) ? $row : null;
	}

	/**
	 * What to tell a merchant whose firewall refused the call.
	 *
	 * Named plugins get named instructions. A merchant told "something is blocking it" has to
	 * go looking; a merchant told which plugin and which path has a minute's work.
	 *
	 * @param string $culprit Security plugin name, or an empty string.
	 * @param int    $code    HTTP status that came back.
	 * @return array{0:string,1:string} Headline and detail.
	 */
	protected static function blocked_message( $culprit, $code ) {
		$path = '/wp-json/' . Zorem_MCP_Server::REST_NS . '/';

		if ( '' !== $culprit ) {
			return array(
				sprintf(
					/* translators: 1: security plugin name, 2: HTTP status. */
					__( '%1$s refused this request (%2$d) before it reached the plugin.', 'zorem-mcp' ),
					$culprit,
					$code
				),
				sprintf(
					/* translators: %s: URL path to allow. */
					__( 'It will refuse your AI assistant the same way. In its firewall settings, allow the path %s. If that does not do it, the rule is matching something other than the path - look for user agent or bot blocking too, and read its blocked-request log for the exact rule.', 'zorem-mcp' ),
					$path
				),
			);
		}

		return array(
			sprintf(
				/* translators: %d: HTTP status. */
				__( 'Something refused this request (%d) before it reached the plugin.', 'zorem-mcp' ),
				$code
			),
			sprintf(
				/* translators: %s: URL path to allow. */
				__( 'It is none of the security plugins this test knows, so it is your host, your CDN, or a rule in .htaccess. Ask them to allow the path %s, and to check whether they filter callers by name.', 'zorem-mcp' ),
				$path
			),
		);
	}

	/**
	 * Where a merchant should go to fix this, per plugin.
	 *
	 * Telling someone "allow this path in your security plugin" and leaving them to find the
	 * screen is most of the work still undone -- these settings are buried, and a merchant
	 * who has to go hunting is a merchant who opens a support ticket instead.
	 *
	 * @return array List of array( 'label', 'url' ), the log screens marked with 'log'.
	 */
	public static function fix_links() {
		$links = array();

		// First, because it is the only one that goes to a specific switch rather than to a
		// plugin's front door. A merchant who has just been told which setting to turn off
		// should not then have to find it.
		$switch = self::named_switch();

		if ( $switch ) {
			$links[] = array(
				'label' => sprintf(
					/* translators: %s: the setting's name. */
					__( 'Open the %s setting', 'zorem-mcp' ),
					$switch['label']
				),
				'url'   => $switch['url'],
			);
		}

		if ( class_exists( 'wordfence' ) || defined( 'WORDFENCE_VERSION' ) ) {
			$links[] = array(
				'label' => __( 'Open Wordfence firewall settings', 'zorem-mcp' ),
				'url'   => admin_url( 'admin.php?page=WordfenceWAF&subpage=waf_options' ),
			);
			$links[] = array(
				'label' => __( 'See what Wordfence blocked', 'zorem-mcp' ),
				'url'   => admin_url( 'admin.php?page=WordfenceTools&subpage=livetraffic' ),
				'log'   => true,
			);
		}

		if ( class_exists( 'AIOWPSecurity' ) || defined( 'AIO_WP_SECURITY_VERSION' ) ) {
			$links[] = array(
				'label' => __( 'Open All In One WP Security firewall', 'zorem-mcp' ),
				'url'   => admin_url( 'admin.php?page=aiowpsec_firewall' ),
			);
		}

		if ( class_exists( 'SucuriScan' ) || defined( 'SUCURISCAN_VERSION' ) ) {
			$links[] = array(
				'label' => __( 'Open Sucuri firewall settings', 'zorem-mcp' ),
				'url'   => admin_url( 'admin.php?page=sucuriscan_firewall' ),
			);
		}

		if ( class_exists( 'ITSEC_Core' ) || defined( 'ITSEC_CORE_VERSION' ) ) {
			$links[] = array(
				'label' => __( 'Open Solid Security settings', 'zorem-mcp' ),
				'url'   => admin_url( 'admin.php?page=itsec' ),
			);
		}

		if ( class_exists( 'NinjaFirewall' ) || defined( 'NFW_ENGINE_VERSION' ) ) {
			$links[] = array(
				'label' => __( 'Open NinjaFirewall settings', 'zorem-mcp' ),
				'url'   => admin_url( 'admin.php?page=nfsubwaf' ),
			);
		}

		return $links;
	}

	/**
	 * The exact switch to turn off, when a security plugin's own settings name one.
	 *
	 * WHY READ THEIR SETTINGS
	 *
	 * "Look for a bad-bot or user agent list" is a fair instruction and a poor one. These
	 * plugins have dozens of screens; the merchant has to recognise which of them the
	 * sentence meant, and the one that matters here is four levels deep behind a name --
	 * "nG firewall rules" -- that gives no hint of what it does.
	 *
	 * The setting is stored in the open, so it can simply be looked at. If it is on, this
	 * screen can name it, say where it is, and say what turning it off will do -- and the
	 * merchant does none of the recognising.
	 *
	 * Written defensively against a shape that is not ours: the option is matched by pattern
	 * rather than by an exact key, so a rename in their next release costs this a mention
	 * rather than a fatal.
	 *
	 * @return array{label:string,where:string,url:string}|null
	 */
	protected static function named_switch() {
		// Read from the plugin's own firewall config, by the key its own rule reads.
		//
		// Guessing was tried first and was wrong twice over: the setting is called
		// aiowps_6g_block_agents -- not "user_agents", however it is labelled on screen -- and
		// it does not live in the plugin's options at all. Its firewall keeps a config of its
		// own, on disk, and publishes it as a global. So this asks the same object their rule
		// asks, which is the only reading that cannot drift from what the rule actually does.
		if ( ! isset( $GLOBALS['aiowps_firewall_config'] )
			|| ! is_object( $GLOBALS['aiowps_firewall_config'] )
			|| ! method_exists( $GLOBALS['aiowps_firewall_config'], 'get_value' ) ) {
			return null;
		}

		if ( ! $GLOBALS['aiowps_firewall_config']->get_value( 'aiowps_6g_block_agents' ) ) {
			return null;
		}

		return array(
			'label' => __( 'Block user-agents', 'zorem-mcp' ),
			'where' => __( 'All In One WP Security → Firewall → PHP rules → nG firewall rules', 'zorem-mcp' ),
			'url'   => admin_url( 'admin.php?page=aiowpsec_firewall&tab=php-rules&subtab=ng' ),
		);
	}

	/**
	 * Which installed security plugin writes rules into .htaccess.
	 *
	 * An unsigned refusal came from Apache, which means a rule in a file -- and the merchant
	 * is about to be sent looking for it. Naming the plugin that put it there is the
	 * difference between a minute's work and an afternoon's, and it is knowable: only some of
	 * these plugins write .htaccess at all.
	 *
	 * @return string Plugin name, or an empty string when none of them is installed.
	 */
	protected static function htaccess_writer() {
		$writers = array(
			'All In One WP Security' => array( 'AIOWPSecurity', 'AIO_WP_SECURITY_VERSION' ),
			'NinjaFirewall'          => array( 'NinjaFirewall', 'NFW_ENGINE_VERSION' ),
			'iThemes / SolidWP'      => array( 'ITSEC_Core', 'ITSEC_CORE_VERSION' ),
		);

		foreach ( $writers as $name => $signs ) {
			if ( class_exists( $signs[0] ) || defined( $signs[1] ) ) {
				return $name;
			}
		}

		return '';
	}

	/**
	 * The security plugins this test can recognise, and how.
	 *
	 * Two ways of recognising them, for two different questions. `class` and `const` answer
	 * "is it installed here", which is a guess at the culprit. `needle` answers "did this
	 * refusal come from it", which is not a guess at all: these plugins sign their block
	 * pages, and reading the signature beats naming everything on the site and hoping.
	 *
	 * @return array Keyed by display name.
	 */
	protected static function known_plugins() {
		return array(
			'Wordfence'              => array( 'class' => 'wordfence', 'const' => 'WORDFENCE_VERSION', 'needle' => 'wordfence' ),
			'All In One WP Security' => array( 'class' => 'AIOWPSecurity', 'const' => 'AIO_WP_SECURITY_VERSION', 'needle' => 'all in one wp security' ),
			'Sucuri Security'        => array( 'class' => 'SucuriScan', 'const' => 'SUCURISCAN_VERSION', 'needle' => 'sucuri' ),
			'iThemes / SolidWP'      => array( 'class' => 'ITSEC_Core', 'const' => 'ITSEC_CORE_VERSION', 'needle' => 'solid security' ),
			'NinjaFirewall'          => array( 'class' => 'NinjaFirewall', 'const' => 'NFW_ENGINE_VERSION', 'needle' => 'ninjafirewall' ),
			'Cleantalk Security'     => array( 'class' => 'CleantalkSP', 'const' => 'SPBC_VERSION', 'needle' => 'cleantalk' ),
		);
	}

	/**
	 * Which security plugin is active, if one this list knows about is.
	 *
	 * Detection is by class or constant, not by file path, so a renamed plugin folder does
	 * not defeat it.
	 *
	 * @return string Plugin name, or an empty string.
	 */
	public static function security_plugin() {
		$found = array();

		foreach ( self::known_plugins() as $name => $sign ) {
			if ( class_exists( $sign['class'] ) || defined( $sign['const'] ) ) {
				$found[] = $name;
			}
		}

		return implode( __( ' and ', 'zorem-mcp' ), $found );
	}
}
