<?php
/**
 * "AI Assistant" tab — the shared Zorem MCP connection screen.
 *
 * The whole screen (Your MCP · Woo MCP · Audit Log) is drawn by the library's
 * zorem_mcp_render_screen(). This file only supplies the wording and the URL the
 * sidebar/pagination link back to. Assets are enqueued from the admin class's own
 * admin_enqueue_scripts hook (see Return_Exchange_Admin::admin_enqueue) — enqueuing
 * here would be too late and silently dropped.
 *
 * @package Zorem_Returns
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'zorem_mcp_render_screen' ) ) {
	echo '<div class="zui-card"><p>' . esc_html__( 'The Zorem MCP library is not loaded.', 'zorem-returns' ) . '</p></div>';
	return;
}

zorem_mcp_render_screen(
	array(
		'slug'     => Return_Exchange_MCP_Bridge::SLUG,
		'label'    => __( 'AI Assistant', 'zorem-returns' ),
		'title'    => __( 'Returns AI Assistant', 'zorem-returns' ),
		'base_url' => admin_url( 'admin.php?page=zorem-returns-exchanges&tab=ai-assistant' ),
		'words'    => array(
			'connect_title' => __( 'Connect your AI assistant to Returns', 'zorem-returns' ),
			'connect_sub'   => __( 'Paste one link into Claude (or any MCP client) and manage returns and exchanges by chat.', 'zorem-returns' ),
			'steps'         => array(
				__( 'Open claude.ai, go to Settings → Connectors.', 'zorem-returns' ),
				__( 'Choose "Add custom connector", give it any name, and paste the link above.', 'zorem-returns' ),
				__( 'Sign in with your store account when prompted.', 'zorem-returns' ),
				__( 'Ask: "Which return requests are waiting for approval?" or "Approve return #123."', 'zorem-returns' ),
			),
			'tools_title'   => __( 'Returns tools on this connection', 'zorem-returns' ),
			'tools_note'    => __( 'Write tools (approve, complete, create) are only offered when writing is switched on below.', 'zorem-returns' ),
		),
	)
);
