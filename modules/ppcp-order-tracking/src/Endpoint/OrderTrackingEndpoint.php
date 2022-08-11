<?php
/**
 * The order tracking Endpoint.
 *
 * @package WooCommerce\PayPalCommerce\OrderTracking\Endpoint
 */

declare( strict_types=1 );

namespace WooCommerce\PayPalCommerce\OrderTracking\Endpoint;

use Exception;
use Psr\Log\LoggerInterface;
use WC_Order;
use WooCommerce\PayPalCommerce\ApiClient\Authentication\Bearer;
use WooCommerce\PayPalCommerce\ApiClient\Endpoint\RequestTrait;
use WooCommerce\PayPalCommerce\ApiClient\Entity\SellerStatus;
use WooCommerce\PayPalCommerce\ApiClient\Exception\PayPalApiException;
use WooCommerce\PayPalCommerce\ApiClient\Exception\RuntimeException;
use WooCommerce\PayPalCommerce\ApiClient\Factory\SellerStatusFactory;
use WooCommerce\PayPalCommerce\Button\Endpoint\RequestData;
use WooCommerce\PayPalCommerce\WcGateway\Processor\TransactionIdHandlingTrait;

/**
 * @psalm-type SupportedStatuses = 'SHIPPED'|'ON_HOLD'|'DELIVERED'|'CANCELLED'
 * @psalm-type TrackingInfo = array{transaction_id: string, status: SupportedStatuses, tracking_number?: string, carrier?: string}
 * @psalm-type RequestValues = array{transaction_id: string, status: SupportedStatuses, order_id: int, action: 'create'|'update', tracking_number?: string, carrier?: string}
 * Class OrderTrackingEndpoint
 */
class OrderTrackingEndpoint {

	use RequestTrait, TransactionIdHandlingTrait;

	const ENDPOINT = 'ppc-tracking-info';

	/**
	 * The RequestData.
	 *
	 * @var RequestData
	 */
	protected $request_data;

	/**
	 * The Host URL.
	 *
	 * @var string
	 */
	private $host;

	/**
	 * The bearer.
	 *
	 * @var Bearer
	 */
	private $bearer;

	/**
	 * The logger.
	 *
	 * @var LoggerInterface
	 */
	private $logger;

	/**
	 * The seller status factory.
	 *
	 * @var SellerStatusFactory
	 */
	private $seller_status_factory;

	/**
	 * The partner ID.
	 *
	 * @var string
	 */
	private $partner_id;

	/**
	 * The merchant ID.
	 *
	 * @var string
	 */
	private $merchant_id;

	/**
	 * PartnersEndpoint constructor.
	 *
	 * @param string          $host The host.
	 * @param Bearer          $bearer The bearer.
	 * @param LoggerInterface $logger The logger.
	 * @param RequestData     $request_data The Request data.
	 */
	public function __construct(
		string $host,
		Bearer $bearer,
		LoggerInterface $logger,
		RequestData $request_data
	) {
		$this->host         = $host;
		$this->bearer       = $bearer;
		$this->logger       = $logger;
		$this->request_data = $request_data;
	}

	/**
	 * Handles the request.
	 */
	public function handle_request() {
		try {
			$data         = $this->request_data->read_request( $this->nonce() );
			$action       = $data['action'];
			$request_body = $this->extract_tracking_information( $data );
			$order_id     = (int) $data['order_id'];
			$action === 'create' ? $this->add_tracking_information( $request_body, $order_id ) : $this->update_tracking_information( $data, $order_id );

			$action_message = $action === 'create' ? 'created' : 'updated';
			$message        = sprintf(
			// translators: %1$s is the action message (created or updated).
				_x( 'successfully %1$s', 'tracking inof success message', 'woocommerce-paypal-payments' ),
				esc_html( $action_message )
			);

			wp_send_json_success( array( 'message' => $message ) );
		} catch ( Exception $error ) {
			wp_send_json_error( $error->getMessage(), 500 );
		}
	}

	/**
	 * Creates the tracking information of a given order with the given data.
	 *
	 * @param array $data The tracking information to add.
	 * @psalm-param TrackingInfo $data
	 * @param int   $order_id The order ID.
	 * @throws RuntimeException If problem creating.
	 */
	public function add_tracking_information( array $data, int $order_id ) : void {
		$url = trailingslashit( $this->host ) . 'v1/shipping/trackers-batch';

		$body = array(
			'trackers' => array( $data ),
		);

		$args = array(
			'method'  => 'POST',
			'headers' => $this->request_headers(),
			'body'    => wp_json_encode( $body ),
		);

		$response = $this->request( $url, $args );

		if ( is_wp_error( $response ) ) {
			$error = new RuntimeException(
				'Could not create order tracking information.'
			);
			$this->logger->log(
				'warning',
				$error->getMessage(),
				array(
					'args'     => $args,
					'response' => $response,
				)
			);
			throw $error;
		}

		$json        = json_decode( $response['body'] );
		$status_code = (int) wp_remote_retrieve_response_code( $response );
		if ( 200 !== $status_code ) {
			$error = new PayPalApiException(
				$json,
				$status_code
			);
			$this->logger->log(
				'warning',
				sprintf(
					'Failed to create order tracking information. PayPal API response: %1$s',
					$error->getMessage()
				),
				array(
					'args'     => $args,
					'response' => $response,
				)
			);
			throw $error;
		}

		update_post_meta( $order_id, '_ppcp_paypal_tracking_number', $data['tracking_number'] );
	}

