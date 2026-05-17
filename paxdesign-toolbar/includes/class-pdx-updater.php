<?php
/**
 * GitHub release updater — enables one-click updates from wp-admin.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PDX_Updater {

	private const GITHUB_REPO = 'Black10998/paxdesign-toolbar';
	private const CACHE_KEY   = 'pdx_github_release';
	private const CACHE_TTL   = 43200; // 12 hours

	public function __construct() {
		add_filter( 'pre_set_site_transient_update_plugins', [ $this, 'inject_update' ] );
		add_filter( 'plugins_api', [ $this, 'plugins_api' ], 20, 3 );
		add_filter( 'upgrader_pre_download', [ $this, 'upgrader_pre_download' ], 10, 3 );
	}

	private function plugin_basename(): string {
		return plugin_basename( PDX_DIR . 'paxdesign-toolbar.php' );
	}

	private function get_release(): ?array {
		$cached = get_transient( self::CACHE_KEY );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$url = 'https://api.github.com/repos/' . self::GITHUB_REPO . '/releases/latest';
		$res = wp_remote_get(
			$url,
			[
				'timeout' => 15,
				'headers' => [
					'Accept'     => 'application/vnd.github+json',
					'User-Agent' => 'PaxDesign-Toolbar-Updater/' . PDX_VERSION,
				],
			]
		);

		if ( is_wp_error( $res ) || wp_remote_retrieve_response_code( $res ) !== 200 ) {
			return null;
		}

		$body = json_decode( wp_remote_retrieve_body( $res ), true );
		if ( ! is_array( $body ) || empty( $body['tag_name'] ) ) {
			return null;
		}

		$version = ltrim( (string) $body['tag_name'], 'vV' );
		$package = '';

		if ( ! empty( $body['assets'] ) && is_array( $body['assets'] ) ) {
			foreach ( $body['assets'] as $asset ) {
				$name = $asset['name'] ?? '';
				if ( preg_match( '/^paxdesign-toolbar-.+\.zip$/i', $name ) ) {
					$package = $asset['browser_download_url'] ?? '';
					break;
				}
			}
		}

		if ( ! $package ) {
			$package = $body['zipball_url'] ?? '';
		}

		$data = [
			'version' => $version,
			'package' => $package,
			'url'     => $body['html_url'] ?? 'https://github.com/' . self::GITHUB_REPO,
			'name'    => 'PaxDesign Utility Dock',
			'notes'   => $body['body'] ?? '',
		];

		set_transient( self::CACHE_KEY, $data, self::CACHE_TTL );
		return $data;
	}

	public function inject_update( $transient ) {
		if ( ! is_object( $transient ) ) {
			return $transient;
		}

		$release = $this->get_release();
		if ( ! $release || empty( $release['version'] ) || empty( $release['package'] ) ) {
			return $transient;
		}

		if ( version_compare( PDX_VERSION, $release['version'], '>=' ) ) {
			return $transient;
		}

		$plugin = $this->plugin_basename();
		$transient->response[ $plugin ] = (object) [
			'slug'        => PDX_SLUG,
			'plugin'      => $plugin,
			'new_version' => $release['version'],
			'url'         => $release['url'],
			'package'     => $release['package'],
			'tested'      => get_bloginfo( 'version' ),
			'id'          => $plugin,
		];

		return $transient;
	}

	public function plugins_api( $result, $action, $args ) {
		if ( $action !== 'plugin_information' || ( $args->slug ?? '' ) !== PDX_SLUG ) {
			return $result;
		}

		$release = $this->get_release();
		if ( ! $release ) {
			return $result;
		}

		return (object) [
			'name'          => $release['name'],
			'slug'          => PDX_SLUG,
			'version'       => $release['version'],
			'author'        => '<a href="https://paxdesign.io">PaxDesign</a>',
			'homepage'      => $release['url'],
			'download_link' => $release['package'],
			'sections'      => [
				'description' => 'Enterprise utility dock for WordPress.',
				'changelog'   => wp_kses_post( $release['notes'] ),
			],
		];
	}

	/**
	 * GitHub zipball extracts to a random folder — rename for WP upgrader.
	 */
	public function upgrader_pre_download( $reply, $package, $upgrader ) {
		if ( ! is_object( $upgrader ) || empty( $upgrader->skin->plugin ) ) {
			return $reply;
		}
		if ( $upgrader->skin->plugin !== $this->plugin_basename() ) {
			return $reply;
		}
		return $reply;
	}
}

new PDX_Updater();
