/* global ApplePaySession */
/* global PayPalCommerceGateway */

import ContextHandlerFactory from './Context/ContextHandlerFactory';
import { createAppleErrors } from './Helper/applePayError';
import { setVisible } from '../../../ppcp-button/resources/js/modules/Helper/Hiding';
import { setEnabled } from '../../../ppcp-button/resources/js/modules/Helper/ButtonDisabler';
import FormValidator from '../../../ppcp-button/resources/js/modules/Helper/FormValidator';
import ErrorHandler from '../../../ppcp-button/resources/js/modules/ErrorHandler';
import widgetBuilder from '../../../ppcp-button/resources/js/modules/Renderer/WidgetBuilder';
import { apmButtonsInit } from '../../../ppcp-button/resources/js/modules/Helper/ApmButtons';

/**
 * List of valid context values that the button can have.
 *
 * @type {Object}
 */
const CONTEXT = {
	Product: 'product',
	Cart: 'cart',
	Checkout: 'checkout',
	PayNow: 'pay-now',
	MiniCart: 'mini-cart',
	BlockCart: 'cart-block',
	BlockCheckout: 'checkout-block',
	Preview: 'preview',
	Blocks: [ 'cart-block', 'checkout-block' ],
};

/**
 * A payment button for Apple Pay.
 *
 * On a single page, multiple Apple Pay buttons can be displayed, which also means multiple
 * ApplePayButton instances exist. A typical case is on the product page, where one Apple Pay button
 * is located inside the minicart-popup, and another pay-now button is in the product context.
 */
class ApplePayButton {
	/**
	 * Whether the payment button is initialized.
	 *
	 * @type {boolean}
	 */
	isInitialized = false;

	/**
	 * Context describes the button's location on the website and what details it submits.
	 *
	 * @type {''|'product'|'cart'|'checkout'|'pay-now'|'mini-cart'|'cart-block'|'checkout-block'|'preview'}
	 */
	context = '';

	externalHandler = null;
	buttonConfig = null;
	ppcpConfig = null;
	paymentsClient = null;
	formData = null;
	contextHandler = null;
	updatedContactInfo = [];
	selectedShippingMethod = [];

	/**
	 * Stores initialization data sent to the button.
	 */
	initialPaymentRequest = null;

	constructor( context, externalHandler, buttonConfig, ppcpConfig ) {
		apmButtonsInit( ppcpConfig );

		this.context = context;
		this.externalHandler = externalHandler;
		this.buttonConfig = buttonConfig;
		this.ppcpConfig = ppcpConfig;

		this.contextHandler = ContextHandlerFactory.create(
			this.context,
			this.buttonConfig,
			this.ppcpConfig
		);

		this.log = function () {
			if ( this.buttonConfig.is_debug ) {
				//console.log('[ApplePayButton]', ...arguments);
			}
		};

		this.refreshContextData();

		// Debug helpers
		jQuery( document ).on( 'ppcp-applepay-debug', () => {
			console.log( 'ApplePayButton', this.context, this );
		} );
		document.ppcpApplepayButtons = document.ppcpApplepayButtons || {};
		document.ppcpApplepayButtons[ this.context ] = this;
	}

	/**
	 * The nonce for ajax requests.
	 *
	 * @return {string} The nonce value
	 */
	get nonce() {
		const input = document.getElementById(
			'woocommerce-process-checkout-nonce'
		);

		return input?.value || this.buttonConfig.nonce;
	}

	/**
	 * Whether the current page qualifies to use the Apple Pay button.
	 *
	 * In admin, the button is always eligible, to display an accurate preview.
	 * On front-end, PayPal's response decides if customers can use Apple Pay.
	 *
	 * @return {boolean} True, if the button can be displayed.
	 */
	get isEligible() {
		if ( ! this.isInitialized ) {
			return true;
		}

		if ( this.buttonConfig.is_admin ) {
			return true;
		}

		return !! ( this.applePayConfig.isEligible && window.ApplePaySession );
	}

