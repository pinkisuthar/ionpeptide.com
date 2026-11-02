<?php
defined( 'ABSPATH' ) || exit;
if ( ! is_array( $notifications_list ) || count( $notifications_list ) === 0 ) {
	return;
}

?>
<div class="wf_notification_wrap">
    <div class="inside">
		<?php
		foreach ( $notifications_list as $nkey => $nvalue ) {
			foreach ( $nvalue as $key => $value ) {
				$combined_class = [ $key, 'wf_notification_content_sec' ];
				if ( isset( $value['type'] ) && $value['type'] !== '' ) {
					$combined_class[] = $value['type'];
				}
				if ( isset( $value['class'] ) && ! empty( $value['class'] ) ) {
					$value['class'] = is_array( $value['class'] ) ? $value['class'] : explode( ' ', (string) $value['class'] );
					$value['class'] = array_filter( $value['class'] );
					$value['class'] = array_map( 'trim', $value['class'] );
					$combined_class = array_merge( $combined_class, $value['class'] );
				}

				?>
                <div class="<?php echo esc_attr( implode( ' ', $combined_class ) ); ?>" wf-noti-key="wf-<?php echo esc_attr( $key ); ?>" wf-noti-group="<?php echo esc_attr( $nkey ); ?>">
                    <div class="wf_overlay_active "></div>
					<?php
					echo '<div class="wf_notification_html"><p>' . wp_kses_post( $value['html'] ) . '</p></div>';


					if ( isset( $value['buttons'] ) && ( is_array( $value['buttons'] ) && count( $value['buttons'] ) > 0 ) ) {

						printf( '<div class="wf_notification_btn_wrap">' );
						foreach ( $value['buttons'] as $btn_key => $btn_val ) { // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable

							$btn_class = [];
							if ( isset( $btn_val['class'] ) && ! empty( $btn_val['class'] ) ) {
								$btn_val['class'] = is_array( $btn_val['class'] ) ? $btn_val['class'] : explode( ' ', (string) $btn_val['class'] );
								$btn_val['class'] = array_filter( $btn_val['class'] );
								$btn_val['class'] = array_map( 'trim', $btn_val['class'] );
								$btn_class        = $btn_val['class'];
							}

							if ( ! isset( $btn_val['name'] ) || $btn_val['name'] === '' ) {
								continue;
							}

							printf( ' <a href="%s" target="%s" class="%s">%s</a>', isset( $btn_val['url'] ) ? esc_url( $btn_val['url'] ) : '#', isset( $btn_val['target'] ) ? esc_attr( $btn_val['target'] ) : '_blank', esc_attr( implode( ' ', $btn_class ) ), esc_html( $btn_val['name'] ) );
						}

						printf( '</div>' );
					}

					?>
                    <div class="wf_notice_dismiss_link_wrap">
                        <a class="notice-dismiss" href="javascript:void(0)">
							<?php esc_html_e( 'Dismiss', 'wp-marketing-automations' ); // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch ?>
                        </a>
                    </div>
                    <div class="clearfix"></div>
                </div>
				<?php
			}
		}
		?>
    </div>
</div>
