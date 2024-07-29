<?php
/**
 * The Googlepay module services.
 *
 * @package WooCommerce\PayPalCommerce\Googlepay
 */

declare(strict_types=1);

namespace WooCommerce\PayPalCommerce\Googlepay;

use Automattic\WooCommerce\Blocks\Payments\PaymentMethodTypeInterface;
use WooCommerce\PayPalCommerce\Button\Assets\ButtonInterface;
use WooCommerce\PayPalCommerce\Common\Pattern\SingletonDecorator;
use WooCommerce\PayPalCommerce\Googlepay\Assets\BlocksPaymentMethod;
use WooCommerce\PayPalCommerce\Googlepay\Assets\Button;
use WooCommerce\PayPalCommerce\Googlepay\Endpoint\UpdatePaymentDataEndpoint;
use WooCommerce\PayPalCommerce\Googlepay\Helper\ApmApplies;
use WooCommerce\PayPalCommerce\Googlepay\Helper\ApmProductStatus;
use WooCommerce\PayPalCommerce\Googlepay\Helper\AvailabilityNotice;
use WooCommerce\PayPalCommerce\Onboarding\Environment;
use WooCommerce\PayPalCommerce\Onboarding\State;
use WooCommerce\PayPalCommerce\Vendor\Psr\Container\ContainerInterface;

