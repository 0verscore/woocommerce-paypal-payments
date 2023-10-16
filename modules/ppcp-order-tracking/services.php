<?php
/**
 * The order tracking module services.
 *
 * @package WooCommerce\PayPalCommerce\OrderTracking
 */

declare(strict_types=1);

namespace WooCommerce\PayPalCommerce\OrderTracking;

use WooCommerce\PayPalCommerce\OrderTracking\Integration\GermanizedShipmentIntegration;
use WooCommerce\PayPalCommerce\OrderTracking\Integration\ShipmentTrackingIntegration;
use WooCommerce\PayPalCommerce\OrderTracking\Integration\YithShipmentIntegration;
use WooCommerce\PayPalCommerce\OrderTracking\Shipment\ShipmentFactoryInterface;
use WooCommerce\PayPalCommerce\OrderTracking\Shipment\ShipmentFactory;
use WooCommerce\PayPalCommerce\Vendor\Psr\Container\ContainerInterface;
use WooCommerce\PayPalCommerce\OrderTracking\Assets\OrderEditPageAssets;
use WooCommerce\PayPalCommerce\OrderTracking\Endpoint\OrderTrackingEndpoint;

return array(
	'order-tracking.assets'                    => function( ContainerInterface $container ) : OrderEditPageAssets {
		return new OrderEditPageAssets(
			$container->get( 'order-tracking.module.url' ),
			$container->get( 'ppcp.asset-version' )
		);
	},
	'order-tracking.shipment.factory'          => static function ( ContainerInterface $container ) : ShipmentFactoryInterface {
		return new ShipmentFactory();
	},
	'order-tracking.endpoint.controller'       => static function ( ContainerInterface $container ) : OrderTrackingEndpoint {
		return new OrderTrackingEndpoint(
			$container->get( 'api.host' ),
			$container->get( 'api.bearer' ),
			$container->get( 'woocommerce.logger.woocommerce' ),
			$container->get( 'button.request-data' ),
			$container->get( 'order-tracking.shipment.factory' ),
			$container->get( 'order-tracking.allowed-shipping-statuses' ),
			$container->get( 'order-tracking.is-merchant-country-us' )
		);
	},
	'order-tracking.module.url'                => static function ( ContainerInterface $container ): string {
		/**
		 * The path cannot be false.
		 *
		 * @psalm-suppress PossiblyFalseArgument
		 */
		return plugins_url(
			'/modules/ppcp-order-tracking/',
			dirname( realpath( __FILE__ ), 3 ) . '/woocommerce-paypal-payments.php'
		);
	},
	'order-tracking.meta-box.renderer'         => static function ( ContainerInterface $container ): MetaBoxRenderer {
		return new MetaBoxRenderer(
			$container->get( 'order-tracking.allowed-shipping-statuses' ),
			$container->get( 'order-tracking.available-carriers' ),
			$container->get( 'order-tracking.endpoint.controller' ),
			$container->get( 'order-tracking.is-merchant-country-us' )
		);
	},
	'order-tracking.allowed-shipping-statuses' => static function ( ContainerInterface $container ): array {
		return (array) apply_filters(
			'woocommerce_paypal_payments_tracking_statuses',
			array(
				'SHIPPED'   => 'Shipped',
				'ON_HOLD'   => 'On Hold',
				'DELIVERED' => 'Delivered',
				'CANCELLED' => 'Cancelled',
			)
		);
	},
	'order-tracking.allowed-carriers'          => static function ( ContainerInterface $container ): array {
		return require __DIR__ . '/carriers.php';
	},
	'order-tracking.available-carriers'        => static function ( ContainerInterface $container ): array {
		$api_shop_country = $container->get( 'api.shop.country' );
		$allowed_carriers = $container->get( 'order-tracking.allowed-carriers' );
		$selected_country_carriers = $allowed_carriers[ $api_shop_country ] ?? array();

		return array(
			$api_shop_country => $selected_country_carriers ?? array(),
			'global'          => $allowed_carriers['global'] ?? array(),
			'other'           => array(
				'name'  => 'Other',
				'items' => array(
					'OTHER' => _x( 'Other', 'Name of carrier', 'woocommerce-paypal-payments' ),
				),
			),
		);
	},
	'order-tracking.is-merchant-country-us'    => static function ( ContainerInterface $container ): bool {
		return $container->get( 'api.shop.country' ) === 'US';
	},
	'order-tracking.integrations'              => static function ( ContainerInterface $container ): array {
		$shipment_factory = $container->get( 'order-tracking.shipment.factory' );
		$logger           = $container->get( 'woocommerce.logger.woocommerce' );
		$endpoint         = $container->get( 'order-tracking.endpoint.controller' );

		$integrations = array();

		if ( function_exists( 'wc_gzd_get_shipments_by_order' ) ) {
			$integrations[] = new GermanizedShipmentIntegration( $shipment_factory, $logger, $endpoint );
		}

		if ( class_exists( 'WC_Shipment_Tracking' ) ) {
			$integrations[] = new ShipmentTrackingIntegration( $shipment_factory, $logger, $endpoint );
		}

		if ( function_exists( 'yith_ywot_init' ) ) {
			$integrations[] = new YithShipmentIntegration( $shipment_factory, $logger, $endpoint );
		}

		return $integrations;
	},
);
