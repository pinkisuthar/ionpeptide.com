<?php
/**
 * Shared guards for the write abilities.
 *
 * @package WPCode
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Trait WPCode_Abilities_Guards.
 */
trait WPCode_Abilities_Guards {

	/**
	 * Get an error when Testing Mode is active, null otherwise.
	 *
	 * @return WP_Error|null
	 */
	protected function testing_mode_error() {
		if ( ! wpcode_testing_mode_enabled() ) {
			return null;
		}

		return new WP_Error(
			'wpcode_testing_mode_active',
			__( 'WPCode Testing Mode is active. Publish or discard the test changes before making changes through automation tools.', 'insert-headers-and-footers' ),
			array( 'status' => 409 )
		);
	}

	/**
	 * Load a snippet and check that the current user can edit it.
	 *
	 * @param int $id The snippet id.
	 *
	 * @return WPCode_Snippet|WP_Error
	 */
	protected function load_snippet( $id ) {
		$id   = absint( $id );
		$post = $id ? get_post( $id ) : null;
		// Trashed (and any other non-snippet status) posts are not snippets the admin can edit or activate.
		if ( ! $post || 'wpcode' !== $post->post_type || ! in_array( $post->post_status, array( 'draft', 'publish' ), true ) ) {
			return new WP_Error(
				'wpcode_snippet_not_found',
				__( 'Snippet not found.', 'insert-headers-and-footers' ),
				array( 'status' => 404 )
			);
		}

		$snippet = new WPCode_Snippet( $id );
		if ( ! WPCode_Snippet_Input::can_edit( $snippet->get_code_type(), $id ) ) {
			return new WP_Error(
				'wpcode_forbidden',
				__( 'You are not allowed to edit this snippet.', 'insert-headers-and-footers' ),
				array( 'status' => 403 )
			);
		}

		return $snippet;
	}

	/**
	 * Check if the current user can activate the snippet.
	 *
	 * @param WPCode_Snippet $snippet The snippet.
	 *
	 * @return bool
	 */
	protected function can_activate( $snippet ) {
		return current_user_can( 'wpcode_activate_snippets', $snippet );
	}

	/**
	 * Get an error when the site refuses local snippet saves, null otherwise.
	 *
	 * WPCode_Snippet::save() asks the wpcode_pre_save_snippet filter first; Pro remote-only mode and files mode
	 * use it to refuse every local save. Ask it the same way, on a copy, before any test-run or write, so code is
	 * never test-run for a save that will be refused.
	 *
	 * @param WPCode_Snippet $snippet The snippet as it will be saved.
	 *
	 * @return WP_Error|null
	 */
	protected function save_refused_error( $snippet ) {
		wpcode()->error->clear_errors();
		$pre_save = apply_filters( 'wpcode_pre_save_snippet', false, clone $snippet );
		$message  = wpcode()->error->get_last_error_message();
		wpcode()->error->clear_errors();

		if ( false === $pre_save ) {
			return null;
		}

		return new WP_Error(
			'wpcode_forbidden',
			$message ? $message : __( 'Snippets cannot be saved on this site.', 'insert-headers-and-footers' ),
			array( 'status' => 403 )
		);
	}

	/**
	 * Get an error when PHP snippets are disabled on the site (Pro: WPCODE_DISABLE_PHP or the
	 * completely_disable_php setting) and the code type runs PHP, null otherwise.
	 *
	 * @param string $code_type The code type.
	 *
	 * @return WP_Error|null
	 */
	protected function php_disabled_error( $code_type ) {
		if ( ! in_array( $code_type, array( 'php', 'universal' ), true ) || ! class_exists( 'WPCode_Access' ) || ! WPCode_Access::php_disabled() ) {
			return null;
		}

		return new WP_Error(
			'wpcode_unsupported_code_type',
			__( 'PHP snippets are disabled on this site, so PHP and Universal snippets cannot be created, changed or activated.', 'insert-headers-and-footers' ),
			array( 'status' => 400 )
		);
	}

	/**
	 * Get the error for SCSS code, which only the snippet editor can compile.
	 *
	 * @return WP_Error
	 */
	protected function scss_error() {
		return new WP_Error(
			'wpcode_unsupported_code_type',
			__( 'SCSS code is compiled in the WPCode snippet editor. Create or change SCSS code there; other fields and the status can be changed here.', 'insert-headers-and-footers' ),
			array( 'status' => 400 )
		);
	}

