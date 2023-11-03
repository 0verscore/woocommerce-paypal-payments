<?php
/**
 * The Applepay module.
 *
 * @package WooCommerce\PayPalCommerce\Applepay
 */

declare(strict_types=1);

namespace WooCommerce\PayPalCommerce\Applepay;

use Automattic\WooCommerce\Blocks\Payments\PaymentMethodRegistry;
use WooCommerce\PayPalCommerce\Applepay\Assets\ApplePayButton;
use WooCommerce\PayPalCommerce\Applepay\Assets\AppleProductStatus;
use WooCommerce\PayPalCommerce\Applepay\Assets\PropertiesDictionary;
use WooCommerce\PayPalCommerce\Button\Assets\ButtonInterface;
use WooCommerce\PayPalCommerce\Button\Assets\SmartButtonInterface;
use WooCommerce\PayPalCommerce\Applepay\Helper\AvailabilityNotice;
use WooCommerce\PayPalCommerce\Onboarding\Environment;
use WooCommerce\PayPalCommerce\Vendor\Dhii\Container\ServiceProvider;
use WooCommerce\PayPalCommerce\Vendor\Dhii\Modular\Module\ModuleInterface;
use WooCommerce\PayPalCommerce\Vendor\Interop\Container\ServiceProviderInterface;
use WooCommerce\PayPalCommerce\Vendor\Psr\Container\ContainerInterface;
use WooCommerce\PayPalCommerce\WcGateway\Settings\Settings;

/**
 * Class ApplepayModule
 */
