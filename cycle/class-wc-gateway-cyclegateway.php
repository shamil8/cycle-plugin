<?php
/*
 * Plugin Name: Cycle Online Payment Gateway
 * Plugin URI:
 * Description: Cycle Online Crypto Payment gateway for WooCommerce.
 * Version: 1.0.1
 * Author: CycleBit
 * License: MIT
 * WC requires at least: 5.0.0
 * WC tested up to: 5.1.0
 */


if (!defined('ABSPATH')) exit; // exit if accessed directly

add_action('plugins_loaded', 'cycle_payment_gateway_init');


global $cyclebit_db_version;
$cyclebit_db_version = '1.1';

/**
 * Creates table in database for collecting the transactions
 *
 * @return void
 */
function cyclebit_db_install() {
    global $wpdb;
    global $cyclebit_db_version;

    $table_name = $wpdb->prefix . 'cyclegateway_transactions';

    $charset_collate = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE $table_name (
          `id` INT NOT NULL AUTO_INCREMENT,
          `order_id` INT NOT NULL,
          `transaction_id` VARCHAR(45) NOT NULL,          
          `createdate` DATETIME NOT NULL,
          `statusdate` DATETIME NULL,
          `status` VARCHAR(15) NOT NULL,
          `pending` TINYINT(1) NULL DEFAULT 1,
        PRIMARY KEY (`id`)) $charset_collate;";

    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql);

    add_option('cyclebit_db_version', $cyclebit_db_version);
}

function cycle_payment_gateway_update_db_check() {
    global $cyclebit_db_version;
    if (get_site_option('cyclebit_db_version') != $cyclebit_db_version) {
        cyclebit_db_install();
    }
}