	/**
	 * Run the activation checks on a copy of the snippet without saving anything.
	 *
	 * @param WPCode_Snippet $snippet The snippet.
	 *
	 * @return true|WP_Error
	 */
	protected function test_run( $snippet ) {
		if ( ! in_array( $snippet->get_code_type(), array( 'php', 'universal' ), true ) ) {
			return true;
		}

		// Detach the copy from the stored snippet so a failed run cannot write error meta to it.
		$stored_id    = absint( $snippet->get_id() );
		$copy         = clone $snippet;
		$copy->id     = 0;
		$copy->active = true;

		// Check the syntax without running anything. Read the code from the copy: the getter fills the property.
		$code_type = $copy->get_code_type();
		$code      = $copy->get_code();
		$declared  = $this->declared_names( $code, $code_type );
		if ( is_wp_error( $declared ) ) {
			return $declared;
		}

		// Running code that declares a function or class that already exists is a fatal error that cannot be caught.
		$existing = array();
		foreach ( $declared as $key => $info ) {
			$name = $info['name'];
			if ( 'function' === $info['kind'] ? function_exists( $name ) : ( class_exists( $name, false ) || interface_exists( $name, false ) || trait_exists( $name, false ) ) ) {
				$existing[ $key ] = $name;
			}
		}
		if ( ! empty( $existing ) ) {
			// The live version of this same snippet declared them in this request: the syntax check must do.
			$stored = $stored_id ? get_post( $stored_id ) : null;
			if ( $stored && 'wpcode' === $stored->post_type && 'publish' === $stored->post_status ) {
				$stored_names = $this->declared_names( $stored->post_content, ( new WPCode_Snippet( $stored_id ) )->get_code_type() );
				if ( ! is_wp_error( $stored_names ) && ! array_diff_key( $existing, $stored_names ) ) {
					return true;
				}
			}

			return new WP_Error(
				'wpcode_activation_failed',
				/* translators: %s: function or class name. */
				sprintf( __( 'Cannot redeclare %s: it is already declared on this site.', 'insert-headers-and-footers' ), reset( $existing ) ),
				array( 'status' => 400 )
			);
		}

		// Never let a failing test run deactivate a stored snippet, whatever the auto-disable settings.
		$last_error  = null;
		$message     = '';
		// A callback only this method holds, so removing it cannot remove a third-party __return_true.
		$keep_active = function () {
			return true;
		};
		add_filter( 'wpcode_force_deactivate_snippet', $keep_active );

		wpcode()->error->clear_errors();
		$ob_level = ob_get_level();
		try {
			$copy->run_activation_checks();
		} catch ( Throwable $e ) {
			// Snippet code can throw exceptions the executor does not catch.
			return new WP_Error(
				'wpcode_activation_failed',
				$e->getMessage(),
				array(
					'status' => 400,
					'line'   => absint( $e->getLine() ),
				)
			);
		} finally {
			// A thrown exception skips the executor's ob_get_clean(), so drop any buffer the run left open.
			while ( ob_get_level() > $ob_level ) {
				ob_end_clean();
			}
			remove_filter( 'wpcode_force_deactivate_snippet', $keep_active );
			wpcode()->execute->not_doing_activation();
			// Errors are read below only on the normal path, so keep them until then.
			if ( ! isset( $e ) ) {
				$last_error = wpcode()->error->get_last_error();
				$message    = wpcode()->error->get_last_error_message();
			}
			wpcode()->error->clear_errors();
		}

		if ( null !== $last_error ) {
			$data = array( 'status' => 400 );
			$line = 0;
			if ( is_array( $last_error ) ) {
				$line = ! empty( $last_error['error_line'] ) ? $last_error['error_line'] : ( isset( $last_error['line'] ) ? $last_error['line'] : 0 );
			} elseif ( is_object( $last_error ) && method_exists( $last_error, 'getLine' ) ) {
				$line = $last_error->getLine();
			}
			if ( $line ) {
				$data['line'] = absint( $line );
			}

			return new WP_Error( 'wpcode_activation_failed', $message, $data );
		}

		return true;
	}

