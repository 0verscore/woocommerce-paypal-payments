<?php
declare(strict_types=1);

namespace Inpsyde\PayPalCommerce\Button\Assets;

use Inpsyde\PayPalCommerce\Button\Endpoint\ChangeCartEndpoint;
use Inpsyde\PayPalCommerce\Button\Endpoint\CreateOrderEndpoint;

class SmartButton
{

    private $moduleUrl;
    private $isSandbox;
    public function __construct(
        string $moduleUrl,
        bool $isSandbox
    ) {

        $this->moduleUrl = $moduleUrl;
        $this->isSandbox = $isSandbox;
    }

    public function renderWrapper() : bool
    {
        $renderer = function () {
            echo '<div id="ppc-button"></div>';
        };
        if (is_cart()) {
            add_action(
                'woocommerce_after_cart_totals',
                $renderer,
                20
            );
        }
        if (is_product()) {
            add_action(
                'woocommerce_single_product_summary',
                $renderer,
                31
            );
        }
        if (is_checkout()) {
            add_action(
                'wp_footer',
                $renderer,
                31
            );
        }

        add_action(
            'woocommerce_widget_shopping_cart_buttons',
            function () {
                echo '<span id="ppc-button-minicart"></span>';
            },
            30
        );
        return true;
    }

    public function enqueue() : bool
    {
        wp_enqueue_script(
            'paypal-smart-button',
            $this->moduleUrl . '/assets/js/button.js'
        );

        $params = [
            'client-id' => 'AcVzowpNCpTxFzLG7onQI4JD0sVcA0BkZv-D42qRZPv_gZ8cNfX9zGL_8bXmSu7cbJ5B2DH7sot8vDpw',
            'currency' => get_woocommerce_currency(),
        ];
        $smartButtonUrl = add_query_arg($params, 'https://www.paypal.com/sdk/js');

        $localize = [
            'redirect' => wc_get_checkout_url(),
            'context' => $this->context(),
            'ajax' => [
                'change_cart' => [
                    'endpoint' => home_url(\WC_AJAX::get_endpoint(ChangeCartEndpoint::ENDPOINT)),
                    'nonce' => wp_create_nonce(ChangeCartEndpoint::nonce()),
                ],
                'create_order' => [
                    'endpoint' => home_url(\WC_AJAX::get_endpoint(CreateOrderEndpoint::ENDPOINT)),
                    'nonce' => wp_create_nonce(CreateOrderEndpoint::nonce()),
                ],
            ],
            'button' => [
                'wrapper' => '#ppc-button',
                'mini_cart_wrapper' => '#ppc-button-minicart',
                'url' =>$smartButtonUrl,
            ],
        ];
        wp_localize_script(
            'paypal-smart-button',
            'PayPalCommerceGateway',
            $localize
        );
        return true;
    }

    private function context() : string
    {
        $context = 'mini-cart';
        if (is_product()) {
            $context = 'product';
        }
        if (is_cart()) {
            $context = 'cart';
        }
        if (is_checkout()) {
            $context = 'checkout';
        }
        return $context;
    }
}