<?php
/**
 * The Settings module services.
 *
 * @package WooCommerce\PayPalCommerce\Settings
 */

declare( strict_types = 1 );

namespace WooCommerce\PayPalCommerce\Settings;

use WooCommerce\PayPalCommerce\Vendor\Psr\Container\ContainerInterface;
use WooCommerce\PayPalCommerce\Settings\Endpoint\OnboardingRestEndpoint;

return array(
	'settings.url'             => static function ( ContainerInterface $container ) : string {
		/**
		 * The path cannot be false.
		 *
		 * @psalm-suppress PossiblyFalseArgument
		 */
		return plugins_url(
			'/modules/ppcp-settings/',
			dirname( realpath( __FILE__ ), 3 ) . '/woocommerce-paypal-payments.php'
		);
	},
	'settings.rest.onboarding' => static function ( ContainerInterface $container ) : OnboardingRestEndpoint {
		return new OnboardingRestEndpoint();
	},
);
