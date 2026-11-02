<?php
//phpcs:disable

namespace BWF_Pelago;

use BWFAN\Pelago\Emogrifier\CssInliner;
use BWFAN\Pelago\Emogrifier\HtmlProcessor\CssToAttributeConverter;
use BWFAN\Pelago\Emogrifier\HtmlProcessor\HtmlPruner;

/**
 * This class provides functions for converting CSS styles into inline style attributes in your HTML code.
 *
 * For more information, please see the README.md file.
 */
class Emogrifier {
	public $content = null;
	public $css = null;

	public function __construct( $content, $css ) {
		$this->content = $content;
		$this->css     = $css;
	}

	/**
	 * Applies $this->css to the given HTML and returns the HTML with the CSS
	 * applied.
	 *
	 * This method places the CSS inline.
	 *
	 * @return string
	 *
	 * @throws \BadMethodCallException
	 */
	public function emogrify() {
		if ( version_compare( phpversion(), '8.0.3', '<' ) ) {
			return $this->old_library();
		}

		require_once __DIR__ . '/Emogrifier/autoload.php'; // phpcs:ignoreFile
		$css_inliner  = CssInliner::fromHtml( $this->content )->inlineCss( $this->css );
		$dom_document = $css_inliner->getDomDocument();

		$content = CssToAttributeConverter::fromDomDocument( $dom_document )->convertCssToVisualAttributes()->render();

		return defined( 'BWFAN_MINIFY_MAIL_CONTENT' ) && BWFAN_MINIFY_MAIL_CONTENT && method_exists( 'BWFAN_Common', 'minifyHtmlData' ) ? \BWFAN_Common::minifyHtmlData( $content ) : $content;
	}

	/**
	 * Applies $this->css to the given HTML and returns the body with the CSS
	 * applied.
	 *
	 * This method places the CSS inline.
	 *
	 * @return string
	 *
	 * @throws \BadMethodCallException
	 */
	public function emogrifyBodyContent() {
		if ( version_compare( phpversion(), '8.0.3', '<' ) ) {
			return $this->old_library( 'emogrifyBodyContent' );
		}

		require_once __DIR__ . '/Emogrifier/autoload.php'; // phpcs:ignoreFile
		$css_inliner = CssInliner::fromHtml( $this->content )->inlineCss( $this->css );

		$dom_document = $css_inliner->getDomDocument();

		$content = CssToAttributeConverter::fromDomDocument( $dom_document )->convertCssToVisualAttributes()->renderBodyContent();

		return defined( 'BWFAN_MINIFY_MAIL_CONTENT' ) && BWFAN_MINIFY_MAIL_CONTENT && method_exists( 'BWFAN_Common', 'minifyHtmlData' ) ? \BWFAN_Common::minifyHtmlData( $content ) : $content;
	}

	/**
	 * Use legacy emogrify library
	 *
	 * @param $called_from
	 *
	 * @return string
	 */
	public function old_library( $called_from = null ) {
		if ( ! class_exists( 'BWF_Pelago\Emogrifier_V1' ) ) {
			include_once BWFAN_PLUGIN_DIR . '/libraries/class-emogrifier-v1.php'; // phpcs:ignoreFile
		}

		$emogrifier = new Emogrifier_V1( $this->content, $this->css );

		if ( 'emogrifyBodyContent' === $called_from ) {
			return $emogrifier->emogrifyBodyContent();
		}

		return $emogrifier->emogrify();
	}

}