	/**
	 * Gets the tracking information of a given order.
	 *
	 * @param int $wc_order_id The order ID.
	 * @return array|null The tracking information.
	 * @psalm-return TrackingInfo|null
	 */
	public function get_tracking_information( int $wc_order_id ) : ?array {
		$wc_order = wc_get_order( $wc_order_id );
		if ( ! is_a( $wc_order, WC_Order::class ) ) {
			throw new RuntimeException( 'wrong order ID' );
		}

		$transaction_id  = $wc_order->get_transaction_id();
		$tracking_number = get_post_meta( $wc_order_id, '_ppcp_paypal_tracking_number', true );
		$url             = trailingslashit( $this->host ) . 'v1/shipping/trackers/' . $this->find_tracker_id( $transaction_id, $tracking_number );

		$args = array(
			'method'  => 'GET',
			'headers' => $this->request_headers(),
		);

		$response = $this->request( $url, $args );

		if ( is_wp_error( $response ) ) {
			$error = new RuntimeException(
				'Could not fetch the tracking information.'
			);
			$this->logger->log(
				'warning',
				$error->getMessage(),
				array(
					'args'     => $args,
					'response' => $response,
				)
			);
			throw $error;
		}

		$data        = json_decode( $response['body'] );
		$status_code = (int) wp_remote_retrieve_response_code( $response );

		if ( 200 !== $status_code ) {
			return null;
		}

		return array(
			'transaction_id'  => $data->transaction_id ?? '',
			'status'          => $data->status ?? '',
			'tracking_number' => $data->tracking_number ?? '',
			'carrier'         => $data->carrier ?? '',
		);
	}

	/**
	 * Updates the tracking information of a given order with the given data.
	 *
	 * @param array $data The tracking information to update.
	 * @psalm-param TrackingInfo $data
	 * @param int   $order_id The order ID.
	 * @throws RuntimeException If problem updating.
	 */
	public function update_tracking_information( array $data, int $order_id ) : void {
		$tracking_info   = $this->get_tracking_information( $order_id );
		$transaction_id  = $tracking_info['transaction_id'] ?? '';
		$tracking_number = $tracking_info['tracking_number'] ?? '';
		$url             = trailingslashit( $this->host ) . 'v1/shipping/trackers/' . $this->find_tracker_id( $transaction_id, $tracking_number );

		$args = array(
			'method'  => 'PUT',
			'headers' => $this->request_headers(),
			'body'    => wp_json_encode( $data ),
		);

		$response = $this->request( $url, $args );

		if ( is_wp_error( $response ) ) {
			$error = new RuntimeException(
				'Could not update order tracking information.'
			);
			$this->logger->log(
				'warning',
				$error->getMessage(),
				array(
					'args'     => $args,
					'response' => $response,
				)
			);
			throw $error;
		}

		$json        = json_decode( $response['body'] );
		$status_code = (int) wp_remote_retrieve_response_code( $response );
		if ( 204 !== $status_code ) {
			$error = new PayPalApiException(
				$json,
				$status_code
			);
			$this->logger->log(
				'warning',
				sprintf(
					'Failed to update the order tracking information. PayPal API response: %1$s',
					$error->getMessage()
				),
				array(
					'args'     => $args,
					'response' => $response,
				)
			);
			throw $error;
		}

		update_post_meta( $order_id, '_ppcp_paypal_tracking_number', $data['tracking_number'] );
	}

	/**
	 * The nonce.
	 *
	 * @return string
	 */
	public static function nonce(): string {
		return self::ENDPOINT;
	}

	/**
	 * Extracts the needed tracking information from given request data.
	 *
	 * @param array $data The request data map.
	 * @psalm-param RequestValues $data
	 * @return array A map of tracking information keys to values.
	 * @psalm-return TrackingInfo
	 */
	protected function extract_tracking_information( array $data ): array {
		return array(
			'transaction_id'  => $data['transaction_id'] ?? '',
			'tracking_number' => $data['tracking_number'] ?? '',
			'status'          => $data['status'] ?? '',
			'carrier'         => $data['carrier'] ?? '',
		);
	}

	/**
	 * Creates the request headers.
	 *
	 * @return array The request headers.
	 */
	protected function request_headers(): array {
		return array(
			'Authorization' => 'Bearer ' . $this->bearer->bearer()->token(),
			'Content-Type'  => 'application/json',
		);
	}

	/**
	 * Finds the tracker ID from given transaction ID and tracking number.
	 *
	 * @param string $transaction_id The transaction ID.
	 * @param string $tracking_number The tracking number.
	 * @return string The tracker ID.
	 */
	protected function find_tracker_id( string $transaction_id, string $tracking_number ): string {
		return ! empty( $tracking_number ) ? "{$transaction_id}-{$tracking_number}" : "{$transaction_id}-NOTRACKER";
	}
}