	init( config ) {
		if ( this.isInitialized ) {
			return;
		}

		if ( ! this.contextHandler.validateContext() ) {
			return;
		}

		this.log( 'Init', this.context );
		this.initEventHandlers();

		this.isInitialized = true;
		this.applePayConfig = config;

		const idMinicart = this.buttonConfig.button.mini_cart_wrapper;
		const idButton = this.buttonConfig.button.wrapper;

		if ( ! this.isEligible ) {
			jQuery( '#' + idButton ).hide();
			jQuery( '#' + idMinicart ).hide();
			jQuery( '#express-payment-method-ppcp-applepay' ).hide();

			return;
		}

		// Add click-handler to the button.
		const setupButtonEvents = ( id ) => {
			document
				.getElementById( id )
				?.addEventListener( 'click', ( evt ) => {
					evt.preventDefault();
					this.onButtonClick();
				} );
		};

		this.fetchTransactionInfo().then( () => {
			this.addButton();

			if ( CONTEXT.MiniCart === this.context ) {
				setupButtonEvents( idMinicart );
			} else {
				setupButtonEvents( idButton );
			}
		} );
	}

	reinit() {
		if ( ! this.applePayConfig ) {
			return;
		}

		this.isInitialized = false;
		this.init( this.applePayConfig );
	}

	async fetchTransactionInfo() {
		this.transactionInfo = await this.contextHandler.transactionInfo();
	}

	/**
	 * Returns configurations relative to this button context.
	 */
	contextConfig() {
		const config = {};

		if ( CONTEXT.MiniCart === this.context ) {
			config.wrapper = this.buttonConfig.button.mini_cart_wrapper;
			config.ppcpStyle = this.ppcpConfig.button.mini_cart_style;
			config.buttonStyle = this.buttonConfig.button.mini_cart_style;
			config.ppcpButtonWrapper = this.ppcpConfig.button.mini_cart_wrapper;
		} else {
			config.wrapper = this.buttonConfig.button.wrapper;
			config.ppcpStyle = this.ppcpConfig.button.style;
			config.buttonStyle = this.buttonConfig.button.style;
			config.ppcpButtonWrapper = this.ppcpConfig.button.wrapper;
		}

		// Block editor configuration.
		if ( CONTEXT.Blocks.includes( this.context ) ) {
			config.ppcpButtonWrapper =
				'#express-payment-method-ppcp-gateway-paypal';
		}

		return config;
	}

	initEventHandlers() {
		const { wrapper, ppcpButtonWrapper } = this.contextConfig();
		const wrapperId = '#' + wrapper;

		if ( wrapperId === ppcpButtonWrapper ) {
			throw new Error(
				`[ApplePayButton] "wrapper" and "ppcpButtonWrapper" values must differ to avoid infinite loop. Current value: "${ wrapperId }"`
			);
		}

		const syncButtonVisibility = () => {
			if ( ! this.isEligible ) {
				return;
			}

			const $ppcpButtonWrapper = jQuery( ppcpButtonWrapper );
			setVisible( wrapperId, $ppcpButtonWrapper.is( ':visible' ) );
			setEnabled(
				wrapperId,
				! $ppcpButtonWrapper.hasClass( 'ppcp-disabled' )
			);
		};

		jQuery( document ).on(
			'ppcp-shown ppcp-hidden ppcp-enabled ppcp-disabled',
			( ev, data ) => {
				if ( jQuery( data.selector ).is( ppcpButtonWrapper ) ) {
					syncButtonVisibility();
				}
			}
		);

		syncButtonVisibility();
	}

	/**
	 * Starts an Apple Pay session.
	 *
	 * @param {Object} paymentRequest The payment request object.
	 */
	applePaySession( paymentRequest ) {
		this.log( 'applePaySession', paymentRequest );
		const session = new ApplePaySession( 4, paymentRequest );
		session.begin();

		if ( this.shouldRequireShippingInButton() ) {
			session.onshippingmethodselected =
				this.onShippingMethodSelected( session );
			session.onshippingcontactselected =
				this.onShippingContactSelected( session );
		}

		session.onvalidatemerchant = this.onValidateMerchant( session );
		session.onpaymentauthorized = this.onPaymentAuthorized( session );
		return session;
	}

