<?php
/**
 * Shared admin page that promotes a sister plugin: hero, screenshot with feature
 * bullets and a single install step. The page hides its own submenu item unless
 * it is the current rotating promo slot, and redirects into the plugin once active.
 *
 * @package WPCode
 */

/**
 * Class WPCode_Admin_Page_Plugin_Promo.
 */
abstract class WPCode_Admin_Page_Plugin_Promo extends WPCode_Admin_Page {

	/**
	 * Key in WPCode_Suggested_Plugins::all_plugins(), also the installer slug.
	 *
	 * @var string
	 */
	protected $plugin_key = '';

	/**
	 * Plugin display name.
	 *
	 * @var string
	 */
	protected $plugin_name = '';

	/**
	 * The action used for the nonce.
	 *
	 * @var string
	 */
	protected $action;

	/**
	 * The nonce name field.
	 *
	 * @var string
	 */
	protected $nonce_name;

	/**
	 * Only admins can install these plugins, and the plugins' own screens may need more than WPCode access.
	 *
	 * @var string
	 */
	protected $capability = 'manage_options';

	/**
	 * Constructor. Subclasses set page_title and menu_title before calling this.
	 */
	public function __construct() {
		$this->action     = $this->page_slug;
		$this->nonce_name = $this->page_slug . '_nonce';
		$this->hide_menu  = ! $this->is_current_promo();

		parent::__construct();
	}

	/**
	 * Whether this page is the item the rotating slot currently shows.
	 *
	 * @return bool
	 */
	protected function is_current_promo() {
		$rotation = isset( wpcode()->promo_rotation ) ? wpcode()->promo_rotation : null;

		return $rotation instanceof WPCode_Promo_Rotation && $rotation->get_current_slug() === $this->page_slug;
	}

	/**
	 * Redirect into the plugin when it is already active. Runs only on this page.
	 *
	 * @return void
	 */
	public function page_hooks() {
		add_action( 'admin_init', array( $this, 'maybe_redirect_to_plugin' ) );
	}

	/**
	 * Send the user to the plugin's own screen if it is active.
	 *
	 * @return void
	 */
	public function maybe_redirect_to_plugin() {
		$url = $this->get_plugin_admin_url();

		if ( ! empty( $url ) ) {
			wp_safe_redirect( $url );
			exit;
		}
	}

	/**
	 * Menu slugs of the promoted plugin's own screen, most specific edition first (Pro before Lite).
	 *
	 * @return string[]
	 */
	abstract protected function get_plugin_menu_slugs();

	/**
	 * Admin URL of the first registered screen among the plugin's menu slugs, empty string when none is.
	 * Menus register on admin_menu, which runs before admin_init on admin.php, so an inactive or deleted
	 * plugin registers nothing. Top-level menus register regardless of capability, which is why these
	 * pages require manage_options.
	 *
	 * @return string
	 */
	protected function get_plugin_admin_url() {
		if ( ! function_exists( 'menu_page_url' ) ) {
			return '';
		}

		foreach ( $this->get_plugin_menu_slugs() as $slug ) {
			$url = menu_page_url( $slug, false );

			if ( ! empty( $url ) ) {
				// menu_page_url() escapes for display; the redirect needs a plain ampersand.
				return wp_specialchars_decode( $url );
			}
		}

		return '';
	}

	/**
	 * Page headline.
	 *
	 * @return string
	 */
	abstract protected function get_headline();

	/**
	 * Paragraph under the headline.
	 *
	 * @return string
	 */
	abstract protected function get_description();

	/**
	 * Feature bullets shown next to the screenshot.
	 *
	 * @return string[]
	 */
	abstract protected function get_features();

	/**
	 * Screenshot thumbnail file name in admin/images.
	 *
	 * @return string
	 */
	abstract protected function get_screenshot_thumb();

	/**
	 * Full-size screenshot file name in admin/images, opened in the lightbox.
	 *
	 * @return string
	 */
	abstract protected function get_screenshot_full();

