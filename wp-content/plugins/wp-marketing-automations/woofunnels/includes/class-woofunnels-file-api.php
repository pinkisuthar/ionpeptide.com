<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Including WordPress Filesystem API
 */
include_once ABSPATH . '/wp-admin/includes/file.php';
if ( function_exists( 'WP_Filesystem' ) ) {
	WP_Filesystem();
}

if ( class_exists( 'WP_Filesystem_Direct' ) ) {
	#[AllowDynamicProperties]
	class WooFunnels_File_Api extends WP_Filesystem_Direct {
		private $upload_dir;
		private static $ins = null;
		private $core_dir = 'funnelkit';
		private $component = '';

		public function __construct( $component ) {
			$upload                    = wp_upload_dir();
			$this->upload_dir          = $upload['basedir'];
			$this->woofunnels_core_dir = $this->upload_dir . '/' . $this->core_dir;
			$this->set_component( $component );

			$this->makedirs();
			self::$ins = 1;
		}

		public function set_component( $component ) {
			if ( '' !== $component ) {
				$this->component = $component;
			}
		}

		public function get_component_dir() {
			return $this->woofunnels_core_dir . '/' . $this->component;
		}

		public function touch( $file, $time = 0, $atime = 0 ) {
			$file = $this->file_path( $file );

			return parent::touch( $file, $time, $atime );
		}

		public function file_path( $file ) {
			$file = sanitize_file_name( basename( $file ) );
			if ( '' === $file ) {
				return false;
			}
			$real_base = realpath( $this->woofunnels_core_dir . '/' . $this->component );
			if ( false === $real_base ) {
				return false;
			}
			$candidate = $real_base . '/' . $file;
			$resolved  = realpath( $candidate );
			/** If the file already exists, ensure it resolves inside $real_base (defends against symlink escape). */
			if ( false !== $resolved ) {
				if ( 0 !== strpos( $resolved, $real_base . DIRECTORY_SEPARATOR ) ) {
					return false;
				}

				return $resolved;
			}

			/** File does not exist yet (write/create path): parent is already resolved and basename is sanitized, so candidate is safe. */
			return $candidate;
		}

		public function folder_path( $folder_name ) {
			$folder_name = sanitize_file_name( basename( $folder_name ) );
			if ( '' === $folder_name ) {
				return false;
			}
			$real_base = realpath( $this->woofunnels_core_dir );
			if ( false === $real_base ) {
				return false;
			}
			$candidate = $real_base . '/' . $folder_name;
			$resolved  = realpath( $candidate );
			if ( false !== $resolved ) {
				if ( 0 !== strpos( $resolved, $real_base . DIRECTORY_SEPARATOR ) ) {
					return false;
				}

				return $resolved . '/';
			}

			return $candidate . '/';
		}

		public function is_readable( $file ) {
			$file = $this->file_path( $file );

			return parent::is_readable( $file );
		}

		public function is_writable( $file ) {
			$file = $this->file_path( $file );

			return parent::is_writable( $file );
		}

		public function put_contents( $file, $contents, $mode = false ) {
			$file = $this->file_path( $file );

			return parent::put_contents( $file, $contents, $mode );
		}

		public function delete_file( $file, $recursive = false, $type = 'f' ) {
			$file = $this->file_path( $file );

			return parent::delete( $file, $recursive, $type );
		}

		public function delete_all( $folder_name, $recursive = false ) {
			$folder_path = $this->folder_path( $folder_name );

			return parent::rmdir( $folder_path, $recursive );
		}


		/**
		 * Gets details for files in a directory or a specific file.
		 *
		 * @param string $path Path to directory or file.
		 * @param bool $include_hidden Optional. Whether to include details of hidden ("." prefixed) files.
		 *                               Default true.
		 * @param bool $recursive Optional. Whether to recursively include file details in nested directories.
		 *                               Default false.
		 *
		 * @return array|false {
		 *     Array of files. False if unable to list directory contents.
		 *
		 * @type string $name Name of the file or directory.
		 * @type string $perms *nix representation of permissions.
		 * @type int $permsn Octal representation of permissions.
		 * @type string $owner Owner name or ID.
		 * @type int $size Size of file in bytes.
		 * @type int $lastmodunix Last modified unix timestamp.
		 * @type mixed $lastmod Last modified month (3 letter) and day (without leading 0).
		 * @type int $time Last modified time.
		 * @type string $type Type of resource. 'f' for file, 'd' for directory.
		 * @type mixed $files If a directory and $recursive is true, contains another array of files.
		 * }
		 * @since 2.5.0
		 *
		 */
		public function dirlist( $path, $include_hidden = true, $recursive = false ) {
			if ( $this->is_file( $path ) ) {
				$limit_file = basename( $path );
				$path       = dirname( $path );
			} else {
				$limit_file = false;
			}
			if ( ! $this->is_dir( $path ) ) {
				return false;
			}

			$dir = dir( $path );
			if ( ! $dir ) {
				return false;
			}

			$ret = array();

			while ( false !== ( $entry = $dir->read() ) ) { // phpcs:ignore Generic.CodeAnalysis.AssignmentInCondition.FoundInWhileCondition
				$struc         = array();
				$struc['name'] = $entry;

				if ( '.' === $struc['name'] || '..' === $struc['name'] ) {
					continue;
				}

				if ( ! $include_hidden && '.' === $struc['name'][0] ) {
					continue;
				}

				if ( $limit_file && $struc['name'] !== $limit_file ) {
					continue;
				}

				$struc['perms']       = $this->gethchmod( $path . '/' . $entry );
				$struc['permsn']      = $this->getnumchmodfromh( $struc['perms'] );
				$struc['number']      = false;
				$struc['owner']       = $this->owner( $path . '/' . $entry );
				$struc['group']       = $this->group( $path . '/' . $entry );
				$struc['size']        = $this->size( $path . '/' . $entry );
				$struc['lastmodunix'] = $this->mtime( $path . '/' . $entry );
				$struc['lastmod']     = gmdate( 'M j', $struc['lastmodunix'] );
				$struc['time']        = gmdate( 'h:i:s', $struc['lastmodunix'] );
				$struc['type']        = $this->is_dir( $path . '/' . $entry ) ? 'd' : 'f';

				if ( 'd' === $struc['type'] ) {
					if ( $recursive ) {
						$struc['files'] = $this->dirlist( $path . '/' . $struc['name'], $include_hidden, $recursive );
					} else {
						$struc['files'] = array();
					}
				}

				$ret[ $struc['name'] ] = $struc;
			}
			$dir->close();
			unset( $dir );

			return $ret;
		}


		public function delete_folder( $folder_path, $recursive = false ) {
			return parent::rmdir( $folder_path, $recursive );
		}

		public function exists( $file ) {
			$file = $this->file_path( $file );

			return parent::exists( $file );
		}

		public function get_contents( $file ) {
			$file = $this->file_path( $file );

			return parent::get_contents( $file );
		}

		public function makedirs() {
			$component = $this->component;

			if ( parent::is_writable( $this->upload_dir ) ) {
				if ( false === $this->is_dir( $this->woofunnels_core_dir ) ) {
					$this->mkdir( $this->woofunnels_core_dir );
					$file_handle = @fopen( trailingslashit( $this->woofunnels_core_dir ) . '/.htaccess', 'w' ); // phpcs:ignore WordPressVIPMinimum.PHP.SilencedErrors.Silenced, Generic.PHP.NoSilencedErrors.Discouraged, Generic.PHP.NoSilencedErrors.Forbidden, WordPress.WP.AlternativeFunctions.file_system_read_fopen, WordPress.WP.AlternativeFunctions.file_system_operations, WordPress.WP.AlternativeFunctions.file_system_operations_fopen
					if ( $file_handle ) {
						fwrite( $file_handle, 'deny from all' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_fwrite, WordPress.WP.AlternativeFunctions.file_system_operations, WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- writes the protective .htaccess (deny from all) for the upload folder
						fclose( $file_handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_fclose, WordPress.WP.AlternativeFunctions.file_system_operations, WordPress.WP.AlternativeFunctions.file_system_operations_fclose
					}
				}
				$dir = $this->woofunnels_core_dir . '/' . $component;
				if ( false === $this->is_dir( $dir ) ) {
					$this->mkdir( $dir );
				}
			}
		}
	}
}
