<?php
declare(strict_types=1);

namespace Inpsyde\PayPalCommerce\ApiClient\Endpoint;

use Inpsyde\PayPalCommerce\ApiClient\Authentication\Bearer;
use Inpsyde\PayPalCommerce\ApiClient\Entity\Order;
use Inpsyde\PayPalCommerce\ApiClient\Entity\PurchaseUnit;
use Inpsyde\PayPalCommerce\ApiClient\Exception\RuntimeException;
use Inpsyde\PayPalCommerce\ApiClient\Factory\OrderFactory;
use Inpsyde\PayPalCommerce\ApiClient\Factory\PatchCollectionFactory;
use Inpsyde\PayPalCommerce\Session\SessionHandler;

class OrderEndpoint
{

    private $host;
    private $bearer;
    private $sessionHandler;
    private $orderFactory;
    private $patchCollectionFactory;
    public function __construct(
        string $host,
        Bearer $bearer,
        SessionHandler $sessionHandler,
        OrderFactory $orderFactory,
        PatchCollectionFactory $patchCollectionFactory
    ) {

        $this->host = $host;
        $this->bearer = $bearer;
        $this->sessionHandler = $sessionHandler;
        $this->orderFactory = $orderFactory;
        $this->patchCollectionFactory = $patchCollectionFactory;
    }

    public function createForPurchaseUnits(PurchaseUnit ...$items) : Order
    {
        $bearer = $this->bearer->bearer();

        $data = [
            'intent' => 'CAPTURE',
            'purchase_units' => array_map(
                function (PurchaseUnit $item) : array {
                    return $item->toArray();
                },
                $items
            ),
        ];
        $url = trailingslashit($this->host) . 'v2/checkout/orders';
        $args = [
            'headers' => [
                'Authorization' => 'Bearer ' . $bearer,
                'Content-Type' => 'application/json',
                'Prefer' => 'return=representation',
            ],
            'body' => json_encode($data),
        ];
        $response = wp_remote_post($url, $args);
        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 201) {
            throw new RuntimeException(__('Could not create order.', 'woocommerce-paypal-commerce-gateway'));
        }
        $json = json_decode($response['body']);
        $order = $this->orderFactory->fromPayPalResponse($json);
        $this->sessionHandler->replaceOrder($order);
        return $order;
    }

    public function capture(Order $order) : Order
    {
        $bearer = $this->bearer->bearer();
        $url = trailingslashit($this->host) . 'v2/checkout/orders/' . $order->id() . '/capture';
        $args = [
            'headers' => [
                'Authorization' => 'Bearer ' . $bearer,
                'Content-Type' => 'application/json',
                'Prefer' => 'return=representation',
            ],
        ];
        $response = wp_remote_post($url, $args);
        /**
         * ToDo: If order has already been captured. Do something about it. This could happen
         * if you click the button twice.
         *
         * HTTP Code: 422
         * body: {"details":[{"issue":"ORDER_ALREADY_CAPTURED"}]}
         **/
        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 201) {
            throw new RuntimeException(__('Could not capture order.', 'woocommerce-paypal-commerce-gateway'));
        }
        $json = json_decode($response['body']);
        $order = $this->orderFactory->fromPayPalResponse($json);
        $this->sessionHandler->replaceOrder($order);
        return $order;
    }

    public function order(string $id) : Order
    {
        $bearer = $this->bearer->bearer();
        $url = trailingslashit($this->host) . 'v2/checkout/orders/' . $id;
        $args = [
            'headers' => [
                'Authorization' => 'Bearer ' . $bearer,
                'Content-Type' => 'application/json',
            ],
        ];
        $response = wp_remote_get($url, $args);
        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
            throw new RuntimeException(__('Could not retrieve order.', 'woocommerce-paypal-commerce-gateway'));
        }
        $json = json_decode($response['body']);
        return $this->orderFactory->fromPayPalResponse($json);
    }

    public function patchOrderWith(Order $orderToUpdate, Order $orderToCompare) : Order
    {
        $patches = $this->patchCollectionFactory->fromOrders($orderToCompare, $orderToCompare);
        if (! count($patches->patches())) {
            return $orderToUpdate;
        }

        $bearer = $this->bearer->bearer();
        $url = trailingslashit($this->host) . 'v2/checkout/orders/' . $orderToUpdate->id();
        $args = [
            'method' => 'PATCH',
            'headers' => [
                'Authorization' => 'Bearer ' . $bearer,
                'Content-Type' => 'application/json',
                'Prefer' => 'return=representation',
            ],
            'body' => json_encode($patches->toArray()),
        ];
        $response = wp_remote_post($url, $args);
        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 204) {
            throw new RuntimeException(__('Could not patch order.', 'woocommerce-paypal-commerce-gateway'));
        }

        $newOrder = $this->order($orderToUpdate->id());
        $this->sessionHandler->replaceOrder($newOrder);
        return $newOrder;
    }
}