	/**
	 * Adds an Apple Pay purchase button.
	 */
	addButton() {
		this.log( 'addButton', this.context );

		const { wrapper, ppcpStyle } = this.contextConfig();

		const appleContainer = document.getElementById( wrapper );
		const type = this.buttonConfig.button.type;
		const language = this.buttonConfig.button.lang;
		const color = this.buttonConfig.button.color;
		const id = 'apple-' + wrapper;

		if ( ! appleContainer ) {
			return;
		}

		appleContainer.innerHTML = `<apple-pay-button id='${ id }' buttonstyle='${ color }' type='${ type }' locale='${ language }'>`;
		appleContainer.classList.add( 'ppcp-button-' + ppcpStyle.shape );

		if ( ppcpStyle.height ) {
			appleContainer.style.setProperty(
				'--apple-pay-button-height',
				`${ ppcpStyle.height }px`
			);
			appleContainer.style.height = `${ ppcpStyle.height }px`;
		}
	}

	//------------------------
	// Button click
	//------------------------

	/**
	 * Show Apple Pay payment sheet when Apple Pay payment button is clicked
	 */
	async onButtonClick() {
		this.log( 'onButtonClick', this.context );

		const paymentRequest = this.paymentRequest();

		// Do this on another place like on create order endpoint handler.
		window.ppcpFundingSource = 'apple_pay';

		// Trigger woocommerce validation if we are in the checkout page.
		if ( CONTEXT.Checkout === this.context ) {
			const checkoutFormSelector = 'form.woocommerce-checkout';
			const errorHandler = new ErrorHandler(
				PayPalCommerceGateway.labels.error.generic,
				document.querySelector( '.woocommerce-notices-wrapper' )
			);
			try {
				const formData = new FormData(
					document.querySelector( checkoutFormSelector )
				);
				this.formData = Object.fromEntries( formData.entries() );

				this.updateRequestDataWithForm( paymentRequest );
			} catch ( error ) {
				console.error( error );
			}

			this.log( '=== paymentRequest', paymentRequest );

			const session = this.applePaySession( paymentRequest );
			const formValidator =
				PayPalCommerceGateway.early_checkout_validation_enabled
					? new FormValidator(
							PayPalCommerceGateway.ajax.validate_checkout.endpoint,
							PayPalCommerceGateway.ajax.validate_checkout.nonce
					  )
					: null;
			if ( formValidator ) {
				try {
					const errors = await formValidator.validate(
						document.querySelector( checkoutFormSelector )
					);
					if ( errors.length > 0 ) {
						errorHandler.messages( errors );
						jQuery( document.body ).trigger( 'checkout_error', [
							errorHandler.currentHtml(),
						] );
						session.abort();
						return;
					}
				} catch ( error ) {
					console.error( error );
				}
			}
			return;
		}

		// Default session initialization.
		this.applePaySession( paymentRequest );
	}

	/**
	 * If the button should show the shipping fields.
	 *
	 * @return {boolean} True, if shipping fields should be captured by ApplePay.
	 */
	shouldRequireShippingInButton() {
		return (
			this.contextHandler.shippingAllowed() &&
			this.buttonConfig.product.needShipping &&
			( CONTEXT.Checkout !== this.context ||
				this.shouldUpdateButtonWithFormData() )
		);
	}

	/**
	 * If the button should be updated with the form addresses.
	 *
	 * @return {boolean} True, when Apple Pay data should be submitted to WooCommerce.
	 */
	shouldUpdateButtonWithFormData() {
		if ( CONTEXT.Checkout !== this.context ) {
			return false;
		}
		return (
			this.buttonConfig?.preferences?.checkout_data_mode ===
			'use_applepay'
		);
	}

	/**
	 * Indicates how payment completion should be handled if with the context handler default
	 * actions. Or with Apple Pay module specific completion.
	 *
	 * @return {boolean} True, when the Apple Pay data should be submitted to WooCommerce.
	 */
	shouldCompletePaymentWithContextHandler() {
		// Data already handled, ex: PayNow
		if ( ! this.contextHandler.shippingAllowed() ) {
			return true;
		}

		// Use WC form data mode in Checkout.
		return (
			CONTEXT.Checkout === this.context &&
			! this.shouldUpdateButtonWithFormData()
		);
	}