return array(

	// If GooglePay can be configured.
	'googlepay.eligible'                          => static function ( ContainerInterface $container ): bool {
		$apm_applies = $container->get( 'googlepay.helpers.apm-applies' );
		assert( $apm_applies instanceof ApmApplies );

		return $apm_applies->for_country() && $apm_applies->for_currency();
	},

	'googlepay.helpers.apm-applies'               => static function ( ContainerInterface $container ) : ApmApplies {
		return new ApmApplies(
			$container->get( 'googlepay.supported-countries' ),
			$container->get( 'googlepay.supported-currencies' ),
			$container->get( 'api.shop.currency' ),
			$container->get( 'api.shop.country' )
		);
	},

	// If GooglePay is configured and onboarded.
	'googlepay.available'                         => static function ( ContainerInterface $container ): bool {
		if ( apply_filters( 'woocommerce_paypal_payments_googlepay_validate_product_status', true ) ) {
			$status = $container->get( 'googlepay.helpers.apm-product-status' );
			assert( $status instanceof ApmProductStatus );
			/**
			 * If merchant isn't onboarded via /v1/customer/partner-referrals this returns false as the API call fails.
			 */
			return apply_filters( 'woocommerce_paypal_payments_googlepay_product_status', $status->is_active() );
		}
		return true;
	},

	// We assume it's a referral if we can check product status without API request failures.
	'googlepay.is_referral'                       => static function ( ContainerInterface $container ): bool {
		$status = $container->get( 'googlepay.helpers.apm-product-status' );
		assert( $status instanceof ApmProductStatus );

		return ! $status->has_request_failure();
	},

	'googlepay.availability_notice'               => static function ( ContainerInterface $container ): AvailabilityNotice {
		return new AvailabilityNotice(
			$container->get( 'googlepay.helpers.apm-product-status' ),
			$container->get( 'wcgateway.is-wc-gateways-list-page' ),
			$container->get( 'wcgateway.is-ppcp-settings-page' )
		);
	},

	'googlepay.helpers.apm-product-status'        => SingletonDecorator::make(
		static function( ContainerInterface $container ): ApmProductStatus {
			return new ApmProductStatus(
				$container->get( 'wcgateway.settings' ),
				$container->get( 'api.endpoint.partners' ),
				$container->get( 'onboarding.state' ),
				$container->get( 'api.helper.failure-registry' )
			);
		}
	),

	/**
	 * The list of which countries can be used for GooglePay.
	 */
	'googlepay.supported-countries'               => static function ( ContainerInterface $container) : array {
		/**
		 * Returns which countries can be used for GooglePay.
		 */
		return apply_filters(
			'woocommerce_paypal_payments_googlepay_supported_countries',
			array(
				'AU', // Australia
				'AT', // Austria
				'BE', // Belgium
				'BG', // Bulgaria
				'CA', // Canada
				'CN', // China
				'CY', // Cyprus
				'CZ', // Czech Republic
				'DK', // Denmark
				'EE', // Estonia
				'FI', // Finland
				'FR', // France
				'DE', // Germany
				'GR', // Greece
				'HU', // Hungary
				'IE', // Ireland
				'IT', // Italy
				'LV', // Latvia
				'LI', // Liechtenstein
				'LT', // Lithuania
				'LU', // Luxembourg
				'MT', // Malta
				'NL', // Netherlands
				'NO', // Norway
				'PL', // Poland
				'PT', // Portugal
				'RO', // Romania
				'SK', // Slovakia
				'SI', // Slovenia
				'ES', // Spain
				'SE', // Sweden
				'US', // United States
				'GB'  // United Kingdom
			)
		);
	},

	/**
	 * The list of which currencies can be used for GooglePay.
	 */
	'googlepay.supported-currencies'              => static function ( ContainerInterface $container ) : array {
		/**
		 * Returns which currencies can be used for GooglePay.
		 */
		return apply_filters(
			'woocommerce_paypal_payments_googlepay_supported_currencies',
			array(
				'AUD', // Australian Dollar
				'BRL', // Brazilian Real
				'CAD', // Canadian Dollar
				'CHF', // Swiss Franc
				'CZK', // Czech Koruna
				'DKK', // Danish Krone
				'EUR', // Euro
				'GBP', // British Pound Sterling
				'HKD', // Hong Kong Dollar
				'HUF', // Hungarian Forint
				'ILS', // Israeli New Shekel
				'JPY', // Japanese Yen
				'MXN', // Mexican Peso
				'NOK', // Norwegian Krone
				'NZD', // New Zealand Dollar
				'PHP', // Philippine Peso
				'PLN', // Polish Zloty
				'SEK', // Swedish Krona
				'SGD', // Singapore Dollar
				'THB', // Thai Baht
				'TWD', // New Taiwan Dollar
				'USD'  // United States Dollar
			)
		);
	},

	'googlepay.button'                            => static function ( ContainerInterface $container ): ButtonInterface {
		return new Button(
			$container->get( 'googlepay.url' ),
			$container->get( 'googlepay.sdk_url' ),
			$container->get( 'ppcp.asset-version' ),
			$container->get( 'session.handler' ),
			$container->get( 'wcgateway.settings' ),
			$container->get( 'onboarding.environment' ),
			$container->get( 'wcgateway.settings.status' ),
			$container->get( 'api.shop.currency' ),
			$container->get( 'woocommerce.logger.woocommerce' )
		);
	},

	'googlepay.blocks-payment-method'             => static function ( ContainerInterface $container ): PaymentMethodTypeInterface {
		return new BlocksPaymentMethod(
			'ppcp-googlepay',
			$container->get( 'googlepay.url' ),
			$container->get( 'ppcp.asset-version' ),
			$container->get( 'googlepay.button' ),
			$container->get( 'blocks.method' )
		);
	},

	'googlepay.url'                               => static function ( ContainerInterface $container ): string {
		$path = realpath( __FILE__ );
		if ( false === $path ) {
			return '';
		}
		return plugins_url(
			'/modules/ppcp-googlepay/',
			dirname( $path, 3 ) . '/woocommerce-paypal-payments.php'
		);
	},

	'googlepay.sdk_url'                           => static function ( ContainerInterface $container ): string {
		return 'https://pay.google.com/gp/p/js/pay.js';
	},

	'googlepay.endpoint.update-payment-data'      => static function ( ContainerInterface $container ): UpdatePaymentDataEndpoint {
		return new UpdatePaymentDataEndpoint(
			$container->get( 'button.request-data' ),
			$container->get( 'woocommerce.logger.woocommerce' )
		);
	},

	'googlepay.enable-url-sandbox'                => static function ( ContainerInterface $container ): string {
		return 'https://www.sandbox.paypal.com/bizsignup/add-product?product=payment_methods&capabilities=GOOGLE_PAY';
	},

	'googlepay.enable-url-live'                   => static function ( ContainerInterface $container ): string {
		return 'https://www.paypal.com/bizsignup/add-product?product=payment_methods&capabilities=GOOGLE_PAY';
	},

	'googlepay.settings.connection.status-text'   => static function ( ContainerInterface $container ): string {
		$state = $container->get( 'onboarding.state' );
		if ( $state->current_state() < State::STATE_ONBOARDED ) {
			return '';
		}

		$product_status = $container->get( 'googlepay.helpers.apm-product-status' );
		assert( $product_status instanceof ApmProductStatus );

		$environment = $container->get( 'onboarding.environment' );
		assert( $environment instanceof Environment );

		$enabled = $product_status->is_active();

		$enabled_status_text  = esc_html__( 'Status: Available', 'woocommerce-paypal-payments' );
		$disabled_status_text = esc_html__( 'Status: Not yet enabled', 'woocommerce-paypal-payments' );

		$button_text = $enabled
			? esc_html__( 'Settings', 'woocommerce-paypal-payments' )
			: esc_html__( 'Enable Google Pay', 'woocommerce-paypal-payments' );

		$enable_url = $environment->current_environment_is( Environment::PRODUCTION )
			? $container->get( 'googlepay.enable-url-live' )
			: $container->get( 'googlepay.enable-url-sandbox' );

		$button_url = $enabled
			? admin_url( 'admin.php?page=wc-settings&tab=checkout&section=ppcp-gateway&ppcp-tab=ppcp-credit-card-gateway#ppcp-googlepay_button_enabled' )
			: $enable_url;

		return sprintf(
			'<p>%1$s %2$s</p><p><a target="%3$s" href="%4$s" class="button">%5$s</a></p>',
			$enabled ? $enabled_status_text : $disabled_status_text,
			$enabled ? '<span class="dashicons dashicons-yes"></span>' : '<span class="dashicons dashicons-no"></span>',
			$enabled ? '_self' : '_blank',
			esc_url( $button_url ),
			esc_html( $button_text )
		);
	},
	'googlepay.wc-gateway'                        => static function ( ContainerInterface $container ): GooglePayGateway {
		return new GooglePayGateway(
			$container->get( 'wcgateway.order-processor' ),
			$container->get( 'api.factory.paypal-checkout-url' ),
			$container->get( 'wcgateway.processor.refunds' ),
			$container->get( 'wcgateway.transaction-url-provider' ),
			$container->get( 'session.handler' ),
			$container->get( 'googlepay.url' )
		);
	},
);
