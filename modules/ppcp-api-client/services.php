<?php
/**
 * The services of the API client.
 *
 * @package WooCommerce\PayPalCommerce\ApiClient
 */

declare(strict_types=1);

namespace WooCommerce\PayPalCommerce\ApiClient;

use WooCommerce\PayPalCommerce\ApiClient\Authentication\SdkClientToken;
use WooCommerce\PayPalCommerce\ApiClient\Authentication\UserIdToken;
use WooCommerce\PayPalCommerce\ApiClient\Endpoint\PaymentMethodTokensEndpoint;
use WooCommerce\PayPalCommerce\ApiClient\Endpoint\PaymentTokensEndpoint;
use WooCommerce\PayPalCommerce\ApiClient\Entity\CardAuthenticationResult;
use WooCommerce\PayPalCommerce\ApiClient\Factory\CardAuthenticationResultFactory;
use WooCommerce\PayPalCommerce\ApiClient\Helper\FailureRegistry;
use WooCommerce\PayPalCommerce\Common\Pattern\SingletonDecorator;
use WooCommerce\PayPalCommerce\ApiClient\Endpoint\BillingSubscriptions;
use WooCommerce\PayPalCommerce\ApiClient\Endpoint\CatalogProducts;
use WooCommerce\PayPalCommerce\ApiClient\Endpoint\BillingPlans;
use WooCommerce\PayPalCommerce\ApiClient\Factory\BillingCycleFactory;
use WooCommerce\PayPalCommerce\ApiClient\Factory\PaymentPreferencesFactory;
use WooCommerce\PayPalCommerce\ApiClient\Factory\RefundFactory;
use WooCommerce\PayPalCommerce\ApiClient\Factory\PlanFactory;
use WooCommerce\PayPalCommerce\ApiClient\Factory\ProductFactory;
use WooCommerce\PayPalCommerce\ApiClient\Factory\RefundPayerFactory;
use WooCommerce\PayPalCommerce\ApiClient\Factory\SellerPayableBreakdownFactory;
use WooCommerce\PayPalCommerce\ApiClient\Factory\ShippingOptionFactory;
use WooCommerce\PayPalCommerce\Session\SessionHandler;
use WooCommerce\PayPalCommerce\Vendor\Psr\Container\ContainerInterface;
use WooCommerce\PayPalCommerce\ApiClient\Authentication\Bearer;
use WooCommerce\PayPalCommerce\ApiClient\Authentication\PayPalBearer;
use WooCommerce\PayPalCommerce\ApiClient\Endpoint\BillingAgreementsEndpoint;
use WooCommerce\PayPalCommerce\ApiClient\Endpoint\IdentityToken;
use WooCommerce\PayPalCommerce\ApiClient\Endpoint\LoginSeller;
use WooCommerce\PayPalCommerce\ApiClient\Endpoint\OrderEndpoint;
use WooCommerce\PayPalCommerce\ApiClient\Endpoint\PartnerReferrals;
use WooCommerce\PayPalCommerce\ApiClient\Endpoint\PartnersEndpoint;
use WooCommerce\PayPalCommerce\ApiClient\Endpoint\PaymentsEndpoint;
use WooCommerce\PayPalCommerce\ApiClient\Endpoint\PaymentTokenEndpoint;
use WooCommerce\PayPalCommerce\ApiClient\Endpoint\WebhookEndpoint;
use WooCommerce\PayPalCommerce\ApiClient\Factory\AddressFactory;
use WooCommerce\PayPalCommerce\ApiClient\Factory\AmountFactory;
use WooCommerce\PayPalCommerce\ApiClient\Factory\ApplicationContextFactory;
use WooCommerce\PayPalCommerce\ApiClient\Factory\AuthorizationFactory;
use WooCommerce\PayPalCommerce\ApiClient\Factory\CaptureFactory;
use WooCommerce\PayPalCommerce\ApiClient\Factory\ExchangeRateFactory;
use WooCommerce\PayPalCommerce\ApiClient\Factory\FraudProcessorResponseFactory;
use WooCommerce\PayPalCommerce\ApiClient\Factory\ItemFactory;
use WooCommerce\PayPalCommerce\ApiClient\Factory\MoneyFactory;
use WooCommerce\PayPalCommerce\ApiClient\Factory\OrderFactory;
use WooCommerce\PayPalCommerce\ApiClient\Factory\PatchCollectionFactory;
use WooCommerce\PayPalCommerce\ApiClient\Factory\PayeeFactory;
use WooCommerce\PayPalCommerce\ApiClient\Factory\PayerFactory;
use WooCommerce\PayPalCommerce\ApiClient\Factory\PaymentsFactory;
use WooCommerce\PayPalCommerce\ApiClient\Factory\PaymentTokenActionLinksFactory;
use WooCommerce\PayPalCommerce\ApiClient\Factory\PaymentTokenFactory;
use WooCommerce\PayPalCommerce\ApiClient\Factory\PlatformFeeFactory;
use WooCommerce\PayPalCommerce\ApiClient\Factory\PurchaseUnitFactory;
use WooCommerce\PayPalCommerce\ApiClient\Factory\SellerReceivableBreakdownFactory;
use WooCommerce\PayPalCommerce\ApiClient\Factory\SellerStatusFactory;
use WooCommerce\PayPalCommerce\ApiClient\Factory\ShippingFactory;
use WooCommerce\PayPalCommerce\ApiClient\Factory\ShippingPreferenceFactory;
use WooCommerce\PayPalCommerce\ApiClient\Factory\WebhookEventFactory;
use WooCommerce\PayPalCommerce\ApiClient\Factory\WebhookFactory;
use WooCommerce\PayPalCommerce\ApiClient\Helper\Cache;
use WooCommerce\PayPalCommerce\ApiClient\Helper\DccApplies;
use WooCommerce\PayPalCommerce\ApiClient\Helper\OrderHelper;
use WooCommerce\PayPalCommerce\ApiClient\Helper\OrderTransient;
use WooCommerce\PayPalCommerce\ApiClient\Helper\PurchaseUnitSanitizer;
use WooCommerce\PayPalCommerce\ApiClient\Repository\ApplicationContextRepository;
use WooCommerce\PayPalCommerce\ApiClient\Repository\CustomerRepository;
use WooCommerce\PayPalCommerce\ApiClient\Repository\OrderRepository;
use WooCommerce\PayPalCommerce\ApiClient\Repository\PartnerReferralsData;
use WooCommerce\PayPalCommerce\ApiClient\Repository\PayeeRepository;
use WooCommerce\PayPalCommerce\WcGateway\Settings\Settings;