	/**
	 * Check the syntax of PHP snippet code without running it and list the functions and classes it declares
	 * at the top level (not inside a function, class or condition).
	 *
	 * @param string $code      The code, unslashed.
	 * @param string $code_type The code type: php or universal.
	 *
	 * @return array|WP_Error Lowercase fully qualified name => array( 'kind' => 'function'|'class', 'name' => name ).
	 */
	protected function declared_names( $code, $code_type ) {
		if ( ! function_exists( 'token_get_all' ) ) {
			return array();
		}

		// PHP snippets run as PHP code; universal snippets start as HTML, as the executors run them.
		$source = 'universal' === $code_type ? $code : '<?php ' . $code;
		try {
			$tokens = token_get_all( $source, TOKEN_PARSE );
		} catch ( ParseError $e ) {
			// The prefix is on the first line, so the line numbers are those of the snippet code.
			return new WP_Error(
				'wpcode_activation_failed',
				$e->getMessage(),
				array(
					'status' => 400,
					'line'   => absint( $e->getLine() ),
				)
			);
		}

		$name_tokens = array( T_STRING, T_NS_SEPARATOR );
		foreach ( array( 'T_NAME_QUALIFIED', 'T_NAME_FULLY_QUALIFIED' ) as $constant ) {
			if ( defined( $constant ) ) {
				$name_tokens[] = constant( $constant );
			}
		}
		$class_tokens = array( T_CLASS, T_INTERFACE, T_TRAIT );
		if ( defined( 'T_ENUM' ) ) {
			$class_tokens[] = constant( 'T_ENUM' );
		}
		$skip  = array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT );
		$names = array();
		$depth = 0;
		$top   = 0; // Depth of the top level: 1 inside a braced namespace.
		$ns    = '';
		$prev  = null;
		$count = count( $tokens );

		for ( $i = 0; $i < $count; $i++ ) {
			$token = $tokens[ $i ];
			$id    = is_array( $token ) ? $token[0] : $token;
			if ( in_array( $id, $skip, true ) ) {
				continue;
			}

			if ( '{' === $id || T_CURLY_OPEN === $id || T_DOLLAR_OPEN_CURLY_BRACES === $id ) {
				++$depth;
			} elseif ( '}' === $id ) {
				--$depth;
				if ( $depth < $top ) {
					// End of a braced namespace.
					$top = 0;
					$ns  = '';
				}
			} elseif ( T_NAMESPACE === $id && $depth === $top ) {
				// Read the namespace name; "namespace\foo()" is a call, not a declaration.
				$name = '';
				for ( $j = $i + 1; $j < $count; $j++ ) {
					$next = $tokens[ $j ];
					$nid  = is_array( $next ) ? $next[0] : $next;
					if ( in_array( $nid, $skip, true ) ) {
						continue;
					}
					if ( in_array( $nid, $name_tokens, true ) ) {
						if ( T_NS_SEPARATOR === $nid && '' === $name ) {
							break;
						}
						$name .= $next[1];
						continue;
					}
					if ( ';' === $nid || '{' === $nid ) {
						$ns = trim( $name, '\\' );
						if ( '{' === $nid ) {
							$top   = $depth + 1;
							$depth = $top;
						}
						$i = $j;
					}
					break;
				}
			} elseif ( $depth === $top && ( T_FUNCTION === $id || in_array( $id, $class_tokens, true ) ) && T_DOUBLE_COLON !== $prev && T_NEW !== $prev && T_USE !== $prev ) {
				// The next significant token is the name; closures and anonymous classes have none.
				for ( $j = $i + 1; $j < $count; $j++ ) {
					$next = $tokens[ $j ];
					$nid  = is_array( $next ) ? $next[0] : $next;
					if ( in_array( $nid, $skip, true ) || '&' === $nid || ( defined( 'T_AMPERSAND_NOT_FOLLOWED_BY_VAR_OR_VARARG' ) && constant( 'T_AMPERSAND_NOT_FOLLOWED_BY_VAR_OR_VARARG' ) === $nid ) ) {
						continue;
					}
					if ( T_STRING === $nid ) {
						$name                         = '' === $ns ? $next[1] : $ns . '\\' . $next[1];
						$names[ strtolower( $name ) ] = array(
							'kind' => T_FUNCTION === $id ? 'function' : 'class',
							'name' => $name,
						);
					}
					break;
				}
			}
			$prev = $id;
		}

		return $names;
	}

	/**
	 * Build the standard snippet summary returned by the abilities.
	 *
	 * @param WPCode_Snippet $snippet The snippet.
	 *
	 * @return array
	 */
	protected function snippet_response( $snippet ) {
		return array(
			'id'        => $snippet->get_id(),
			'title'     => $snippet->get_title(),
			'code_type' => $snippet->get_code_type(),
			'active'    => $snippet->is_active(),
			'edit_url'  => $snippet->get_edit_url(),
		);
	}
}