	/**
	 * Updates Apple Pay paymentRequest with form data.
	 *
	 * @param {Object} paymentRequest Object to extend with form data.
	 */
	updateRequestDataWithForm( paymentRequest ) {
		if ( ! this.shouldUpdateButtonWithFormData() ) {
			return;
		}

		// Add billing address.
		paymentRequest.billingContact = this.fillBillingContact(
			this.formData
		);

		// Add custom data.
		// "applicationData" is originating a "PayPalApplePayError: An internal server error has
		// occurred" on paypal.Applepay().confirmOrder(). paymentRequest.applicationData =
		// this.fillApplicationData(this.formData);

		if ( ! this.shouldRequireShippingInButton() ) {
			return;
		}

		// Add shipping address.
		paymentRequest.shippingContact = this.fillShippingContact(
			this.formData
		);

		// Get shipping methods.
		const rate = this.transactionInfo.chosenShippingMethods[ 0 ];
		paymentRequest.shippingMethods = [];

		// Add selected shipping method.
		for ( const shippingPackage of this.transactionInfo.shippingPackages ) {
			if ( rate === shippingPackage.id ) {
				const shippingMethod = {
					label: shippingPackage.label,
					detail: '',
					amount: shippingPackage.cost_str,
					identifier: shippingPackage.id,
				};

				// Remember this shipping method as the selected one.
				this.selectedShippingMethod = shippingMethod;

				paymentRequest.shippingMethods.push( shippingMethod );
				break;
			}
		}

		// Add other shipping methods.
		for ( const shippingPackage of this.transactionInfo.shippingPackages ) {
			if ( rate !== shippingPackage.id ) {
				paymentRequest.shippingMethods.push( {
					label: shippingPackage.label,
					detail: '',
					amount: shippingPackage.cost_str,
					identifier: shippingPackage.id,
				} );
			}
		}

		// Store for reuse in case this data is not provided by ApplePay on authorization.
		this.initialPaymentRequest = paymentRequest;

		this.log(
			'=== paymentRequest.shippingMethods',
			paymentRequest.shippingMethods
		);
	}

	paymentRequest() {
		const applepayConfig = this.applePayConfig;
		const buttonConfig = this.buttonConfig;
		const baseRequest = {
			countryCode: applepayConfig.countryCode,
			merchantCapabilities: applepayConfig.merchantCapabilities,
			supportedNetworks: applepayConfig.supportedNetworks,
			requiredShippingContactFields: [
				'postalAddress',
				'email',
				'phone',
			],
			requiredBillingContactFields: [ 'postalAddress' ], // ApplePay does not implement billing
			// email and phone fields.
		};

		if ( ! this.shouldRequireShippingInButton() ) {
			if ( this.shouldCompletePaymentWithContextHandler() ) {
				// Data needs handled externally.
				baseRequest.requiredShippingContactFields = [];
			} else {
				// Minimum data required for order creation.
				baseRequest.requiredShippingContactFields = [
					'email',
					'phone',
				];
			}
		}

		const paymentRequest = Object.assign( {}, baseRequest );
		paymentRequest.currencyCode = buttonConfig.shop.currencyCode;
		paymentRequest.total = {
			label: buttonConfig.shop.totalLabel,
			type: 'final',
			amount: this.transactionInfo.totalPrice,
		};

		return paymentRequest;
	}

	refreshContextData() {
		if ( CONTEXT.Product === this.context ) {
			// Refresh product data that makes the price change.
			this.productQuantity = document.querySelector( 'input.qty' )?.value;
			this.products = this.contextHandler.products();
			this.log( 'Products updated', this.products );
		}
	}

	//------------------------
	// Payment process
	//------------------------

	/**
	 * Make ajax call to change the verification-status of the current domain.
	 *
	 * @param {boolean} isValid
	 */
	adminValidation( isValid ) {
		// eslint-disable-next-line no-unused-vars
		const ignored = fetch( this.buttonConfig.ajax_url, {
			method: 'POST',
			headers: {
				'Content-Type': 'application/x-www-form-urlencoded',
			},
			body: new URLSearchParams( {
				action: 'ppcp_validate',
				'woocommerce-process-checkout-nonce': this.nonce,
				validation: isValid,
			} ).toString(),
		} );
	}

