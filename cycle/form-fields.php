<?php
/**
 * Gateway Settings Form Fields.
 */
if (!defined('ABSPATH')) {
    exit;
}

return array(
    'enabled' => array(
        'title' => __('Enable/Disable', 'woocommerce'),
        'type' => 'checkbox',
        'label' => __('Enable gateway', 'woocommerce-cyclegateway'),
        'default' => 'yes',
        'desc_tip' => true,
    ),
    'title' => array(
        'title' => __('Title', 'woocommerce'),
        'type' => 'text',
        'description' => __('This controls the title which the user sees during checkout.', 'woocommerce'),
        'default' => __('Cryptocurrency', 'woocommerce-cyclegateway'),
        'desc_tip' => true,
    ),
    'description' => array(
        'title' => __('Description', 'woocommerce'),
        'type' => 'text',
        'description' => __('This controls the description which the user sees during checkout.', 'woocommerce'),
        'default' => __('Pay with BTC, ETH and more via Cycle Online', 'woocommerce-cyclegateway'),
        'desc_tip' => true,
    ),

    'api_key' => array(
        'title' => __('Api-Key', 'woocommerce-cyclegateway'),
        'type' => 'text',
        'description' => __('Api-Key', 'woocommerce-cyclegateway'),
        'default' => "",
        'desc_tip' => true,
    ),
    'redirect_uri' => array(
        'title' => __('Redirect URL (Optional)', 'woocommerce-cyclegateway'),
        'type' => 'text',
        'description' => __('Redirect URL (Optional). If not specified - order detail page will be substituted', 'woocommerce-cyclegateway'),
        'default' => "",
        'desc_tip' => true,
    ),
);