function cycle_payment_gateway_init()
{
    cycle_payment_gateway_update_db_check();
    if (!class_exists('WC_Payment_Gateway')) {
        return;
    }

    if (class_exists('WC_Cycle_Gateway')) {
        return;
    }

    if (!class_exists('CycleGateway\Client')) {
        require_once(plugin_dir_path(__FILE__) . "CycleGateway/Client.php");
    }

    /**
     * Add the gateway to WooCommerce
     */
    add_filter('woocommerce_payment_gateways', function($methods) {
        $methods[] = 'WC_Cycle_Gateway';
        return $methods;
    });

    /**
     * Gateway class.
     */
    class WC_Cycle_Gateway extends WC_Payment_Gateway 
    {
        /** @var boolean Whether logging is enabled */
        public static $log_enabled = true;

        private $gatewayClient;

        /**
        * Throw error on object clone
        *
        * @access public
        * @return void
        */
        public function __clone() {
            trigger_error('Cloning instances of the class is forbidden.');
        }

        /**
        * Disable unserializing of the class
        *
        * @access public
        * @return void
        */
        public function __wakeup() {
            trigger_error('Unserializing instances of the class is forbidden.');
        }

        public function __construct() {
            $this->order_id = 0;
            $this->id = 'cyclegateway';
            $this->method_title = 'Cycle Online';
            $this->method_description = $this->method_title . ' Payment Gateway';

            // Load the settings
            $this->init_form_fields();
            $this->init_settings();

            $this->icon = apply_filters('woocommerce_'. $this->id .'_gateway_icon', plugins_url('assets/images/logo.png', __FILE__));
            $this->supports = array('products');

            foreach ($this->settings as $setting_key => $value) {
                $this->$setting_key = $value;
            }

            $this->gatewayClient = new CycleGateway\Client($this->api_key ?? '');

            // TODO:: NEED TO DISABLE DEBUG `$this->debug === 'yes'`
            self::$log_enabled = true;
            // Save settings
            if (is_admin()) {
                // Save administration options.
                add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
            }

            add_action('woocommerce_receipt_' . $this->id, array( $this, 'receipt_page' ) );
            add_action('woocommerce_api_wc_cycle_gateway', array( $this, 'check_response' ) ); // Payment listener/API hook

            if (is_admin()) {
                add_action( 'woocommerce_admin_order_data_after_billing_address', array( $this, 'after_order_details' ), 10, 3 );
            }

            // Check for SSL
            if ($this->gatewayClient->getUserIp() != '127.0.0.1') {
                add_action('admin_notices', array($this, 'do_ssl_check'));
            }
        }

        /**
         * Logging method
         * @param string $message
         */
        public static function log(string $message) {
            if (self::$log_enabled) {
                $logger = wc_get_logger();
                $logger->info( $message, array( 'source' => 'cyclegateway' ) );
            }
        }      

        public function after_order_details($order)
         {
            var_dump('after_order_detailssss', $order); // TODO:: REMOVE IT!
            $order_id = $order->get_order_number();
            $transactions = $this->getTransactions($order_id, 0);

            if($transactions ){
                $text = '<p></p><strong>Transactions (Cycle Online):</strong><table>';
                foreach ($transactions as  $transaction) {
                    $text .= '<tr>';
                    $text .= '<td style="border-bottom:1px solid #ccc">'. $transaction->transaction_id.'</td>';
                    $text .= '<td style="border-bottom:1px solid #ccc">'. $transaction->status.'</td>';
                    $text .= '</tr>';
                 }  
                 $text .= '</table>';  

                 echo wp_kses_post($text); 
             } 
         }

        /**
         * Initialise Gateway Settings Form Fields
         *
         * @access public
         * @return void
         */
        public function init_form_fields() {
            $this->form_fields = include('form-fields.php');
        }

        /**
         * Add notice in admin section if gateway is used without SSL certificate
         */
        public function do_ssl_check() {
            if ($this->enabled == 'yes' && get_option('woocommerce_force_ssl_checkout') == 'no') {
                echo wp_kses_post("<div class=\"error\"><p>". sprintf( __( "<strong>%s</strong> is enabled and WooCommerce is not forcing the SSL certificate on your checkout page. Please ensure that you have a valid SSL certificate and that you are <a href=\"%s\">forcing the checkout pages to be secured.</a>" ), $this->method_title, admin_url( 'admin.php?page=wc-settings&tab=checkout' ) ) ."</p></div>");
            }
        }

        /*** Process fields values of this plugin admin settings */
        public function process_admin_options() {
            parent::process_admin_options();
        }

        /*** Customize Thank you text. */
//         public function change_order_received_text($text): string
//         {
//             $newText = $text;
//             $newText .= '<div style="display: flex;flex-wrap: wrap;">';
//             foreach ($this->order_payments_statuses as $status) {
//                 $newText .= '<div style="width: 33%;">' . $status['transaction_id'].'</div>';
//                 $newText .= '<div style="width: 33%;">' . $status['status'].'</div>';
//                 $newText .= '<div style="width: 33%;">' . $status['status']['message']['string'].'</div>';
//             }
//             $newText .= '</div>';
//             //$newText .= '<p>' . sprintf(__('. <br />Your payment is beeing processed via %s Payment Gateway.', 'woocommerce-cyclegateway'), $this->method_title) . '</p>';
//             return $newText;
//         }

        /**
         * Handling payment and processing the order.
         *
         * @param $order_id /id of the order
         * @return array
         */
        public function process_payment($order_id): array
        {
            self::log("--- Shop order " . $order_id);

            $order = new WC_Order($order_id);

            return [
                'result' => 'success',
                'redirect' => $order->get_checkout_payment_url(true)
            ];
        }

        /**
         * Creates order on Gateway. Sets Shop's order status. Shows additional HTML or redirects to Gateway.
         * /checkout/order-pay/SSS/?key=wc_order_ZZZ
         * 
         * @param $order_id
         */
        function receipt_page($order_id) {
            self::log("receipt_page : " . $order_id);
            $order = new WC_Order($order_id);

            $jsonData = $this->createJsonForGateway($order);

            $output = $this->gatewayClient->createPayment($jsonData);
            $outputCode = (int)$output['code'];
            $outputBody = json_decode($output['body'], true);

            self::log($order_id . ': Output code: ' . $outputCode);
            echo '<pre>' . var_dump('receipt_pagee', $output) . '</pre>';  // TODO:: REMOVE IT!

            if($outputCode != 201) {
                self::log("Failed to connect to server, code:" . $outputCode);

                if ($outputBody && isset($outputBody['message'])) {
                    wc_add_notice(__($outputBody['message'], 'woocommerce-cyclegateway'), 'error');
                } else {
                    wc_add_notice(__('Failed to connect to the server. Please try again later.', 'woocommerce-cyclegateway'), 'error');
                }

                exit;
            }

            $this->addTransaction($order_id, $outputBody['result']['id'], $jsonData);

            $wooOrderStatus = 'pending';
            $messageEntity = $this->setWooNotice($wooOrderStatus);

            $order->update_status($wooOrderStatus, $messageEntity['string']);

            $gatewayProcessPageUrl = $outputBody['result']['successUrl'];

            if (!$gatewayProcessPageUrl) {
                self::log("receipt_page, BAD Location : " . $order_id);
                wc_add_notice( __('Location value not defined. ', 'woocommerce-cyclegateway') . __('Order ID: ', 'woocommerce-cyclegateway') . $order_id, 'error' );
                $gatewayProcessPageUrl = $this->get_return_url($order);
            }

            $gatewayProcessPageUrl = esc_url($gatewayProcessPageUrl);

            $order->update_status('pending');
            $order->add_order_note( __('Transaction created - '  . $outputBody['result']['id'] .'. Waiting for payment.', 'woocommerce-cyclegateway') );

            wp_redirect($gatewayProcessPageUrl);
            exit;
        }

        /**
         * Callbakc from gateway
         *  
	        {
	            "TransactionId": "e3cc51a0-4542-4433-af5e-28f2dea99e7e",
	            "status": "completed",
	            "Success": true
	        }

        CREATED — payment was created, the payer has not yet selected the currency for payment;
        IN_PROGRESS — payer chose the currency to pay the invoice, but incoming transaction in the blockchain has not yet been detected;
        COMPLETED — payment is successfully completed, you can release good(s)/service(s) to the payer, the receipt has been sent to the payer's email address specified in the KYC form;
        OVERPAID — payment more than enough.
        UNDERPAID — payment less than enough.
        HIGH_RISK — payment less than enough.
        EXPIRED — payment failed for some reason (verification error, funds were not sent by the payer, etc.).
        */
        public function check_response() {
            $data = json_decode(file_get_contents('php://input'), true);

            if (!isset($data['order_id'])) {
                return true;
            }

            $gateway_response = $this->gatewayClient->getPaymentStatus($data['order_id']);
            $transaction = json_decode($gateway_response['body'], true);

            if (!$transaction['ok'] || is_null($transaction['result']['customId'])) {
                return true;
            }

            $order_id = $transaction['result']['customId'];
            $status = $transaction['result']['status'];
            $order = new WC_Order($order_id);

            switch ($status) {
                case 'EXPIRED':
                case 'UNDERPAID':
                case 'HIGH_RISK':
                    $this->updateTransaction($data['order_id'], $status, 0);
                    if($order) $order->add_order_note( __('Notification was received from the payment gateway.<br>Transaction - '  . $data['order_id'] .'.<br>Status: '. $status, 'woocommerce-cyclegateway') );
                    break;
                case 'COMPLETED':
                case 'OVERPAID':
                    $this->updateTransaction($data['order_id'], $status, 0);
                    if($order){
                        $order->add_order_note( __('Notification was received from the payment gateway.<br>Orderpaid, transaction - '  . $data['order_id'] .'.', 'woocommerce-cyclegateway') );
                        $order->payment_complete($data['order_id']);
                    }

                    $this->stopCheck($order_id);
                    break;
                case 'IN_PROGRESS':
                case 'CREATED':
                default:
                    if($order) $order->add_order_note( __('Notification was received from the payment gateway.<br>Transaction - '  . $data['order_id'] .'.<br>Status: '. $status, 'woocommerce-cyclegateway') );
                    $this->updateTransaction($data['order_id'], $status, 1);
                    break;
            }

            return true;
        }

        private function getTransactions($order_id, $pendingOnly = 1)
        {
            global $wpdb;
            $table = $wpdb->prefix.'cyclegateway_transactions';
            $sql = "
                SELECT transaction_id, status, pending  
                FROM $table
                WHERE order_id = $order_id 
                ";
            if($pendingOnly){
                $sql .= " AND pending = 1";
            }
                
            return $wpdb->get_results($sql);
        }

        private function addTransaction($order_id, $transaction_id, $data = '')
        {
            global $wpdb;
            $table = $wpdb->prefix.'cyclegateway_transactions';
            $data = array('order_id' => $order_id, 'transaction_id' => $transaction_id, 'pending' => 1, 'createdate' => current_time('mysql', 1), 'statusdate' => current_time('mysql', 1), 'status' => 'created');
            
            $wpdb->insert($table,$data);
        }

        private function updateTransaction($transaction_id, $status, $pending = 1)
        {
            global $wpdb;
            $table = $wpdb->prefix.'cyclegateway_transactions';
            $data = array('pending' => $pending, 'statusdate' => current_time('mysql', 1), 'status' => $status);
            $where = array('transaction_id' => $transaction_id);
            $wpdb->update($table,$data, $where);
        }

        private function stopCheck($order_id)
        {
            global $wpdb;
            $table = $wpdb->prefix.'cyclegateway_transactions';
            $data = array('pending' => 0);
            $where = array('order_id' => $order_id);
            $wpdb->update($table,$data, $where);
        }

        /*** Prepare gateway order object for request to Gateway. */
        private function createJsonForGateway($order) {
            $orderId = $order->get_order_number();
            $order_data = $order->get_data();
            $orderArray = [
                'custom_id' => $orderId,
                'amount' => $order->get_total(),
                'currency' => $order->get_currency(),
                'callback_url' => add_query_arg('wc-api', 'WC_Cycle_Gateway', home_url( '/' )),
                'description' => "WooCommerce order #" . $orderId . " from " . get_bloginfo('name'),
                'email' => $order_data['billing']['email'],
            ];

            if(!$this->redirect_uri) {
                // Go here from Gateway after payment. Originally "Thank you. Your order has been received." page
                $returnFromGatewayPageUrl = $this->get_return_url($order);

                $returnFromGatewayPageUrl = add_query_arg('shop_order_id', $orderId, $returnFromGatewayPageUrl);

                $orderArray['redirect_url'] = esc_url($returnFromGatewayPageUrl);
            } else {
                $orderArray['redirect_url'] = $this->redirect_uri;
            }

            return json_encode($orderArray);
        }

        /** 
         * Set Woo notice according to Woo Order status. Visual representation, sets color of message.
         *
         * null — transaction id not found;
         * created — payment was created, the payer has not yet selected the currency for payment;
         * pending — payer chose the currency to pay the invoice, but incoming transaction in the blockchain has not yet been detected;
         * completed — payment is successfully completed, you can release good(s)/service(s) to the payer, the receipt has been sent to the payer's email address specified in the KYC form;
         * failed — payment failed for some reason (verification error, funds were not sent by the payer, etc.).
         *
         * @access private
         */
        private function setWooNotice($wooOrderStatus = 'failed', $gatewayOrderStatus = ''): array
        {
            $messageEntities = [
                'null' => [
                    'type' => 'error',
                    'string' => __('Transaction ID not found', 'woocommerce-cyclegateway')
                ],
                'created' => [
                    'type' => 'notice',
                    'string' => __('Payment was created', 'woocommerce-cyclegateway')
                ],
                'pending' => [
                    'type' => 'notice',
                    'string' => __('Payer chose the currency to pay the invoice, but incoming transaction in the blockchain has not yet been detected', 'woocommerce-cyclegateway') 
                ],
                'completed' => [
                    'type' => 'success',
                    'string' => sprintf(__('Payment is successfully completed via %s.', 'woocommerce-cyclegateway'), $this->method_title)
                ], 
                'failed' => [
                    'type' => 'error',
                    'string' => ucfirst($gatewayOrderStatus) . '.'
                ]
            ];

            if (!isset($messageEntities[$wooOrderStatus])) {
                return [
                    'type' => 'notice',
                    'string' => ucfirst($gatewayOrderStatus) . '.'
                ];
            }

            return $messageEntities[$wooOrderStatus];
        }
    }
}