class ApplepayModule implements ModuleInterface {
	/**
	 * {@inheritDoc}
	 */
	public function setup(): ServiceProviderInterface {
		return new ServiceProvider(
			require __DIR__ . '/../services.php',
			require __DIR__ . '/../extensions.php'
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function run( ContainerInterface $c ): void {
		$module = $this;

		// Clears product status when appropriate.
		add_action(
			'woocommerce_paypal_payments_clear_apm_product_status',
			function( Settings $settings = null ) use ( $c ): void {
				$apm_status = $c->get( 'applepay.apple-product-status' );
				assert( $apm_status instanceof AppleProductStatus );
				$apm_status->clear( $settings );
			}
		);

		add_action(
			'init',
			static function () use ( $c, $module ) {

				// Check if the module is applicable, correct country, currency, ... etc.
				if ( ! $c->get( 'applepay.eligible' ) ) {
					return;
				}

				// Load the button handler.
				$apple_payment_method = $c->get( 'applepay.button' );
				// add onboarding and referrals hooks.
				assert( $apple_payment_method instanceof ApplepayButton );
				$apple_payment_method->initialize();

				// Show notice if there are product availability issues.
				$availability_notice = $c->get( 'applepay.availability_notice' );
				assert( $availability_notice instanceof AvailabilityNotice );
				$availability_notice->execute();

				// Return if server not supported.
				if ( ! $c->get( 'applepay.server_supported' ) ) {
					return;
				}

				// Check if this merchant can activate / use the buttons.
				// We allow non referral merchants as they can potentially still use ApplePay, we just have no way of checking the capability.
				if ( ( ! $c->get( 'applepay.available' ) ) && $c->get( 'applepay.is_referral' ) ) {
					return;
				}

				if ( $apple_payment_method->is_enabled() ) {
					$module->load_assets( $c, $apple_payment_method );
					$module->handle_validation_file( $c, $apple_payment_method );
					$module->render_buttons( $c, $apple_payment_method );
					$apple_payment_method->bootstrap_ajax_request();
				}

 				$module->load_admin_assets( $c, $apple_payment_method );
      },
			1
		);

		add_filter(
			'nonce_user_logged_out',
			/**
			 * Prevents nonce from being changed for non logged in users.
			 *
			 * @param int $uid The uid.
			 * @param string|int $action The action.
			 * @return int
			 *
			 * @psalm-suppress MissingClosureParamType
			 */
			function ( $uid, $action ) {
				if ( $action === PropertiesDictionary::NONCE_ACTION ) {
					return 0;
				}
				return $uid;
			},
			100,
			2
		);
	}

	/**
	 * Returns the key for the module.
	 *
	 * @return string|void
	 */
	public function getKey() {
	}

	/**
	 * Loads the validation string.
	 *
	 * @param boolean $is_sandbox The environment for this merchant.
	 */
	protected function load_domain_association_file( bool $is_sandbox ): void {
		if ( ! isset( $_SERVER['REQUEST_URI'] ) ) {
			return;
		}
		$request_uri = (string) filter_var( wp_unslash( $_SERVER['REQUEST_URI'] ), FILTER_SANITIZE_URL );
		if ( strpos( $request_uri, '.well-known/apple-developer-merchantid-domain-association' ) !== false ) {
			$validation_string = $this->validation_string( $is_sandbox );
			nocache_headers();
			header( 'Content-Type: text/plain', true, 200 );
			echo $validation_string;// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			exit;
		}
	}

	/**
	 * Registers and enqueues the assets.
	 *
	 * @param ContainerInterface $c The container.
	 * @param ApplePayButton     $button The button.
	 * @return void
	 */
	public function load_assets( ContainerInterface $c, ApplePayButton $button ): void {
		if ( ! $button->is_enabled() ) {
			return;
		}

		add_action(
			'wp_enqueue_scripts',
			function () use ( $c, $button ) {
				$smart_button = $c->get( 'button.smart-button' );
				assert( $smart_button instanceof SmartButtonInterface );
				if ( $smart_button->should_load_ppcp_script() ) {
					$button->enqueue();
				}

				if ( has_block( 'woocommerce/checkout' ) || has_block( 'woocommerce/cart' ) ) {
					/**
					 * Should add this to the ButtonInterface.
					 *
					 * @psalm-suppress UndefinedInterfaceMethod
					 */
					$button->enqueue_styles();
				}
			}
		);
		add_action(
			'woocommerce_blocks_payment_method_type_registration',
			function( PaymentMethodRegistry $payment_method_registry ) use ( $c ): void {
				$payment_method_registry->register( $c->get( 'applepay.blocks-payment-method' ) );
			}
		);
	}

	/**
	 * Registers and enqueues the assets.
	 *
	 * @param ContainerInterface $c The container.
	 * @param ApplePayButton     $button The button.
	 * @return void
	 */
	public function load_admin_assets( ContainerInterface $c, ApplePayButton $button ): void {
		// Enqueue backend scripts.
		add_action(
			'admin_enqueue_scripts',
			static function () use ( $c, $button ) {
				if ( ! is_admin() ) {
					return;
				}

				/**
				 * Should add this to the ButtonInterface.
				 *
				 * @psalm-suppress UndefinedInterfaceMethod
				 */
				$button->enqueue_admin();
			}
		);

		// Adds ApplePay component to the backend button preview settings.
		add_action(
			'woocommerce_paypal_payments_admin_gateway_settings',
			function( array $settings ) use ( $c ): array {
				if ( is_array( $settings['components'] ) ) {
					$settings['components'][] = 'applepay';
				}
				return $settings;
			}
		);
	}

	/**
	 * Renders the Apple Pay buttons in the enabled places.
	 *
	 * @param ContainerInterface $c The container.
	 * @param ApplePayButton     $button The button.
	 * @return void
	 */
	public function render_buttons( ContainerInterface $c, ApplePayButton $button ): void {
		if ( ! $button->is_enabled() ) {
			return;
		}

		add_action(
			'wp',
			static function () use ( $c ) {
				if ( is_admin() ) {
					return;
				}
				$button = $c->get( 'applepay.button' );

				/**
				 * The Button.
				 *
				 * @var ButtonInterface $button
				 */
				$button->render();
			}
		);
	}

	/**
	 * Handles the validation file.
	 *
	 * @param ContainerInterface $c The container.
	 * @param ApplePayButton     $button The button.
	 * @return void
	 */
	public function handle_validation_file( ContainerInterface $c, ApplePayButton $button ): void {
		if ( ! $button->is_enabled() ) {
			return;
		}
		$env = $c->get( 'onboarding.environment' );
		assert( $env instanceof Environment );
		$is_sandobx = $env->current_environment_is( Environment::SANDBOX );
		$this->load_domain_association_file( $is_sandobx );
	}

	/**
	 * Returns the validation string, depending on the environment.
	 *
	 * @param bool $is_sandbox The environment for this merchant.
	 * @return string
	 */
	public function validation_string( bool $is_sandbox ) {
		$sandbox_string = $this->sandbox_validation_string();
		$live_string    = $this->live_validation_string();
		return $is_sandbox ? $sandbox_string : $live_string;
	}

	/**
	 * Returns the sandbox validation string.
	 *
	 * @return string
	 */
	private function sandbox_validation_string(): string {
		return apply_filters(
			'woocommerce_paypal_payments_applepay_sandbox_validation_string',
			'7B227073704964223A2241443631324543383841333039314132314539434132433035304439454130353741414535444341304542413237424243333838463239344231353534434233222C2276657273696F6E223A312C22637265617465644F6E223A313632343438393037393630362C227369676E6174757265223A2233303830303630393261383634383836663730643031303730326130383033303830303230313031333130663330306430363039363038363438303136353033303430323031303530303330383030363039326138363438383666373064303130373031303030306130383033303832303365343330383230333862613030333032303130323032303835396438613162636161663465336364333030613036303832613836343863653364303430333032333037613331326533303263303630333535303430333063323534313730373036633635323034313730373036633639363336313734363936663665323034393665373436353637373236313734363936663665323034333431323032643230343733333331323633303234303630333535303430623063316434313730373036633635323034333635373237343639363636393633363137343639366636653230343137353734363836663732363937343739333131333330313130363033353530343061306330613431373037303663363532303439366536333265333130623330303930363033353530343036313330323535353333303165313730643332333133303334333233303331333933333337333033303561313730643332333633303334333133393331333933333336333533393561333036323331323833303236303630333535303430333063316636353633363332643733366437303264363237323666366236353732326437333639363736653566353534333334326435333431346534343432346635383331313433303132303630333535303430623063306236393466353332303533373937333734363536643733333131333330313130363033353530343061306330613431373037303663363532303439366536333265333130623330303930363033353530343036313330323535353333303539333031333036303732613836343863653364303230313036303832613836343863653364303330313037303334323030303438323330666461626333396366373565323032633530643939623435313265363337653261393031646436636233653062316364346235323637393866386366346562646538316132356138633231653463333364646365386532613936633266366166613139333033343563346538376134343236636539353162313239356133383230323131333038323032306433303063303630333535316431333031303166663034303233303030333031663036303335353164323330343138333031363830313432336632343963343466393365346566323765366334663632383663336661326262666432653462333034353036303832623036303130353035303730313031303433393330333733303335303630383262303630313035303530373330303138363239363837343734373033613266326636663633373337303265363137303730366336353265363336663664326636663633373337303330333432643631373037303663363536313639363336313333333033323330383230313164303630333535316432303034383230313134333038323031313033303832303130633036303932613836343838366637363336343035303133303831666533303831633330363038326230363031303530353037303230323330383162363063383162333532363536633639363136653633363532303666366532303734363836393733323036333635373237343639363636393633363137343635323036323739323036313665373932303730363137323734373932303631373337333735366436353733323036313633363336353730373436313665363336353230366636363230373436383635323037343638363536653230363137303730366336393633363136323663363532303733373436313665363436313732363432303734363537323664373332303631366536343230363336663665363436393734363936663665373332303666363632303735373336353263323036333635373237343639363636393633363137343635323037303666366336393633373932303631366536343230363336353732373436393636363936333631373436393666366532303730373236313633373436393633363532303733373436313734363536643635366537343733326533303336303630383262303630313035303530373032303131363261363837343734373033613266326637373737373732653631373037303663363532653633366636643266363336353732373436393636363936333631373436353631373537343638366637323639373437393266333033343036303335353164316630343264333032623330323961303237613032353836323336383734373437303361326632663633373236633265363137303730366336353265363336663664326636313730373036633635363136393633363133333265363337323663333031643036303335353164306530343136303431343032323433303062396165656564343633313937613461363561323939653432373138323163343533303065303630333535316430663031303166663034303430333032303738303330306630363039326138363438383666373633363430363164303430323035303033303061303630383261383634386365336430343033303230333437303033303434303232303734613162333234646234323439343330646433323734633530373463343830386439613166343830653361383563356331333632353636333235666263613330323230363933363930353361626635306235613532663966363030346463353861616436633530613764363038363833373930653061373361643031653461643938313330383230326565333038323032373561303033303230313032303230383439366432666266336139386461393733303061303630383261383634386365336430343033303233303637333131623330313930363033353530343033306331323431373037303663363532303532366636663734323034333431323032643230343733333331323633303234303630333535303430623063316434313730373036633635323034333635373237343639363636393633363137343639366636653230343137353734363836663732363937343739333131333330313130363033353530343061306330613431373037303663363532303439366536333265333130623330303930363033353530343036313330323535353333303165313730643331333433303335333033363332333333343336333333303561313730643332333933303335333033363332333333343336333333303561333037613331326533303263303630333535303430333063323534313730373036633635323034313730373036633639363336313734363936663665323034393665373436353637373236313734363936663665323034333431323032643230343733333331323633303234303630333535303430623063316434313730373036633635323034333635373237343639363636393633363137343639366636653230343137353734363836663732363937343739333131333330313130363033353530343061306330613431373037303663363532303439366536333265333130623330303930363033353530343036313330323535353333303539333031333036303732613836343863653364303230313036303832613836343863653364303330313037303334323030303466303137313138343139643736343835643531613565323538313037373665383830613265666465376261653464653038646663346239336531333335366435363635623335616532326430393737363064323234653762626130386664373631376365383863623736626236363730626563386538323938346666353434356133383166373330383166343330343630363038326230363031303530353037303130313034336133303338333033363036303832623036303130353035303733303031383632613638373437343730336132663266366636333733373032653631373037303663363532653633366636643266366636333733373033303334326436313730373036633635373236663666373436333631363733333330316430363033353531643065303431363034313432336632343963343466393365346566323765366334663632383663336661326262666432653462333030663036303335353164313330313031666630343035333030333031303166663330316630363033353531643233303431383330313638303134626262306465613135383333383839616134386139396465626562646562616664616362323461623330333730363033353531643166303433303330326533303263613032616130323838363236363837343734373033613266326636333732366332653631373037303663363532653633366636643266363137303730366336353732366636663734363336313637333332653633373236633330306530363033353531643066303130316666303430343033303230313036333031303036306132613836343838366637363336343036303230653034303230353030333030613036303832613836343863653364303430333032303336373030333036343032333033616366373238333531313639396231383666623335633335366361363262666634313765646439306637353464613238656265663139633831356534326237383966383938663739623539396639386435343130643866396465396332666530323330333232646435343432316230613330353737366335646633333833623930363766643137376332633231366439363466633637323639383231323666353466383761376431623939636239623039383932313631303639393066303939323164303030303331383230313863333038323031383830323031303133303831383633303761333132653330326330363033353530343033306332353431373037303663363532303431373037303663363936333631373436393666366532303439366537343635363737323631373436393666366532303433343132303264323034373333333132363330323430363033353530343062306331643431373037303663363532303433363537323734363936363639363336313734363936663665323034313735373436383666373236393734373933313133333031313036303335353034306130633061343137303730366336353230343936653633326533313062333030393036303335353034303631333032353535333032303835396438613162636161663465336364333030643036303936303836343830313635303330343032303130353030613038313935333031383036303932613836343838366637306430313039303333313062303630393261383634383836663730643031303730313330316330363039326138363438383666373064303130393035333130663137306433323331333033363332333333323332333533373335333935613330326130363039326138363438383666373064303130393334333131643330316233303064303630393630383634383031363530333034303230313035303061313061303630383261383634386365336430343033303233303266303630393261383634383836663730643031303930343331323230343230343239323932333766306638303764346538373932333134643438393635623165626262633038636265386333333432643365643261333939623963336538353330306130363038326138363438636533643034303330323034343733303435303232313030643430333138326637626530396663386265393738316463646461613434623332663362353634386566353666323664323933363738343237393933616530383032323037646663306563316361306461343531653464663031386236376538326231366330313065313930373431363762666632363839356230336563336430396134303030303030303030303030227D'
		);
	}

	/**
	 * Returns the live validation string.
	 *
	 * @return string
	 */
	private function live_validation_string(): string {
		return apply_filters(
			'woocommerce_paypal_payments_applepay_live_validation_string',
			'7B227073704964223A2246354246304143324336314131413238313043373531453439333444414433384346393037313041303935303844314133453241383436314141363232414145222C2276657273696F6E223A312C22637265617465644F6E223A313633343737323736393531342C227369676E6174757265223A223330383030363039326138363438383666373064303130373032613038303330383030323031303133313066333030643036303936303836343830313635303330343032303130353030333038303036303932613836343838366637306430313037303130303030613038303330383230336533333038323033383861303033303230313032303230383463333034313439353139643534333633303061303630383261383634386365336430343033303233303761333132653330326330363033353530343033306332353431373037303663363532303431373037303663363936333631373436393666366532303439366537343635363737323631373436393666366532303433343132303264323034373333333132363330323430363033353530343062306331643431373037303663363532303433363537323734363936363639363336313734363936663665323034313735373436383666373236393734373933313133333031313036303335353034306130633061343137303730366336353230343936653633326533313062333030393036303335353034303631333032353535333330316531373064333133393330333533313338333033313333333233353337356131373064333233343330333533313336333033313333333233353337356133303566333132353330323330363033353530343033306331633635363336333264373336643730326436323732366636623635373232643733363936373665356635353433333432643530353234663434333131343330313230363033353530343062306330623639346635333230353337393733373436353664373333313133333031313036303335353034306130633061343137303730366336353230343936653633326533313062333030393036303335353034303631333032353535333330353933303133303630373261383634386365336430323031303630383261383634386365336430333031303730333432303030346332313537376564656264366337623232313866363864643730393061313231386463376230626436663263323833643834363039356439346166346135343131623833343230656438313166333430376538333333316631633534633366376562333232306436626164356434656666343932383938393365376330663133613338323032313133303832303230643330306330363033353531643133303130316666303430323330303033303166303630333535316432333034313833303136383031343233663234396334346639336534656632376536633466363238366333666132626266643265346233303435303630383262303630313035303530373031303130343339333033373330333530363038326230363031303530353037333030313836323936383734373437303361326632663666363337333730326536313730373036633635326536333666366432663666363337333730333033343264363137303730366336353631363936333631333333303332333038323031316430363033353531643230303438323031313433303832303131303330383230313063303630393261383634383836663736333634303530313330383166653330383163333036303832623036303130353035303730323032333038316236306338316233353236353663363936313665363336353230366636653230373436383639373332303633363537323734363936363639363336313734363532303632373932303631366537393230373036313732373437393230363137333733373536643635373332303631363336333635373037343631366536333635323036663636323037343638363532303734363836353665323036313730373036633639363336313632366336353230373337343631366536343631373236343230373436353732366437333230363136653634323036333666366536343639373436393666366537333230366636363230373537333635326332303633363537323734363936363639363336313734363532303730366636633639363337393230363136653634323036333635373237343639363636393633363137343639366636653230373037323631363337343639363336353230373337343631373436353664363536653734373332653330333630363038326230363031303530353037303230313136326136383734373437303361326632663737373737373265363137303730366336353265363336663664326636333635373237343639363636393633363137343635363137353734363836663732363937343739326633303334303630333535316431663034326433303262333032396130323761303235383632333638373437343730336132663266363337323663326536313730373036633635326536333666366432663631373037303663363536313639363336313333326536333732366333303164303630333535316430653034313630343134393435376462366664353734383138363839383937363266376535373835303765373962353832343330306530363033353531643066303130316666303430343033303230373830333030663036303932613836343838366637363336343036316430343032303530303330306130363038326138363438636533643034303330323033343930303330343630323231303062653039353731666537316531653733356235356535616661636234633732666562343435663330313835323232633732353130303262363165626436663535303232313030643138623335306135646436646436656231373436303335623131656232636538376366613365366166366362643833383038393064633832636464616136333330383230326565333038323032373561303033303230313032303230383439366432666266336139386461393733303061303630383261383634386365336430343033303233303637333131623330313930363033353530343033306331323431373037303663363532303532366636663734323034333431323032643230343733333331323633303234303630333535303430623063316434313730373036633635323034333635373237343639363636393633363137343639366636653230343137353734363836663732363937343739333131333330313130363033353530343061306330613431373037303663363532303439366536333265333130623330303930363033353530343036313330323535353333303165313730643331333433303335333033363332333333343336333333303561313730643332333933303335333033363332333333343336333333303561333037613331326533303263303630333535303430333063323534313730373036633635323034313730373036633639363336313734363936663665323034393665373436353637373236313734363936663665323034333431323032643230343733333331323633303234303630333535303430623063316434313730373036633635323034333635373237343639363636393633363137343639366636653230343137353734363836663732363937343739333131333330313130363033353530343061306330613431373037303663363532303439366536333265333130623330303930363033353530343036313330323535353333303539333031333036303732613836343863653364303230313036303832613836343863653364303330313037303334323030303466303137313138343139643736343835643531613565323538313037373665383830613265666465376261653464653038646663346239336531333335366435363635623335616532326430393737363064323234653762626130386664373631376365383863623736626236363730626563386538323938346666353434356133383166373330383166343330343630363038326230363031303530353037303130313034336133303338333033363036303832623036303130353035303733303031383632613638373437343730336132663266366636333733373032653631373037303663363532653633366636643266366636333733373033303334326436313730373036633635373236663666373436333631363733333330316430363033353531643065303431363034313432336632343963343466393365346566323765366334663632383663336661326262666432653462333030663036303335353164313330313031666630343035333030333031303166663330316630363033353531643233303431383330313638303134626262306465613135383333383839616134386139396465626562646562616664616362323461623330333730363033353531643166303433303330326533303263613032616130323838363236363837343734373033613266326636333732366332653631373037303663363532653633366636643266363137303730366336353732366636663734363336313637333332653633373236633330306530363033353531643066303130316666303430343033303230313036333031303036306132613836343838366637363336343036303230653034303230353030333030613036303832613836343863653364303430333032303336373030333036343032333033616366373238333531313639396231383666623335633335366361363262666634313765646439306637353464613238656265663139633831356534326237383966383938663739623539396639386435343130643866396465396332666530323330333232646435343432316230613330353737366335646633333833623930363766643137376332633231366439363466633637323639383231323666353466383761376431623939636239623039383932313631303639393066303939323164303030303331383230313863333038323031383830323031303133303831383633303761333132653330326330363033353530343033306332353431373037303663363532303431373037303663363936333631373436393666366532303439366537343635363737323631373436393666366532303433343132303264323034373333333132363330323430363033353530343062306331643431373037303663363532303433363537323734363936363639363336313734363936663665323034313735373436383666373236393734373933313133333031313036303335353034306130633061343137303730366336353230343936653633326533313062333030393036303335353034303631333032353535333032303834633330343134393531396435343336333030643036303936303836343830313635303330343032303130353030613038313935333031383036303932613836343838366637306430313039303333313062303630393261383634383836663730643031303730313330316330363039326138363438383666373064303130393035333130663137306433323331333133303332333033323333333333323334333935613330326130363039326138363438383666373064303130393334333131643330316233303064303630393630383634383031363530333034303230313035303061313061303630383261383634386365336430343033303233303266303630393261383634383836663730643031303930343331323230343230623935666665303261316539316665656565396330623239616361656661643465333031396331666237626238313665366631623762343233346666306533353330306130363038326138363438636533643034303330323034343733303435303232303665356233363937366364383733653632623339326330353136633134326362356639303938663330323535656435343938633436393039356133636462346430323231303038396261626335356162626635653037393163633139373562306535383630633937336532336661313266643338343533303930353938343061326363363337303030303030303030303030227D'
		);
	}
}