return array(
	'api.host'                                       => function( ContainerInterface $container ) : string {
		return PAYPAL_API_URL;
	},
	'api.paypal-host'                                => function( ContainerInterface $container ) : string {
		return PAYPAL_API_URL;
	},
	// It seems this 'api.paypal-website-url' key is always overridden in ppcp-onboarding/services.php.
	'api.paypal-website-url'                         => function( ContainerInterface $container ) : string {
		return PAYPAL_URL;
	},
	'api.factory.paypal-checkout-url'                => function( ContainerInterface $container ) : callable {
		return function ( string $id ) use ( $container ): string {
			return $container->get( 'api.paypal-website-url' ) . '/checkoutnow?token=' . $id;
		};
	},
	'api.partner_merchant_id'                        => static function () : string {
		return '';
	},
	'api.merchant_email'                             => function () : string {
		return '';
	},
	'api.merchant_id'                                => function () : string {
		return '';
	},
	'api.key'                                        => static function (): string {
		return '';
	},
	'api.secret'                                     => static function (): string {
		return '';
	},
	'api.prefix'                                     => static function (): string {
		return 'WC-';
	},
	'api.bearer'                                     => static function ( ContainerInterface $container ): Bearer {
		$cache              = new Cache( 'ppcp-paypal-bearer' );
		$key                = $container->get( 'api.key' );
		$secret             = $container->get( 'api.secret' );
		$host   = $container->get( 'api.host' );
		$logger = $container->get( 'woocommerce.logger.woocommerce' );
		$settings = $container->get( 'wcgateway.settings' );
		return new PayPalBearer(
			$cache,
			$host,
			$key,
			$secret,
			$logger,
			$settings
		);
	},
	'api.endpoint.partners'                          => static function ( ContainerInterface $container ) : PartnersEndpoint {
		return new PartnersEndpoint(
			$container->get( 'api.host' ),
			$container->get( 'api.bearer' ),
			$container->get( 'woocommerce.logger.woocommerce' ),
			$container->get( 'api.factory.sellerstatus' ),
			$container->get( 'api.partner_merchant_id' ),
			$container->get( 'api.merchant_id' ),
			$container->get( 'api.helper.failure-registry' )
		);
	},
	'api.factory.sellerstatus'                       => static function ( ContainerInterface $container ) : SellerStatusFactory {
		return new SellerStatusFactory();
	},
	'api.endpoint.payment-token'                     => static function ( ContainerInterface $container ) : PaymentTokenEndpoint {
		return new PaymentTokenEndpoint(
			$container->get( 'api.host' ),
			$container->get( 'api.bearer' ),
			$container->get( 'api.factory.payment-token' ),
			$container->get( 'api.factory.payment-token-action-links' ),
			$container->get( 'woocommerce.logger.woocommerce' ),
			$container->get( 'api.repository.customer' )
		);
	},
	'api.endpoint.payment-tokens'                    => static function( ContainerInterface $container ) : PaymentTokensEndpoint {
		return new PaymentTokensEndpoint(
			$container->get( 'api.host' ),
			$container->get( 'api.bearer' ),
			$container->get( 'woocommerce.logger.woocommerce' )
		);
	},
	'api.endpoint.webhook'                           => static function ( ContainerInterface $container ) : WebhookEndpoint {

		return new WebhookEndpoint(
			$container->get( 'api.host' ),
			$container->get( 'api.bearer' ),
			$container->get( 'api.factory.webhook' ),
			$container->get( 'api.factory.webhook-event' ),
			$container->get( 'woocommerce.logger.woocommerce' )
		);
	},
	'api.endpoint.partner-referrals'                 => static function ( ContainerInterface $container ) : PartnerReferrals {

		return new PartnerReferrals(
			$container->get( 'api.host' ),
			$container->get( 'api.bearer' ),
			$container->get( 'woocommerce.logger.woocommerce' )
		);
	},
	'api.endpoint.identity-token'                    => static function ( ContainerInterface $container ) : IdentityToken {
		$logger = $container->get( 'woocommerce.logger.woocommerce' );
		$settings = $container->get( 'wcgateway.settings' );
		$customer_repository = $container->get( 'api.repository.customer' );
		return new IdentityToken(
			$container->get( 'api.host' ),
			$container->get( 'api.bearer' ),
			$logger,
			$settings,
			$customer_repository
		);
	},
	'api.endpoint.payments'                          => static function ( ContainerInterface $container ): PaymentsEndpoint {
		$authorizations_factory = $container->get( 'api.factory.authorization' );
		$capture_factory = $container->get( 'api.factory.capture' );
		$logger = $container->get( 'woocommerce.logger.woocommerce' );

		return new PaymentsEndpoint(
			$container->get( 'api.host' ),
			$container->get( 'api.bearer' ),
			$authorizations_factory,
			$capture_factory,
			$logger
		);
	},
	'api.endpoint.login-seller'                      => static function ( ContainerInterface $container ) : LoginSeller {

		$logger = $container->get( 'woocommerce.logger.woocommerce' );
		return new LoginSeller(
			$container->get( 'api.paypal-host' ),
			$container->get( 'api.partner_merchant_id' ),
			$logger
		);
	},
	'api.endpoint.order'                             => static function ( ContainerInterface $container ): OrderEndpoint {
		$order_factory            = $container->get( 'api.factory.order' );
		$patch_collection_factory = $container->get( 'api.factory.patch-collection-factory' );
		$logger                   = $container->get( 'woocommerce.logger.woocommerce' );

		$session_handler = $container->get( 'session.handler' );
		assert( $session_handler instanceof SessionHandler );
		$bn_code         = $session_handler->bn_code();

		$settings = $container->get( 'wcgateway.settings' );
		assert( $settings instanceof Settings );

		$intent                         = $settings->has( 'intent' ) && strtoupper( (string) $settings->get( 'intent' ) ) === 'AUTHORIZE' ? 'AUTHORIZE' : 'CAPTURE';
		$application_context_repository = $container->get( 'api.repository.application-context' );
		$subscription_helper = $container->get( 'wc-subscriptions.helper' );
		return new OrderEndpoint(
			$container->get( 'api.host' ),
			$container->get( 'api.bearer' ),
			$order_factory,
			$patch_collection_factory,
			$intent,
			$logger,
			$application_context_repository,
			$subscription_helper,
			$container->get( 'wcgateway.is-fraudnet-enabled' ),
			$container->get( 'wcgateway.fraudnet' ),
			$bn_code
		);
	},
	'api.endpoint.billing-agreements'                => static function ( ContainerInterface $container ): BillingAgreementsEndpoint {
		return new BillingAgreementsEndpoint(
			$container->get( 'api.host' ),
			$container->get( 'api.bearer' ),
			$container->get( 'woocommerce.logger.woocommerce' )
		);
	},
	'api.endpoint.catalog-products'                  => static function ( ContainerInterface $container ): CatalogProducts {
		return new CatalogProducts(
			$container->get( 'api.host' ),
			$container->get( 'api.bearer' ),
			$container->get( 'api.factory.product' ),
			$container->get( 'woocommerce.logger.woocommerce' )
		);
	},
	'api.endpoint.billing-plans'                     => static function( ContainerInterface $container ): BillingPlans {
		return new BillingPlans(
			$container->get( 'api.host' ),
			$container->get( 'api.bearer' ),
			$container->get( 'api.factory.billing-cycle' ),
			$container->get( 'api.factory.plan' ),
			$container->get( 'woocommerce.logger.woocommerce' )
		);
	},
	'api.endpoint.billing-subscriptions'             => static function( ContainerInterface $container ): BillingSubscriptions {
		return new BillingSubscriptions(
			$container->get( 'api.host' ),
			$container->get( 'api.bearer' ),
			$container->get( 'woocommerce.logger.woocommerce' )
		);
	},
	'api.endpoint.payment-method-tokens'             => static function( ContainerInterface $container ): PaymentMethodTokensEndpoint {
		return new PaymentMethodTokensEndpoint(
			$container->get( 'api.host' ),
			$container->get( 'api.bearer' ),
			$container->get( 'woocommerce.logger.woocommerce' )
		);
	},
	'api.repository.application-context'             => static function( ContainerInterface $container ) : ApplicationContextRepository {

		$settings = $container->get( 'wcgateway.settings' );
		return new ApplicationContextRepository( $settings );
	},
	'api.repository.partner-referrals-data'          => static function ( ContainerInterface $container ) : PartnerReferralsData {

		$dcc_applies    = $container->get( 'api.helpers.dccapplies' );
		return new PartnerReferralsData( $dcc_applies );
	},
	'api.repository.payee'                           => static function ( ContainerInterface $container ): PayeeRepository {
		$merchant_email = $container->get( 'api.merchant_email' );
		$merchant_id    = $container->get( 'api.merchant_id' );
		return new PayeeRepository( $merchant_email, $merchant_id );
	},
	'api.repository.customer'                        => static function( ContainerInterface $container ): CustomerRepository {
		$prefix           = $container->get( 'api.prefix' );
		return new CustomerRepository( $prefix );
	},
	'api.repository.order'                           => static function( ContainerInterface $container ): OrderRepository {
		return new OrderRepository(
			$container->get( 'api.endpoint.order' )
		);
	},
	'api.factory.application-context'                => static function ( ContainerInterface $container ) : ApplicationContextFactory {
		return new ApplicationContextFactory();
	},
	'api.factory.payment-token'                      => static function ( ContainerInterface $container ) : PaymentTokenFactory {
		return new PaymentTokenFactory();
	},
	'api.factory.payment-token-action-links'         => static function ( ContainerInterface $container ) : PaymentTokenActionLinksFactory {
		return new PaymentTokenActionLinksFactory();
	},
	'api.factory.webhook'                            => static function ( ContainerInterface $container ): WebhookFactory {
		return new WebhookFactory();
	},
	'api.factory.webhook-event'                      => static function ( ContainerInterface $container ): WebhookEventFactory {
		return new WebhookEventFactory();
	},
	'api.factory.capture'                            => static function ( ContainerInterface $container ): CaptureFactory {

		$amount_factory   = $container->get( 'api.factory.amount' );
		return new CaptureFactory(
			$amount_factory,
			$container->get( 'api.factory.seller-receivable-breakdown' ),
			$container->get( 'api.factory.fraud-processor-response' )
		);
	},
	'api.factory.refund'                             => static function ( ContainerInterface $container ): RefundFactory {
		$amount_factory   = $container->get( 'api.factory.amount' );
		return new RefundFactory(
			$amount_factory,
			$container->get( 'api.factory.seller-payable-breakdown' ),
			$container->get( 'api.factory.refund_payer' )
		);
	},
	'api.factory.purchase-unit'                      => static function ( ContainerInterface $container ): PurchaseUnitFactory {

		$amount_factory   = $container->get( 'api.factory.amount' );
		$item_factory     = $container->get( 'api.factory.item' );
		$shipping_factory = $container->get( 'api.factory.shipping' );
		$payments_factory = $container->get( 'api.factory.payments' );
		$prefix           = $container->get( 'api.prefix' );
		$soft_descriptor  = $container->get( 'wcgateway.soft-descriptor' );
		$sanitizer        = $container->get( 'api.helper.purchase-unit-sanitizer' );

		return new PurchaseUnitFactory(
			$amount_factory,
			$item_factory,
			$shipping_factory,
			$payments_factory,
			$prefix,
			$soft_descriptor,
			$sanitizer
		);
	},
	'api.factory.patch-collection-factory'           => static function ( ContainerInterface $container ): PatchCollectionFactory {
		return new PatchCollectionFactory();
	},
	'api.factory.payee'                              => static function ( ContainerInterface $container ): PayeeFactory {
		return new PayeeFactory();
	},
	'api.factory.item'                               => static function ( ContainerInterface $container ): ItemFactory {
		return new ItemFactory(
			$container->get( 'api.shop.currency' )
		);
	},
	'api.factory.shipping'                           => static function ( ContainerInterface $container ): ShippingFactory {
		return new ShippingFactory(
			$container->get( 'api.factory.address' ),
			$container->get( 'api.factory.shipping-option' )
		);
	},
	'api.factory.shipping-preference'                => static function ( ContainerInterface $container ): ShippingPreferenceFactory {
		return new ShippingPreferenceFactory();
	},
	'api.factory.shipping-option'                    => static function ( ContainerInterface $container ): ShippingOptionFactory {
		return new ShippingOptionFactory(
			$container->get( 'api.factory.money' )
		);
	},
	'api.factory.amount'                             => static function ( ContainerInterface $container ): AmountFactory {
		$item_factory = $container->get( 'api.factory.item' );
		return new AmountFactory(
			$item_factory,
			$container->get( 'api.factory.money' ),
			$container->get( 'api.shop.currency' )
		);
	},
	'api.factory.money'                              => static function ( ContainerInterface $container ): MoneyFactory {
		return new MoneyFactory();
	},
	'api.factory.payer'                              => static function ( ContainerInterface $container ): PayerFactory {
		$address_factory = $container->get( 'api.factory.address' );
		return new PayerFactory( $address_factory );
	},
	'api.factory.refund_payer'                       => static function ( ContainerInterface $container ): RefundPayerFactory {
		return new RefundPayerFactory();
	},
	'api.factory.address'                            => static function ( ContainerInterface $container ): AddressFactory {
		return new AddressFactory();
	},
	'api.factory.order'                              => static function ( ContainerInterface $container ): OrderFactory {
		$purchase_unit_factory          = $container->get( 'api.factory.purchase-unit' );
		$payer_factory                  = $container->get( 'api.factory.payer' );
		$application_context_repository = $container->get( 'api.repository.application-context' );
		$application_context_factory    = $container->get( 'api.factory.application-context' );
		return new OrderFactory(
			$purchase_unit_factory,
			$payer_factory,
			$application_context_repository,
			$application_context_factory
		);
	},
	'api.factory.payments'                           => static function ( ContainerInterface $container ): PaymentsFactory {
		$authorizations_factory = $container->get( 'api.factory.authorization' );
		$capture_factory        = $container->get( 'api.factory.capture' );
		$refund_factory         = $container->get( 'api.factory.refund' );
		return new PaymentsFactory( $authorizations_factory, $capture_factory, $refund_factory );
	},
	'api.factory.authorization'                      => static function ( ContainerInterface $container ): AuthorizationFactory {
		return new AuthorizationFactory( $container->get( 'api.factory.fraud-processor-response' ) );
	},
	'api.factory.exchange-rate'                      => static function ( ContainerInterface $container ): ExchangeRateFactory {
		return new ExchangeRateFactory();
	},
	'api.factory.platform-fee'                       => static function ( ContainerInterface $container ): PlatformFeeFactory {
		return new PlatformFeeFactory(
			$container->get( 'api.factory.money' ),
			$container->get( 'api.factory.payee' )
		);
	},
	'api.factory.seller-receivable-breakdown'        => static function ( ContainerInterface $container ): SellerReceivableBreakdownFactory {
		return new SellerReceivableBreakdownFactory(
			$container->get( 'api.factory.money' ),
			$container->get( 'api.factory.exchange-rate' ),
			$container->get( 'api.factory.platform-fee' )
		);
	},
	'api.factory.seller-payable-breakdown'           => static function ( ContainerInterface $container ): SellerPayableBreakdownFactory {
		return new SellerPayableBreakdownFactory(
			$container->get( 'api.factory.money' ),
			$container->get( 'api.factory.platform-fee' )
		);
	},
	'api.factory.fraud-processor-response'           => static function ( ContainerInterface $container ): FraudProcessorResponseFactory {
		return new FraudProcessorResponseFactory();
	},
	'api.factory.product'                            => static function( ContainerInterface $container ): ProductFactory {
		return new ProductFactory();
	},
	'api.factory.billing-cycle'                      => static function( ContainerInterface $container ): BillingCycleFactory {
		return new BillingCycleFactory( $container->get( 'api.shop.currency' ) );
	},
	'api.factory.payment-preferences'                => static function( ContainerInterface $container ):PaymentPreferencesFactory {
		return new PaymentPreferencesFactory( $container->get( 'api.shop.currency' ) );
	},
	'api.factory.plan'                               => static function( ContainerInterface $container ): PlanFactory {
		return new PlanFactory(
			$container->get( 'api.factory.billing-cycle' ),
			$container->get( 'api.factory.payment-preferences' )
		);
	},
	'api.factory.card-authentication-result-factory' => static function( ContainerInterface $container ): CardAuthenticationResultFactory {
		return new CardAuthenticationResultFactory();
	},
	'api.helpers.dccapplies'                         => static function ( ContainerInterface $container ) : DccApplies {
		return new DccApplies(
			$container->get( 'api.dcc-supported-country-currency-matrix' ),
			$container->get( 'api.dcc-supported-country-card-matrix' ),
			$container->get( 'api.shop.currency' ),
			$container->get( 'api.shop.country' )
		);
	},

	'api.shop.currency'                              => static function ( ContainerInterface $container ) : string {
		$currency = get_woocommerce_currency();
		if ( $currency ) {
			return $currency;
		}

		$currency = get_option( 'woocommerce_currency' );
		if ( ! $currency ) {
			return 'NO_CURRENCY'; // Unlikely to happen.
		}

		return $currency;
	},
	'api.shop.country'                               => static function ( ContainerInterface $container ) : string {
		$location = wc_get_base_location();
		return $location['country'];
	},
	'api.shop.is-psd2-country'                       => static function ( ContainerInterface $container ) : bool {
		return in_array(
			$container->get( 'api.shop.country' ),
			$container->get( 'api.psd2-countries' ),
			true
		);
	},
	'api.shop.is-currency-supported'                 => static function ( ContainerInterface $container ) : bool {
		return in_array(
			$container->get( 'api.shop.currency' ),
			$container->get( 'api.supported-currencies' ),
			true
		);
	},


	'api.shop.is-latin-america'                      => static function ( ContainerInterface $container ): bool {
		return in_array(
			$container->get( 'api.shop.country' ),
			array(
				'AI',
				'AG',
				'AR',
				'AW',
				'BS',
				'BB',
				'BZ',
				'BM',
				'BO',
				'BR',
				'VG',
				'KY',
				'CL',
				'CO',
				'CR',
				'DM',
				'DO',
				'EC',
				'SV',
				'FK',
				'GF',
				'GD',
				'GP',
				'GT',
				'GY',
				'HN',
				'JM',
				'MQ',
				'MX',
				'MS',
				'AN',
				'NI',
				'PA',
				'PY',
				'PE',
				'KN',
				'LC',
				'PM',
				'VC',
				'SR',
				'TT',
				'TC',
				'UY',
				'VE',
			),
			true
		);
	},

	/**
	 * Currencies supported by PayPal.
	 *
	 * From https://developer.paypal.com/docs/reports/reference/paypal-supported-currencies/
	 */
	'api.supported-currencies'                       => static function ( ContainerInterface $container ) : array {
		return array(
			'AUD',
			'BRL',
			'CAD',
			'CNY',
			'CZK',
			'DKK',
			'EUR',
			'HKD',
			'HUF',
			'ILS',
			'JPY',
			'MYR',
			'MXN',
			'TWD',
			'NZD',
			'NOK',
			'PHP',
			'PLN',
			'GBP',
			'RUB',
			'SGD',
			'SEK',
			'CHF',
			'THB',
			'USD',
		);
	},

	/**
	 * The matrix which countries and currency combinations can be used for DCC.
	 */
	'api.dcc-supported-country-currency-matrix'      => static function ( ContainerInterface $container ) : array {
		/**
		 * Returns which countries and currency combinations can be used for DCC.
		 */
		return apply_filters(
			'woocommerce_paypal_payments_supported_country_currency_matrix',
			array(
				'AU' => array(
					'AUD',
					'BRL',
					'CAD',
					'CHF',
					'CZK',
					'DKK',
					'EUR',
					'GBP',
					'HKD',
					'HUF',
					'ILS',
					'JPY',
					'MXN',
					'NOK',
					'NZD',
					'PHP',
					'PLN',
					'SEK',
					'SGD',
					'THB',
					'TWD',
					'USD',
				),
				'AT' => array(
					'AUD',
					'BRL',
					'CAD',
					'CHF',
					'CZK',
					'DKK',
					'EUR',
					'GBP',
					'HKD',
					'HUF',
					'ILS',
					'JPY',
					'MXN',
					'NOK',
					'NZD',
					'PHP',
					'PLN',
					'SEK',
					'SGD',
					'THB',
					'TWD',
					'USD',
				),
				'BE' => array(
					'AUD',
					'BRL',
					'CAD',
					'CHF',
					'CZK',
					'DKK',
					'EUR',
					'GBP',
					'HKD',
					'HUF',
					'ILS',
					'JPY',
					'MXN',
					'NOK',
					'NZD',
					'PHP',
					'PLN',
					'SEK',
					'SGD',
					'THB',
					'TWD',
					'USD',
				),
				'BG' => array(
					'AUD',
					'BRL',
					'CAD',
					'CHF',
					'CZK',
					'DKK',
					'EUR',
					'GBP',
					'HKD',
					'HUF',
					'ILS',
					'JPY',
					'MXN',
					'NOK',
					'NZD',
					'PHP',
					'PLN',
					'SEK',
					'SGD',
					'THB',
					'TWD',
					'USD',
				),
				'CA' => array(
					'AUD',
					'BRL',
					'CAD',
					'CHF',
					'CZK',
					'DKK',
					'EUR',
					'GBP',
					'HKD',
					'HUF',
					'ILS',
					'JPY',
					'MXN',
					'NOK',
					'NZD',
					'PHP',
					'PLN',
					'SEK',
					'SGD',
					'THB',
					'TWD',
					'USD',
				),
				'CN' => array(
					'AUD',
					'BRL',
					'CAD',
					'CHF',
					'CZK',
					'DKK',
					'EUR',
					'GBP',
					'HKD',
					'HUF',
					'ILS',
					'JPY',
					'MXN',
					'NOK',
					'NZD',
					'PHP',
					'PLN',
					'SEK',
					'SGD',
					'THB',
					'TWD',
					'USD',
				),
				'CY' => array(
					'AUD',
					'BRL',
					'CAD',
					'CHF',
					'CZK',
					'DKK',
					'EUR',
					'GBP',
					'HKD',
					'HUF',
					'ILS',
					'JPY',
					'MXN',
					'NOK',
					'NZD',
					'PHP',
					'PLN',
					'SEK',
					'SGD',
					'THB',
					'TWD',
					'USD',
				),
				'CZ' => array(
					'AUD',
					'BRL',
					'CAD',
					'CHF',
					'CZK',
					'DKK',
					'EUR',
					'GBP',
					'HKD',
					'HUF',
					'ILS',
					'JPY',
					'MXN',
					'NOK',
					'NZD',
					'PHP',
					'PLN',
					'SEK',
					'SGD',
					'THB',
					'TWD',
					'USD',
				),
				'DK' => array(
					'AUD',
					'BRL',
					'CAD',
					'CHF',
					'CZK',
					'DKK',
					'EUR',
					'GBP',
					'HKD',
					'HUF',
					'ILS',
					'JPY',
					'MXN',
					'NOK',
					'NZD',
					'PHP',
					'PLN',
					'SEK',
					'SGD',
					'THB',
					'TWD',
					'USD',
				),
				'EE' => array(
					'AUD',
					'BRL',
					'CAD',
					'CHF',
					'CZK',
					'DKK',
					'EUR',
					'GBP',
					'HKD',
					'HUF',
					'ILS',
					'JPY',
					'MXN',
					'NOK',
					'NZD',
					'PHP',
					'PLN',
					'SEK',
					'SGD',
					'THB',
					'TWD',
					'USD',
				),
				'FI' => array(
					'AUD',
					'BRL',
					'CAD',
					'CHF',
					'CZK',
					'DKK',
					'EUR',
					'GBP',
					'HKD',
					'HUF',
					'ILS',
					'JPY',
					'MXN',
					'NOK',
					'NZD',
					'PHP',
					'PLN',
					'SEK',
					'SGD',
					'THB',
					'TWD',
					'USD',
				),
				'FR' => array(
					'AUD',
					'BRL',
					'CAD',
					'CHF',
					'CZK',
					'DKK',
					'EUR',
					'GBP',
					'HKD',
					'HUF',
					'ILS',
					'JPY',
					'MXN',
					'NOK',
					'NZD',
					'PHP',
					'PLN',
					'SEK',
					'SGD',
					'THB',
					'TWD',
					'USD',
				),
				'DE' => array(
					'AUD',
					'BRL',
					'CAD',
					'CHF',
					'CZK',
					'DKK',
					'EUR',
					'GBP',
					'HKD',
					'HUF',
					'ILS',
					'JPY',
					'MXN',
					'NOK',
					'NZD',
					'PHP',
					'PLN',
					'SEK',
					'SGD',
					'THB',
					'TWD',
					'USD',
				),
				'GR' => array(
					'AUD',
					'BRL',
					'CAD',
					'CHF',
					'CZK',
					'DKK',
					'EUR',
					'GBP',
					'HKD',
					'HUF',
					'ILS',
					'JPY',
					'MXN',
					'NOK',
					'NZD',
					'PHP',
					'PLN',
					'SEK',
					'SGD',
					'THB',
					'TWD',
					'USD',
				),
				'HK' => array(
					'AUD',
					'BRL',
					'CAD',
					'CHF',
					'CZK',
					'DKK',
					'EUR',
					'GBP',
					'HKD',
					'HUF',
					'ILS',
					'JPY',
					'MXN',
					'NOK',
					'NZD',
					'PHP',
					'PLN',
					'SEK',
					'SGD',
					'THB',
					'TWD',
					'USD',
				),
				'HU' => array(
					'AUD',
					'BRL',
					'CAD',
					'CHF',
					'CZK',
					'DKK',
					'EUR',
					'GBP',
					'HKD',
					'HUF',
					'ILS',
					'JPY',
					'MXN',
					'NOK',
					'NZD',
					'PHP',
					'PLN',
					'SEK',
					'SGD',
					'THB',
					'TWD',
					'USD',
				),
				'IE' => array(
					'AUD',
					'BRL',
					'CAD',
					'CHF',
					'CZK',
					'DKK',
					'EUR',
					'GBP',
					'HKD',
					'HUF',
					'ILS',
					'JPY',
					'MXN',
					'NOK',
					'NZD',
					'PHP',
					'PLN',
					'SEK',
					'SGD',
					'THB',
					'TWD',
					'USD',
				),
				'IT' => array(
					'AUD',
					'BRL',
					'CAD',
					'CHF',
					'CZK',
					'DKK',
					'EUR',
					'GBP',
					'HKD',
					'HUF',
					'ILS',
					'JPY',
					'MXN',
					'NOK',
					'NZD',
					'PHP',
					'PLN',
					'SEK',
					'SGD',
					'THB',
					'TWD',
					'USD',
				),
				'JP' => array(
					'AUD',
					'BRL',
					'CAD',
					'CHF',
					'CZK',
					'DKK',
					'EUR',
					'GBP',
					'HKD',
					'HUF',
					'ILS',
					'JPY',
					'MXN',
					'NOK',
					'NZD',
					'PHP',
					'PLN',
					'SEK',
					'SGD',
					'THB',
					'TWD',
					'USD',
				),
				'LV' => array(
					'AUD',
					'BRL',
					'CAD',
					'CHF',
					'CZK',
					'DKK',
					'EUR',
					'GBP',
					'HKD',
					'HUF',
					'ILS',
					'JPY',
					'MXN',
					'NOK',
					'NZD',
					'PHP',
					'PLN',
					'SEK',
					'SGD',
					'THB',
					'TWD',
					'USD',
				),
				'LI' => array(
					'AUD',
					'BRL',
					'CAD',
					'CHF',
					'CZK',
					'DKK',
					'EUR',
					'GBP',
					'HKD',
					'HUF',
					'ILS',
					'JPY',
					'MXN',
					'NOK',
					'NZD',
					'PHP',
					'PLN',
					'SEK',
					'SGD',
					'THB',
					'TWD',
					'USD',
				),
				'LT' => array(
					'AUD',
					'BRL',
					'CAD',
					'CHF',
					'CZK',
					'DKK',
					'EUR',
					'GBP',
					'HKD',
					'HUF',
					'ILS',
					'JPY',
					'MXN',
					'NOK',
					'NZD',
					'PHP',
					'PLN',
					'SEK',
					'SGD',
					'THB',
					'TWD',
					'USD',
				),
				'LU' => array(
					'AUD',
					'BRL',
					'CAD',
					'CHF',
					'CZK',
					'DKK',
					'EUR',
					'GBP',
					'HKD',
					'HUF',
					'ILS',
					'JPY',
					'MXN',
					'NOK',
					'NZD',
					'PHP',
					'PLN',
					'SEK',
					'SGD',
					'THB',
					'TWD',
					'USD',
				),
				'MT' => array(
					'AUD',
					'BRL',
					'CAD',
					'CHF',
					'CZK',
					'DKK',
					'EUR',
					'GBP',
					'HKD',
					'HUF',
					'ILS',
					'JPY',
					'MXN',
					'NOK',
					'NZD',
					'PHP',
					'PLN',
					'SEK',
					'SGD',
					'THB',
					'TWD',
					'USD',
				),
				'MX' => array(
					'MXN',
				),
				'NL' => array(
					'AUD',
					'BRL',
					'CAD',
					'CHF',
					'CZK',
					'DKK',
					'EUR',
					'GBP',
					'HKD',
					'HUF',
					'ILS',
					'JPY',
					'MXN',
					'NOK',
					'NZD',
					'PHP',
					'PLN',
					'SEK',
					'SGD',
					'THB',
					'TWD',
					'USD',
				),
				'PL' => array(
					'AUD',
					'BRL',
					'CAD',
					'CHF',
					'CZK',
					'DKK',
					'EUR',
					'GBP',
					'HKD',
					'HUF',
					'ILS',
					'JPY',
					'MXN',
					'NOK',
					'NZD',
					'PHP',
					'PLN',
					'SEK',
					'SGD',
					'THB',
					'TWD',
					'USD',
				),
				'PT' => array(
					'AUD',
					'BRL',
					'CAD',
					'CHF',
					'CZK',
					'DKK',
					'EUR',
					'GBP',
					'HKD',
					'HUF',
					'ILS',
					'JPY',
					'MXN',
					'NOK',
					'NZD',
					'PHP',
					'PLN',
					'SEK',
					'SGD',
					'THB',
					'TWD',
					'USD',
				),
				'RO' => array(
					'AUD',
					'BRL',
					'CAD',
					'CHF',
					'CZK',
					'DKK',
					'EUR',
					'GBP',
					'HKD',
					'HUF',
					'ILS',
					'JPY',
					'MXN',
					'NOK',
					'NZD',
					'PHP',
					'PLN',
					'SEK',
					'SGD',
					'THB',
					'TWD',
					'USD',
				),
				'SK' => array(
					'AUD',
					'BRL',
					'CAD',
					'CHF',
					'CZK',
					'DKK',
					'EUR',
					'GBP',
					'HKD',
					'HUF',
					'ILS',
					'JPY',
					'MXN',
					'NOK',
					'NZD',
					'PHP',
					'PLN',
					'SEK',
					'SGD',
					'THB',
					'TWD',
					'USD',
				),
				'SG' => array(
					'AUD',
					'BRL',
					'CAD',
					'CHF',
					'CZK',
					'DKK',
					'EUR',
					'GBP',
					'HKD',
					'HUF',
					'ILS',
					'JPY',
					'MXN',
					'NOK',
					'NZD',
					'PHP',
					'PLN',
					'SEK',
					'SGD',
					'THB',
					'TWD',
					'USD',
				),
				'SI' => array(
					'AUD',
					'BRL',
					'CAD',
					'CHF',
					'CZK',
					'DKK',
					'EUR',
					'GBP',
					'HKD',
					'HUF',
					'ILS',
					'JPY',
					'MXN',
					'NOK',
					'NZD',
					'PHP',
					'PLN',
					'SEK',
					'SGD',
					'THB',
					'TWD',
					'USD',
				),
				'ES' => array(
					'AUD',
					'BRL',
					'CAD',
					'CHF',
					'CZK',
					'DKK',
					'EUR',
					'GBP',
					'HKD',
					'HUF',
					'ILS',
					'JPY',
					'MXN',
					'NOK',
					'NZD',
					'PHP',
					'PLN',
					'SEK',
					'SGD',
					'THB',
					'TWD',
					'USD',
				),
				'SE' => array(
					'AUD',
					'BRL',
					'CAD',
					'CHF',
					'CZK',
					'DKK',
					'EUR',
					'GBP',
					'HKD',
					'HUF',
					'ILS',
					'JPY',
					'MXN',
					'NOK',
					'NZD',
					'PHP',
					'PLN',
					'SEK',
					'SGD',
					'THB',
					'TWD',
					'USD',
				),
				'GB' => array(
					'AUD',
					'BRL',
					'CAD',
					'CHF',
					'CZK',
					'DKK',
					'EUR',
					'GBP',
					'HKD',
					'HUF',
					'ILS',
					'JPY',
					'MXN',
					'NOK',
					'NZD',
					'PHP',
					'PLN',
					'SEK',
					'SGD',
					'THB',
					'TWD',
					'USD',
				),
				'US' => array(
					'AUD',
					'CAD',
					'EUR',
					'GBP',
					'JPY',
					'USD',
				),
				'NO' => array(
					'AUD',
					'BRL',
					'CAD',
					'CHF',
					'CZK',
					'DKK',
					'EUR',
					'GBP',
					'HKD',
					'HUF',
					'ILS',
					'JPY',
					'MXN',
					'NOK',
					'NZD',
					'PHP',
					'PLN',
					'SEK',
					'SGD',
					'THB',
					'TWD',
					'USD',
				),
			)
		);
	},

	/**
	 * Which countries support which credit cards. Empty credit card arrays mean no restriction on currency.
	 */
	'api.dcc-supported-country-card-matrix'          => static function ( ContainerInterface $container ) : array {
		/**
		 * Returns which countries support which credit cards. Empty credit card arrays mean no restriction on currency.
		 */
		return apply_filters(
			'woocommerce_paypal_payments_supported_country_card_matrix',
			array(
				'AU' => array(
					'mastercard' => array(),
					'visa'       => array(),
					'amex'       => array( 'AUD' ),
				),
				'AT' => array(
					'mastercard' => array(),
					'visa'       => array(),
					'amex'       => array(),
				),
				'BE' => array(
					'mastercard' => array(),
					'visa'       => array(),
					'amex'       => array(),
				),
				'BG' => array(
					'mastercard' => array(),
					'visa'       => array(),
					'amex'       => array(),
				),
				'CN' => array(
					'mastercard' => array(),
					'visa'       => array(),
				),
				'CY' => array(
					'mastercard' => array(),
					'visa'       => array(),
					'amex'       => array(),
				),
				'CZ' => array(
					'mastercard' => array(),
					'visa'       => array(),
					'amex'       => array(),
				),
				'DE' => array(
					'mastercard' => array(),
					'visa'       => array(),
					'amex'       => array(),
				),
				'DK' => array(
					'mastercard' => array(),
					'visa'       => array(),
					'amex'       => array(),
				),
				'EE' => array(
					'mastercard' => array(),
					'visa'       => array(),
					'amex'       => array(),
				),
				'ES' => array(
					'mastercard' => array(),
					'visa'       => array(),
					'amex'       => array(),
				),
				'FI' => array(
					'mastercard' => array(),
					'visa'       => array(),
					'amex'       => array(),
				),
				'FR' => array(
					'mastercard' => array(),
					'visa'       => array(),
					'amex'       => array(),
				),
				'GB' => array(
					'mastercard' => array(),
					'visa'       => array(),
					'amex'       => array(),
				),
				'GR' => array(
					'mastercard' => array(),
					'visa'       => array(),
					'amex'       => array(),
				),
				'HK' => array(
					'mastercard' => array(),
					'visa'       => array(),
				),
				'HU' => array(
					'mastercard' => array(),
					'visa'       => array(),
					'amex'       => array(),
				),
				'IE' => array(
					'mastercard' => array(),
					'visa'       => array(),
					'amex'       => array(),
				),
				'IT' => array(
					'mastercard' => array(),
					'visa'       => array(),
					'amex'       => array(),
				),
				'US' => array(
					'mastercard' => array(),
					'visa'       => array(),
					'amex'       => array( 'USD' ),
					'discover'   => array( 'USD' ),
				),
				'CA' => array(
					'mastercard' => array(),
					'visa'       => array(),
					'amex'       => array( 'CAD', 'USD' ),
					'jcb'        => array( 'CAD' ),
				),
				'LI' => array(
					'mastercard' => array(),
					'visa'       => array(),
					'amex'       => array(),
				),
				'LT' => array(
					'mastercard' => array(),
					'visa'       => array(),
					'amex'       => array(),
				),
				'LU' => array(
					'mastercard' => array(),
					'visa'       => array(),
					'amex'       => array(),
				),
				'LV' => array(
					'mastercard' => array(),
					'visa'       => array(),
					'amex'       => array(),
				),
				'MT' => array(
					'mastercard' => array(),
					'visa'       => array(),
					'amex'       => array(),
				),
				'MX' => array(
					'mastercard' => array(),
					'visa'       => array(),
					'amex'       => array(),
				),
				'NL' => array(
					'mastercard' => array(),
					'visa'       => array(),
					'amex'       => array(),
				),
				'NO' => array(
					'mastercard' => array(),
					'visa'       => array(),
					'amex'       => array(),
				),
				'PL' => array(
					'mastercard' => array(),
					'visa'       => array(),
					'amex'       => array(),
				),
				'PT' => array(
					'mastercard' => array(),
					'visa'       => array(),
					'amex'       => array(),
				),
				'RO' => array(
					'mastercard' => array(),
					'visa'       => array(),
					'amex'       => array(),
				),
				'SE' => array(
					'mastercard' => array(),
					'visa'       => array(),
					'amex'       => array(),
				),
				'SI' => array(
					'mastercard' => array(),
					'visa'       => array(),
					'amex'       => array(),
				),
				'SK' => array(
					'mastercard' => array(),
					'visa'       => array(),
					'amex'       => array(),
				),
				'SG' => array(
					'mastercard' => array(),
					'visa'       => array(),
				),
				'JP' => array(
					'mastercard' => array(),
					'visa'       => array(),
					'amex'       => array( 'JPY' ),
					'jcb'        => array( 'JPY' ),
				),
			)
		);
	},

	'api.psd2-countries'                             => static function ( ContainerInterface $container ) : array {
		return array(
			'AT',
			'BE',
			'BG',
			'CY',
			'CZ',
			'DK',
			'EE',
			'FI',
			'FR',
			'DE',
			'GB',
			'GR',
			'HU',
			'IE',
			'IT',
			'LV',
			'LT',
			'LU',
			'MT',
			'NL',
			'NO',
			'PL',
			'PT',
			'RO',
			'SK',
			'SI',
			'ES',
			'SE',
		);
	},
	'api.order-helper'                               => static function( ContainerInterface $container ): OrderHelper {
		return new OrderHelper();
	},
	'api.helper.order-transient'                     => static function( ContainerInterface $container ): OrderTransient {
		$cache                   = new Cache( 'ppcp-paypal-bearer' );
		$purchase_unit_sanitizer = $container->get( 'api.helper.purchase-unit-sanitizer' );
		return new OrderTransient( $cache, $purchase_unit_sanitizer );
	},
	'api.helper.failure-registry'                    => static function( ContainerInterface $container ): FailureRegistry {
		$cache = new Cache( 'ppcp-paypal-api-status-cache' );
		return new FailureRegistry( $cache );
	},
	'api.helper.purchase-unit-sanitizer'             => SingletonDecorator::make(
		static function( ContainerInterface $container ): PurchaseUnitSanitizer {
			$settings  = $container->get( 'wcgateway.settings' );
			assert( $settings instanceof Settings );

			$behavior  = $settings->has( 'subtotal_mismatch_behavior' ) ? $settings->get( 'subtotal_mismatch_behavior' ) : null;
			$line_name = $settings->has( 'subtotal_mismatch_line_name' ) ? $settings->get( 'subtotal_mismatch_line_name' ) : null;
			return new PurchaseUnitSanitizer( $behavior, $line_name );
		}
	),
	'api.user-id-token'                              => static function( ContainerInterface $container ): UserIdToken {
		return new UserIdToken(
			$container->get( 'api.host' ),
			$container->get( 'api.bearer' ),
			$container->get( 'woocommerce.logger.woocommerce' )
		);
	},
	'api.sdk-client-token'                           => static function( ContainerInterface $container ): SdkClientToken {
		return new SdkClientToken(
			$container->get( 'api.host' ),
			$container->get( 'api.bearer' ),
			$container->get( 'woocommerce.logger.woocommerce' )
		);
	},
);