	/**
	 * Returns an event handler that Apple Pay calls when displaying the payment sheet.
	 *
	 * @see https://developer.apple.com/documentation/apple_pay_on_the_web/applepaysession/1778021-onvalidatemerchant
	 *
	 * @param {Object} session The ApplePaySession object.
	 *
	 * @return {(function(*): void)|*} Callback that runs after the merchant validation
	 */
	onValidateMerchant( session ) {
		return ( applePayValidateMerchantEvent ) => {
			this.log( 'onvalidatemerchant call' );

			widgetBuilder.paypal
				.Applepay()
				.validateMerchant( {
					validationUrl: applePayValidateMerchantEvent.validationURL,
				} )
				.then( ( validateResult ) => {
					session.completeMerchantValidation(
						validateResult.merchantSession
					);

					this.adminValidation( true );
				} )
				.catch( ( validateError ) => {
					console.error( validateError );
					this.adminValidation( false );
					this.log( 'onvalidatemerchant session abort' );
					session.abort();
				} );
		};
	}

	onShippingMethodSelected( session ) {
		this.log( 'onshippingmethodselected', this.buttonConfig.ajax_url );
		const ajaxUrl = this.buttonConfig.ajax_url;
		return ( event ) => {
			this.log( 'onshippingmethodselected call' );

			const data = this.getShippingMethodData( event );

			jQuery.ajax( {
				url: ajaxUrl,
				method: 'POST',
				data,
				success: (
					applePayShippingMethodUpdate,
					textStatus,
					jqXHR
				) => {
					this.log( 'onshippingmethodselected ok' );
					const response = applePayShippingMethodUpdate.data;
					if ( applePayShippingMethodUpdate.success === false ) {
						response.errors = createAppleErrors( response.errors );
					}
					this.selectedShippingMethod = event.shippingMethod;

					// Sort the response shipping methods, so that the selected shipping method is
					// the first one.
					response.newShippingMethods =
						response.newShippingMethods.sort( ( a, b ) => {
							if (
								a.label === this.selectedShippingMethod.label
							) {
								return -1;
							}
							return 1;
						} );

					if ( applePayShippingMethodUpdate.success === false ) {
						response.errors = createAppleErrors( response.errors );
					}
					session.completeShippingMethodSelection( response );
				},
				error: ( jqXHR, textStatus, errorThrown ) => {
					this.log( 'onshippingmethodselected error', textStatus );
					console.warn( textStatus, errorThrown );
					session.abort();
				},
			} );
		};
	}

	onShippingContactSelected( session ) {
		this.log( 'onshippingcontactselected', this.buttonConfig.ajax_url );

		const ajaxUrl = this.buttonConfig.ajax_url;

		return ( event ) => {
			this.log( 'onshippingcontactselected call' );

			const data = this.getShippingContactData( event );

			jQuery.ajax( {
				url: ajaxUrl,
				method: 'POST',
				data,
				success: (
					applePayShippingContactUpdate,
					textStatus,
					jqXHR
				) => {
					this.log( 'onshippingcontactselected ok' );
					const response = applePayShippingContactUpdate.data;
					this.updatedContactInfo = event.shippingContact;
					if ( applePayShippingContactUpdate.success === false ) {
						response.errors = createAppleErrors( response.errors );
					}
					if ( response.newShippingMethods ) {
						this.selectedShippingMethod =
							response.newShippingMethods[ 0 ];
					}
					session.completeShippingContactSelection( response );
				},
				error: ( jqXHR, textStatus, errorThrown ) => {
					this.log( 'onshippingcontactselected error', textStatus );
					console.warn( textStatus, errorThrown );
					session.abort();
				},
			} );
		};
	}

	getShippingContactData( event ) {
		const productId = this.buttonConfig.product.id;

		this.refreshContextData();

		switch ( this.context ) {
			case CONTEXT.Product:
				return {
					action: 'ppcp_update_shipping_contact',
					product_id: productId,
					products: JSON.stringify( this.products ),
					caller_page: 'productDetail',
					product_quantity: this.productQuantity,
					simplified_contact: event.shippingContact,
					need_shipping: this.shouldRequireShippingInButton(),
					'woocommerce-process-checkout-nonce': this.nonce,
				};

			case CONTEXT.Cart:
			case CONTEXT.Checkout:
			case CONTEXT.BlockCart:
			case CONTEXT.BlockCheckout:
			case CONTEXT.MiniCart:
				return {
					action: 'ppcp_update_shipping_contact',
					simplified_contact: event.shippingContact,
					caller_page: 'cart',
					need_shipping: this.shouldRequireShippingInButton(),
					'woocommerce-process-checkout-nonce': this.nonce,
				};
		}
	}