	/**
	 * Hero icon. Defaults to the plugin's icon from the installer allowlist; override to use an inline SVG icon.
	 *
	 * @return void
	 */
	protected function output_hero_icon() {
		$plugins = WPCode_Suggested_Plugins::all_plugins();
		$icon    = ! empty( $plugins[ $this->plugin_key ]['icon'] ) ? $plugins[ $this->plugin_key ]['icon'] : 'icon-' . $this->plugin_key . '.png';
		?>
		<img src="<?php echo esc_url( WPCODE_PLUGIN_URL . 'admin/images/' . $icon ); // phpcs:ignore PluginCheck.CodeAnalysis.ImageFunctions.NonEnqueuedImage ?>" width="90" height="90" alt="<?php echo esc_attr( $this->plugin_name ); ?>"/>
		<?php
	}

	/**
	 * These pages have no WPCode header.
	 *
	 * @return void
	 */
	public function output_header() {
	}

	/**
	 * No header bottom either.
	 *
	 * @return void
	 */
	public function output_header_bottom() {
	}

	/**
	 * The page output.
	 *
	 * @return void
	 */
	public function output_content() {
		$images_url = WPCODE_PLUGIN_URL . 'admin/images/';
		$page_class = str_replace( 'wpcode-', '', $this->page_slug );
		?>
		<div class="wpcode-plugin-page wpcode-plugin-page-<?php echo esc_attr( $page_class ); ?>">
			<div class="wpcode-plugin-page-image">
				<?php $this->output_hero_icon(); ?>
			</div>
			<div class="wpcode-plugin-page-title">
				<h1><?php echo esc_html( $this->get_headline() ); ?></h1>
				<p><?php echo esc_html( $this->get_description() ); ?></p>
			</div>
			<section class="wpcode-plugin-screenshot">
				<div class="wpcode-plugin-screenshot-image">
					<img src="<?php echo esc_url( $images_url . $this->get_screenshot_thumb() ); // phpcs:ignore PluginCheck.CodeAnalysis.ImageFunctions.NonEnqueuedImage ?>" alt="<?php echo esc_attr( sprintf( /* translators: %s is the plugin name. */ __( '%s Screenshot', 'insert-headers-and-footers' ), $this->plugin_name ) ); ?>"/>
					<a href="<?php echo esc_url( $images_url . $this->get_screenshot_full() ); ?>" data-lity>
						<?php wpcode_icon( 'search', 16, 16 ); ?>
					</a>
				</div>
				<ul>
					<?php foreach ( $this->get_features() as $feature ) : ?>
						<li><?php echo esc_html( $feature ); ?></li>
					<?php endforeach; ?>
				</ul>
			</section>
			<section class="wpcode-plugin-step wpcode-plugin-step-install">
				<aside class="wpcode-plugin-page-step-num">
					<?php wpcode_icon( 'step-1', 50, 50, '0 0 100 100' ); ?>
					<i class="wpcode-plugin-page-step-loader wpcode-plugin-page-step-loader-hidden"></i>
				</aside>
				<div>
					<h2>
						<?php
						printf(
							// translators: %s is the plugin name.
							esc_html__( 'Install and Activate %s', 'insert-headers-and-footers' ),
							esc_html( $this->plugin_name )
						);
						?>
					</h2>
					<p>
						<?php
						printf(
							// translators: %s is the plugin name.
							esc_html__( 'Install %s from the WordPress.org plugin repository.', 'insert-headers-and-footers' ),
							esc_html( $this->plugin_name )
						);
						?>
					</p>
					<?php if ( current_user_can( 'install_plugins' ) && wp_is_file_mod_allowed( 'install_plugins' ) ) : ?>
						<button class="wpcode-button wpcode-button-install-plugin" data-slug="<?php echo esc_attr( $this->plugin_key ); ?>">
							<?php
							printf(
								// translators: %s is the plugin name.
								esc_html__( 'Install %s', 'insert-headers-and-footers' ),
								esc_html( $this->plugin_name )
							);
							?>
						</button>
					<?php else : ?>
						<p>
							<?php
							printf(
								// translators: %s is the plugin name.
								esc_html__( 'Please ask your website administrator to install %s.', 'insert-headers-and-footers' ),
								esc_html( $this->plugin_name )
							);
							?>
						</p>
					<?php endif; ?>
				</div>
			</section>
		</div>
		<?php
	}
}
