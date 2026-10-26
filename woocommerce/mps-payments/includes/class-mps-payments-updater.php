<?php
/**
 * Updates for a copy installed from the zip on MPS's download location.
 *
 * @package MpsPayments
 */

defined( 'ABSPATH' ) || exit;

/**
 * Makes WordPress offer new versions of this plugin the way it does for any plugin from the
 * directory — in Dashboard › Updates, with auto-updates if the merchant turns them on — by reading
 * info.json next to the published zip. A plugin uploaded as a zip otherwise never updates.
 *
 * The info.json file (written by build.sh):
 *   { "version": "0.2.0", "download_url": "https://…/mps-payments-0.2.0.zip",
 *     "requires": "6.4", "requires_php": "7.4", "tested": "6.8", "changelog": "<html>" }
 */
final class Mps_Payments_Updater {

	const CACHE_KEY = 'mps_payments_update_info';

	/**
	 * The info.json URL, empty when updates are off.
	 *
	 * @var string
	 */
	private $info_url;

	/**
	 * The plugin's basename.
	 *
	 * @var string
	 */
	private $basename;

	/**
	 * The installed version.
	 *
	 * @var string
	 */
	private $version;

	/**
	 * An updater for one installed copy.
	 *
	 * @param string $base_url Where info.json and the zips are published; empty switches updates off.
	 * @param string $basename The plugin's basename, e.g. mps-payments/mps-payments.php.
	 * @param string $version  The installed version.
	 */
	public function __construct( $base_url, $basename, $version ) {
		$this->info_url = '' === $base_url ? '' : untrailingslashit( $base_url ) . '/info.json';
		$this->basename = $basename;
		$this->version  = $version;
	}

	/**
	 * Hooks the update check into WordPress, unless updates are off.
	 */
	public function register() {
		if ( '' === $this->info_url ) {
			return;
		}

		add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'offer_update' ) );
		add_filter( 'plugins_api', array( $this, 'plugin_information' ), 10, 3 );
		add_action( 'upgrader_process_complete', array( $this, 'forget' ), 10, 0 );
	}

	/**
	 * Adds this plugin to the update list when info.json names a newer version.
	 *
	 * @param object $transient WordPress's update_plugins transient.
	 * @return object
	 */
	public function offer_update( $transient ) {
		if ( ! is_object( $transient ) ) {
			return $transient;
		}

		$info = $this->info();
		if ( null === $info ) {
			return $transient;
		}

		$item = (object) array(
			'id'           => $this->basename,
			'slug'         => dirname( $this->basename ),
			'plugin'       => $this->basename,
			'new_version'  => $info['version'],
			'package'      => $info['download_url'],
			'requires'     => isset( $info['requires'] ) ? $info['requires'] : '',
			'requires_php' => isset( $info['requires_php'] ) ? $info['requires_php'] : '',
			'tested'       => isset( $info['tested'] ) ? $info['tested'] : '',
			'url'          => '',
		);

		if ( version_compare( $info['version'], $this->version, '>' ) ) {
			$transient->response[ $this->basename ] = $item;
		} else {
			// Listed as up to date, which is what lets WordPress show the auto-update toggle.
			$transient->no_update[ $this->basename ] = $item;
		}

		return $transient;
	}

	/**
	 * The "View details" dialog for this plugin.
	 *
	 * @param false|object $result The result so far.
	 * @param string       $action The plugins_api action.
	 * @param object       $args   Its arguments.
	 * @return false|object
	 */
	public function plugin_information( $result, $action, $args ) {
		if ( 'plugin_information' !== $action || ! isset( $args->slug ) || dirname( $this->basename ) !== $args->slug ) {
			return $result;
		}

		$info = $this->info();
		if ( null === $info ) {
			return $result;
		}

		return (object) array(
			'name'          => 'MPS Payments for WooCommerce',
			'slug'          => dirname( $this->basename ),
			'version'       => $info['version'],
			'author'        => 'Mobile Payment Solutions',
			'requires'      => isset( $info['requires'] ) ? $info['requires'] : '',
			'requires_php'  => isset( $info['requires_php'] ) ? $info['requires_php'] : '',
			'tested'        => isset( $info['tested'] ) ? $info['tested'] : '',
			'download_link' => $info['download_url'],
			'sections'      => array(
				'changelog' => isset( $info['changelog'] ) ? wp_kses_post( $info['changelog'] ) : '',
			),
		);
	}

	/**
	 * Drops the cached info.json after an update, so the next check reads it again.
	 */
	public function forget() {
		delete_site_transient( self::CACHE_KEY );
	}

	/**
	 * The published info.json, cached for 12 hours — and a failed fetch for one, so an unreachable host is not
	 * asked again on every admin page.
	 *
	 * @return array|null
	 */
	private function info() {
		$cached = get_site_transient( self::CACHE_KEY );
		if ( is_array( $cached ) ) {
			return empty( $cached ) ? null : $cached;
		}

		$response = wp_remote_get( $this->info_url, array( 'timeout' => 10 ) );
		$info     = is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response )
			? null
			: json_decode( wp_remote_retrieve_body( $response ), true );

		$valid = is_array( $info ) && ! empty( $info['version'] ) && ! empty( $info['download_url'] )
			&& 0 === strpos( $info['download_url'], 'https://' );

		set_site_transient( self::CACHE_KEY, $valid ? $info : array(), $valid ? 12 * HOUR_IN_SECONDS : HOUR_IN_SECONDS );

		return $valid ? $info : null;
	}
}