	getShippingMethodData( event ) {
		const productId = this.buttonConfig.product.id;

		this.refreshContextData();

		switch ( this.context ) {
			case CONTEXT.Product:
				return {
					action: 'ppcp_update_shipping_method',
					shipping_method: event.shippingMethod,
					simplified_contact:
						this.updatedContactInfo ||
						this.initialPaymentRequest.shippingContact ||
						this.initialPaymentRequest.billingContact,
					product_id: productId,
					products: JSON.stringify( this.products ),
					caller_page: 'productDetail',
					product_quantity: this.productQuantity,
					'woocommerce-process-checkout-nonce': this.nonce,
				};

			case CONTEXT.Cart:
			case CONTEXT.Checkout:
			case CONTEXT.BlockCart:
			case CONTEXT.BlockCheckout:
			case CONTEXT.MiniCart:
				return {
					action: 'ppcp_update_shipping_method',
					shipping_method: event.shippingMethod,
					simplified_contact:
						this.updatedContactInfo ||
						this.initialPaymentRequest.shippingContact ||
						this.initialPaymentRequest.billingContact,
					caller_page: 'cart',
					'woocommerce-process-checkout-nonce': this.nonce,
				};
		}
	}

	onPaymentAuthorized( session ) {
		this.log( 'onpaymentauthorized' );
		return async ( event ) => {
			this.log( 'onpaymentauthorized call' );

			const processInWooAndCapture = async ( data ) => {
				return new Promise( ( resolve, reject ) => {
					try {
						const billingContact =
							data.billing_contact ||
							this.initialPaymentRequest.billingContact;
						const shippingContact =
							data.shipping_contact ||
							this.initialPaymentRequest.shippingContact;
						const shippingMethod =
							this.selectedShippingMethod ||
							( this.initialPaymentRequest.shippingMethods ||
								[] )[ 0 ];

						const requestData = {
							action: 'ppcp_create_order',
							caller_page: this.context,
							product_id: this.buttonConfig.product.id ?? null,
							products: JSON.stringify( this.products ),
							product_quantity: this.productQuantity ?? null,
							shipping_contact: shippingContact,
							billing_contact: billingContact,
							token: event.payment.token,
							shipping_method: shippingMethod,
							'woocommerce-process-checkout-nonce': this.nonce,
							funding_source: 'applepay',
							_wp_http_referer: '/?wc-ajax=update_order_review',
							paypal_order_id: data.paypal_order_id,
						};

						this.log(
							'onpaymentauthorized request',
							this.buttonConfig.ajax_url,
							data
						);

						jQuery.ajax( {
							url: this.buttonConfig.ajax_url,
							method: 'POST',
							data: requestData,
							complete: ( jqXHR, textStatus ) => {
								this.log( 'onpaymentauthorized complete' );
							},
							success: (
								authorizationResult,
								textStatus,
								jqXHR
							) => {
								this.log( 'onpaymentauthorized ok' );
								resolve( authorizationResult );
							},
							error: ( jqXHR, textStatus, errorThrown ) => {
								this.log(
									'onpaymentauthorized error',
									textStatus
								);
								reject( new Error( errorThrown ) );
							},
						} );
					} catch ( error ) {
						this.log( 'onpaymentauthorized catch', error );
						console.log( error ); // handle error
					}
				} );
			};

			const id = await this.contextHandler.createOrder();

			this.log(
				'onpaymentauthorized paypal order ID',
				id,
				event.payment.token,
				event.payment.billingContact
			);

			try {
				const confirmOrderResponse = await widgetBuilder.paypal
					.Applepay()
					.confirmOrder( {
						orderId: id,
						token: event.payment.token,
						billingContact: event.payment.billingContact,
					} );

				this.log(
					'onpaymentauthorized confirmOrderResponse',
					confirmOrderResponse
				);

				if (
					confirmOrderResponse &&
					confirmOrderResponse.approveApplePayPayment
				) {
					if (
						confirmOrderResponse.approveApplePayPayment.status ===
						'APPROVED'
					) {
						try {
							if (
								this.shouldCompletePaymentWithContextHandler()
							) {
								// No shipping, expect immediate capture, ex: PayNow, Checkout with
								// form data.

								let approveFailed = false;
								await this.contextHandler.approveOrder(
									{
										orderID: id,
									},
									{
										// actions mock object.
										restart: () =>
											new Promise(
												( resolve, reject ) => {
												approveFailed = true;
												resolve();
												}
											),
										order: {
											get: () =>
												new Promise(
													( resolve, reject ) => {
													resolve( null );
													}
												),
										},
									}
								);

								if ( ! approveFailed ) {
									this.log(
										'onpaymentauthorized approveOrder OK'
									);
									session.completePayment(
										ApplePaySession.STATUS_SUCCESS
									);
								} else {
									this.log(
										'onpaymentauthorized approveOrder FAIL'
									);
									session.completePayment(
										ApplePaySession.STATUS_FAILURE
									);
									session.abort();
									console.error( error );
								}
							} else {
								// Default payment.

								const data = {
									billing_contact:
										event.payment.billingContact,
									shipping_contact:
										event.payment.shippingContact,
									paypal_order_id: id,
								};
								const authorizationResult =
									await processInWooAndCapture( data );
								if (
									authorizationResult.result === 'success'
								) {
									session.completePayment(
										ApplePaySession.STATUS_SUCCESS
									);
									window.location.href =
										authorizationResult.redirect;
								} else {
									session.completePayment(
										ApplePaySession.STATUS_FAILURE
									);
								}
							}
						} catch ( error ) {
							session.completePayment(
								ApplePaySession.STATUS_FAILURE
							);
							session.abort();
							console.error( error );
						}
					} else {
						console.error( 'Error status is not APPROVED' );
						session.completePayment(
							ApplePaySession.STATUS_FAILURE
						);
					}
				} else {
					console.error( 'Invalid confirmOrderResponse' );
					session.completePayment( ApplePaySession.STATUS_FAILURE );
				}
			} catch ( error ) {
				console.error(
					'Error confirming order with applepay token',
					error
				);
				session.completePayment( ApplePaySession.STATUS_FAILURE );
				session.abort();
			}
		};
	}

