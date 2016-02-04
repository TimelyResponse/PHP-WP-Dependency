<?php

/**
 * WP Install Dependencies
 *
 * @package   WP_Install_Dependencies
 * @author    Andy Fragen
 * @license   GPL-2.0+
 * @link      https://github.com/afragen/wp-install-dependencies
 */

/*
 * Exit if called directly.
 */
if ( ! defined( 'WPINC' ) ) {
	die;
}

/*
 * Don't run during heartbeat.
 */
if ( isset( $_REQUEST['action'] ) && 'heartbeat' === $_REQUEST['action'] ) {
	return false;
}

/**
 * Instantiate class.
 */
add_action( 'plugins_loaded', function() {
	if ( file_exists( __DIR__ . '/wp-dependencies.json' ) ) {
		$config = file_get_contents( __DIR__ . '/wp-dependencies.json' );
	}
	new WP_Install_Dependencies( $config );
} );

if ( ! class_exists( 'WP_Install_Dependencies' ) ) {

	/**
	 * Class WP_Install_Dependencies
	 */
	class WP_Install_Dependencies {

		protected $config;

		/**
		 * Holds plugin dependency data from wp-dependencies.json
		 * @var
		 */
		protected $dependency;

		/**
		 * Holds names of installed dependencies for admin notices.
		 * @var
		 */
		protected $notices;

		/**
		 * WP_Install_Dependencies constructor.
		 *
		 * @param $config
		 */
		public function __construct( $config ) {
			/*
			 * Only run on plugin pages.
			 */
			global $pagenow;
			if ( false === strstr( $pagenow, 'plugin' ) ) {
				return false;
			}
			delete_option('active_plugins');

			$config = ! empty( $config ) ? json_decode( $config ) : null;
			/*
			 * Exit for json_decode error.
			 */
			if ( is_null( $config ) ) {
				$this->notices[] = array(
					'status' => 'error',
					'message' => '<code>wp-dependencies.json</code> ' . json_last_error_msg()
				);
				add_action( 'admin_notices', array( &$this, 'admin_notices' ) );
				add_action( 'network_admin_notices', array( &$this, 'admin_notices' ) );

				return false;
			}
			$this->config = $this->prepare_json( $config );

			add_action( 'admin_footer', array( $this, 'admin_footer' ) );
			add_action( 'wp_ajax_github_updater', array( $this, 'ajax_router' ) );

			//add_action( 'admin_init', array( $this, 'admin_init' ) );
			//add_action( 'admin_notices', array( $this, 'admin_notices' ) );
			//add_action( 'network_admin_notices', array( $this, 'admin_notices' ) );
		}

		/**
		 * Determine if dependency is active or installed
		 */
		function admin_init() {
			if ( get_transient( 'github_updater_dismiss_notice' ) ) {
				return;
			}
			foreach ( (object) $this->config as $dependency ) {
				$message = null;
				if ( ! $dependency instanceof stdClass ||
				     is_plugin_active( $dependency->slug )
				) {
					continue;
				}
				$this->dependency = $dependency;
				if ( $this->is_installed() ) {
					if ( ! is_plugin_active( $dependency->slug ) ) {
						$message = array(
							'action' => 'activate',
							'text'   => sprintf( __( 'Please activate the %s plugin.' ), $this->dependency->name )
						);
					}
				} else {
					$message = array(
						'action' => 'install',
						'text'   => sprintf( __( 'The %s plugin is required.' ), $dependency->name )
					);
				}
				$this->notices[] = $message;
			}
		}

		/**
		 * Register jQuery AJAX
		 */
		function admin_footer() {
			?>
			<script>
				(function ($) {
					$(function () {
						$(document).on('click', '.ghu-button', function () {
							var $this = $(this);
							$('.github-updater p').html('Running...');
							$.post(ajaxurl, {
								action: 'github_updater',
								method: $this.attr('data-action')
							}, function (response) {
								$('.github-updater p').html(response);
							});
						});
						$(document).on('click', '.github-updater .notice-dismiss', function () {
							$.post(ajaxurl, {
								action: 'github_updater',
								method: 'dismiss'
							});
						});
					});
				})(jQuery);
			</script>
			<?php
		}

		/**
		 * AJAX router
		 */
		function ajax_router() {
			$method    = isset( $_POST['method'] ) ? $_POST['method'] : '';
			$whitelist = array( 'install', 'activate', 'dismiss' );
			if ( in_array( $method, $whitelist ) ) {
				$response = $this->$method();
				echo $response['message'];
			}
			wp_die();
		}

		/**
		 * Prepare json data from wp-dependencies.json for use.
		 *
		 * @param $config
		 *
		 * @return bool
		 */
		protected function prepare_json( $config ) {
			$dependent_plugin = null;
			foreach ( $config as $dependency ) {
				if ( ! $dependency instanceof \stdClass ) {
					$dependent_plugin = $dependency;
					continue;
				}

				$download_link                = null;
				$dependency->dependent_plugin = $dependent_plugin;
				$path                         = parse_url( $dependency->uri, PHP_URL_PATH );
				$owner_repo                   = trim( $path, '/' );  // strip surrounding slashes
				$owner_repo                   = str_replace( '.git', '', $owner_repo ); //strip incorrect URI ending

				switch ( $dependency->git ) {
					case 'github':
						$download_link = 'https://api.github.com/repos/' . $owner_repo . '/zipball/' . $dependency->branch;
						if ( ! empty( $dependency->token ) ) {
							$download_link = add_query_arg( 'access_token', $dependency->token, $download_link );
						}
						$dependency->download_link = $download_link;
						break;
					case 'bitbucket':
						$download_link             = 'https://bitbucket.org/' . $owner_repo . '/get/' . $dependency->branch . '.zip';
						$dependency->download_link = $download_link;
						break;
					case 'gitlab':
						$download_link = 'https://gitlab.com/' . $owner_repo . '/repository/archive.zip';
						$download_link = add_query_arg( 'ref', $dependency->branch, $download_link );
						if ( ! empty( $dependency->token ) ) {
							$download_link = add_query_arg( 'private_token', $dependency->token, $download_link );
						}
						$dependency->download_link = $download_link;
						break;
				}

				$this->dependency            = $dependency;
				$this->dependency->installed = $this->is_installed();
				$this->dependency->optional ? $this->optional_install() : $this->install();
			}

			return $config;
		}

		/**
		 * Is dependency installed?
		 */
		function is_installed() {
			$plugins = get_plugins();
			return isset( $plugins[ $this->dependency->slug ] );
		}

		/**
		 * Install and activate dependency.
		 */
		public function install() {
			if ( ! $this->is_installed() ) {
				require_once ABSPATH . 'wp-admin/includes/file.php';
				require_once ABSPATH . 'wp-admin/includes/misc.php';

				add_filter( 'upgrader_source_selection', array( &$this, 'upgrader_source_selection' ), 10, 2 );

				$skin     = new WPID_Plugin_Installer_Skin( array(
					'type'  => 'plugin',
					'nonce' => wp_nonce_url( $this->dependency->download_link ),
				) );
				$upgrader = new Plugin_Upgrader( $skin );
				$result   = $upgrader->install( $this->dependency->download_link );

				if ( is_wp_error( $result ) ) {
					return array( 'status' => 'error', 'message' => $result->get_error_message() );
				}
				wp_cache_flush();
				$result = $this->activate();
				if ( 'error' == $result['status'] ) {
					return $result;
				}

				$this->notices[] = array(
					'status' => 'ok',
					'message' => sprintf( __( '%s has been installed.' ), $this->dependency->name ) );
				$this->notices[] = $result;

				if ( is_admin() && ! defined( 'DOING_AJAX' ) &&
				     $upgrader->skin->result
				) {
					add_action( 'admin_notices', array( &$this, 'admin_notices' ) );
					add_action( 'network_admin_notices', array( &$this, 'admin_notices' ) );
				}

				$this->dependency->installed = true;
			}
		}

		/**
		 * Install but don't activate optional dependencies.
		 */
		public function optional_install() {
			if ( ! is_multisite() || is_network_admin() && ! $this->is_installed() ) {
				add_action( 'admin_notices', array( &$this, 'admin_notices' ) );
				add_action( 'network_admin_notices', array( &$this, 'admin_notices' ) );
			}
		}

		/**
		 * Correctly rename dependency for activation.
		 *
		 * @param $source
		 * @param $remote_source
		 *
		 * @return string
		 */
		public function upgrader_source_selection( $source, $remote_source ) {
			global $wp_filesystem;
			$new_source = trailingslashit( $remote_source ) . dirname( $this->dependency->slug );
			$wp_filesystem->move( $source, $new_source );

			return trailingslashit( $new_source );
		}

		/**
		 * Activate dependency.
		 */
		public function activate() {
			$result = activate_plugin( $this->dependency->slug );
			if ( is_wp_error( $result ) ) {
				return array( 'status' => 'error', 'message' => $result->get_error_message() );
			}

			return array( 'status' => 'ok', 'message' => sprintf( __( '%s has been activated.' ), $this->dependency->name ) );
		}

		/**
		 * Dismiss admin notice for a week.
		 */
		public function dismiss() {
			//set_transient( 'github_updater_dismiss_notice', 'yes', ( 60 * 60 * 24 * 7 ) );

			return array( 'status' => 'ok', 'message' => '' );
		}

		/**
		 * Display admin notices / action links.
		 */
		public function admin_notices() {
			$this->admin_init();
			foreach ( $this->notices as $notice ) {
				$label = esc_html__( 'Plugin Dependency' ) . ': ';
				if ( ! empty( $notice['action'] ) ) {
					$action  = esc_attr( $notice['action'] );
					$message = esc_html( $notice['text'] );
					$message .= ' <a href="javascript:;" class="ghu-button" data-action="' . $action . '">' . ucfirst( $action ) . ' Now &raquo;</a>';
				}
				if ( ! empty( $notice['status'] ) ) {
					$message = esc_html( $notice['message'] );
				}
				?>
				<div class="updated notice is-dismissible github-updater">
					<p><?php echo $label . $message; ?></p>
				</div>
				<?php
			}
		}
	}

	require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';

	class WPID_Plugin_Installer_Skin extends Plugin_Installer_Skin {
		public function header() {}
		public function footer() {}
		public function error( $errors ) {}
		public function feedback( $string ) {}
	}

}