	fillBillingContact( data ) {
		return {
			givenName: data.billing_first_name ?? '',
			familyName: data.billing_last_name ?? '',
			emailAddress: data.billing_email ?? '',
			phoneNumber: data.billing_phone ?? '',
			addressLines: [ data.billing_address_1, data.billing_address_2 ],
			locality: data.billing_city ?? '',
			postalCode: data.billing_postcode ?? '',
			countryCode: data.billing_country ?? '',
			administrativeArea: data.billing_state ?? '',
		};
	}

	fillShippingContact( data ) {
		if ( data.shipping_first_name === '' ) {
			return this.fillBillingContact( data );
		}
		return {
			givenName:
				data?.shipping_first_name && data.shipping_first_name !== ''
					? data.shipping_first_name
					: data?.billing_first_name,
			familyName:
				data?.shipping_last_name && data.shipping_last_name !== ''
					? data.shipping_last_name
					: data?.billing_last_name,
			emailAddress:
				data?.shipping_email && data.shipping_email !== ''
					? data.shipping_email
					: data?.billing_email,
			phoneNumber:
				data?.shipping_phone && data.shipping_phone !== ''
					? data.shipping_phone
					: data?.billing_phone,
			addressLines: [
				data.shipping_address_1 ?? '',
				data.shipping_address_2 ?? '',
			],
			locality:
				data?.shipping_city && data.shipping_city !== ''
					? data.shipping_city
					: data?.billing_city,
			postalCode:
				data?.shipping_postcode && data.shipping_postcode !== ''
					? data.shipping_postcode
					: data?.billing_postcode,
			countryCode:
				data?.shipping_country && data.shipping_country !== ''
					? data.shipping_country
					: data?.billing_country,
			administrativeArea:
				data?.shipping_state && data.shipping_state !== ''
					? data.shipping_state
					: data?.billing_state,
		};
	}

	fillApplicationData( data ) {
		const jsonString = JSON.stringify( data );
		const utf8Str = encodeURIComponent( jsonString ).replace(
			/%([0-9A-F]{2})/g,
			( match, p1 ) => {
				return String.fromCharCode( '0x' + p1 );
			}
		);

		return btoa( utf8Str );
	}
}

export default ApplePayButton;
