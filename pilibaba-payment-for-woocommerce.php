<?php
/**
 * @wordpress-plugin
 * Plugin Name:       Pilibaba Payment For WooCommerce
 * Plugin URI:        http://api.pilibaba.com/product/pilibaba-payment-for-woocommerce-plugin/
 * Description:       This plugin helps you to enable Pilibaba Payment checkout for Chinese customers. Pilibaba Payment is a convenient tool of CNY payment, solves the complicated cumbersome process of customs inspection and the problem of goods detain, and provides one-stop transport logistics services to saving shipping cost and time for the shippers.
 * Version:           1.0.14
 * Author:            Pilibaba
 * Author URI:        https://en.pilibaba.com/
 * License:           GNU General Public License v3.0
 * License URI:       https://www.gnu.org/licenses/gpl-3.0.html
 *
 *************
 * Attribution
 *************
 * Pilibaba Payment for WooCommerce is a derivative work of the code from WooThemes / SkyVerge,
 * which is licensed with GPLv3.  This code is also licensed under the terms
 * of the GNU Public License, version 3.
 */

//Security Note: Consider blocking direct access to your plugin PHP files by adding the following line
// at the top of each of them, or be sure to refrain from executing sensitive standalone PHP code before
// calling any WordPress functions.
defined('ABSPATH') or die('No script kiddies please!');

// avoid duplicated includes
if (!class_exists('Pilipay_WC_Module', false)):

// define consts
    define('PILIPAY_WC', 'pilipay_wc'); // the plugin's short name, used for localizations...
    define('PILIPAY_WC_PREFIX', 'pilipay_wc_');
    define('PILIPAY_WC_PREFIX_LEN', strlen(PILIPAY_WC_PREFIX));
    define('PILIPAY_WC_TRACKING_NUMBER', '_pilipay_wc_tracking_number');
    define('PILIPAY_WC_TEST_ORDER_TAG', '_pilipay_wc_test_order_tag_');
    define('PILIPAY_WC_PLUGIN_URL', plugin_dir_url(__FILE__));
    define('PILIPAY_WC_PLUGIN_BASENAME', plugin_basename(__FILE__));
    define('PILIPAY_WC_DEBUG_ENABLE', WP_DEBUG or $_COOKIE['pilipay_wc_debug'] == 'enable');
    define('PILIPAY_WC_SETTINGS_URL', admin_url('admin.php?page=wc-settings&tab=checkout&section=' . strtolower('WC_Gateway_Pilipay_Gateway')));
    defined('PILIPAY_STANDARD') or define('PILIPAY_STANDARD', 'pilipay_standard');

    // define log path
    function pilipay_wc_seek_for_a_log_path(){
        $wpUploadDir = wp_upload_dir();
        $dir = $wpUploadDir['basedir'];

        if (is_dir($dir) and is_writable($dir)){
            return $dir . '/pilipay-for-woocommerece.log';
        }

        $dir = '/var/log';
        if (is_dir($dir) and is_writable($dir)){
            return $dir . '/pilipay-for-woocommerece.log';
        }

        $dir = get_temp_dir();
        if (is_dir($dir) and is_writable($dir)){
            return $dir . '/pilipay-for-woocommerece.log';
        }

        return '/tmp/pilipay-for-woocommerece.log';
    }

    define('PILIPAY_WC_LOG_PATH', pilipay_wc_seek_for_a_log_path());

    final class Pilipay_WC_Module
    {
        private function __construct()
        {
            self::$_instance = $this;


            // register hooks
            register_activation_hook(__FILE__, array($this, 'on_activation'));
            register_deactivation_hook(__FILE__, array($this, 'on_deactivation'));

            add_action('plugins_loaded', array($this, 'on_plugins_loaded'));
            add_filter('woocommerce_payment_gateways', array($this, 'add_payment_gateways'));
            add_action('add_meta_boxes_shop_order', array($this, 'add_meta_boxes_shop_order'), 30);

            add_action('woocommerce_proceed_to_checkout', array($this, 'proceed_to_checkout'), 21);

            // deal requests
            add_action('wp_ajax_' . 'pilipay_wc_update_tracking_number', array($this, 'deal_update_tracking_number'));
            add_action('wp_ajax_nopriv_' . 'pilipay_wc_payment_result', array($this, 'deal_payment_result'));
            add_filter('plugin_action_links_' . PILIPAY_WC_PLUGIN_BASENAME, array($this, 'plugin_action_links' ) );

            if (isset($_REQUEST['action']) && strncmp($_REQUEST['action'], PILIPAY_WC_PREFIX, PILIPAY_WC_PREFIX_LEN) === 0){
                add_action('init', array($this, 'deal_action'));
            }
        }

        public function on_activation()
        {
            self::log('debug', sprintf('enter %s -- with %s', __METHOD__, print_r(func_get_args(), true)));

            // If WooCommerce is not enabled, deactivate plugin.
            if (!$this->is_woocommerce_active()) {
                deactivate_plugins(plugin_basename(__FILE__));
            }
        }

        public function on_deactivation()
        {
            self::log('debug', sprintf('enter %s -- with %s', __METHOD__, print_r(func_get_args(), true)));
        }

        /**
         * Show action links on the plugin screen.
         *
         * @param	mixed $links Plugin Action links
         * @return	array
         */
        public function plugin_action_links( $links ) {
            if (!$this->is_woocommerce_active()){
                return $links;
            }

            $action_links = array(
                'settings' => '<a href="' . PILIPAY_WC_SETTINGS_URL . '" title="' . esc_attr( __( 'View/Edit Settings Of Pilibaba Payment For WooCommerce', PILIPAY_WC ) ) . '">' . __( 'Settings', PILIPAY_WC ) . '</a>',
            );

            return array_merge( $action_links, $links );
        }
        /**
         * After plugin is loaded, load the gateway class
         */
        public function on_plugins_loaded()
        {
            if (!class_exists('WC_Payment_Gateway')) {
                self::log('warning', 'Pilibaba Payment for WooCommerce requires WooCommerce. Please install and activated WooCommerce.');
                return;
            }

            require_once(dirname(__FILE__) . DIRECTORY_SEPARATOR . 'class.wc-gateway-pilipay-gateway.php');
        }

        // add gateways to woocommerce:
        public function add_payment_gateways($methods)
        {
            $methods[] = 'WC_Gateway_Pilipay_Gateway';
            return $methods;
        }

        /**
         * update tracking number
         */
        public function deal_update_tracking_number()
        {
            self::log('debug', sprintf('enter %s -- with %s', __METHOD__, print_r(func_get_args(), true)));

            // verify parameters
            $order_id = $_REQUEST['post'];
            $tracking_number = $_REQUEST['trackingNumber'];
            if (!$order_id) {
                die(json_encode(array('success' => false, 'msg' => 'Invalid order ID!')));
            }

            // load order
            /**@var $order WC_Order */
            $order = wc_get_order($order_id);
            if (!$order) {
                die(json_encode(array('success' => false, 'msg' => 'Invalid order!')));
            }

            // save tracking number to post's meta data
            update_post_meta($order->id, PILIPAY_WC_TRACKING_NUMBER, $tracking_number);
            if ($tracking_number != get_post_meta($order->id, PILIPAY_WC_TRACKING_NUMBER, true)) {
                die(json_encode(array('success' => false, 'msg' => 'update failed.')));
            }

            try{
                // notify to pilipay gateway
                WC_Gateway_Pilipay_Gateway::instance();
                do_action('pilipay_wc_update_tracking_number', $order, $tracking_number);

                die(json_encode(array('success' => true)));
            } catch (Exception $e){
                die(json_encode(array(
                    'success' => false,
                    'errorCode' => $e->getCode(),
                    'errorMsg' => $e->getMessage(),
                )));
            }
        }

        /**
         * url: /wp-admin/admin-ajax.php?action=pilipay_wc_payment_result...
         */
        public function deal_payment_result()
        {
            self::log('debug', sprintf('enter %s -- with %s', __METHOD__, print_r(func_get_args(), true)));

            echo WC_Gateway_Pilipay_Gateway::instance()->deal_payment_result();
            die;
        }

        /**
         * URL: /?action=pilipay_wc_xxxx
         */
        public function deal_action(){
            $action = substr($_REQUEST['action'], PILIPAY_WC_PREFIX_LEN);
            $method = 'on_action_' . $action;
            if (method_exists($this, $method)){
                echo $this->{$method}();
                die;
            }
        }

        /**
         * URL: /?action=pilipay_wc_checkout
         */
        public function on_action_checkout(){
            $cart = WC()->cart;
            if (!($cart->get_cart())){
                wp_die(__("Error: the cart is empty! Please go back to add products."));
            }

            $gateway = new WC_Gateway_Pilipay_Gateway();
            $order_id = $gateway->create_order_from_cart($cart);
            if ($order_id){
                // Store Order ID in session so it can be re-used after payment failure
                WC()->session->order_awaiting_payment = $order_id;

                $payment = $gateway->process_payment($order_id);
                if ($payment['result'] === 'success'){
                    wp_redirect($payment['redirect']);
                    return;
                }
            }

            wc_print_notices();
            wc_clear_notices();
            wp_die(__("Pilibaba payment failed. Please choose another payment method instead.", PILIPAY_WC));
        }

        public function on_action_view_log(){
            header('Content-Type: text/plain');

            $len = isset($_REQUEST['len']) && intval($_REQUEST['len']) ? intval($_REQUEST['len']) : 80000;
            $file = fopen(PILIPAY_WC_LOG_PATH, 'r');
            if (!$file){
                die("file not found!");
            }

            fseek($file, -$len, SEEK_END);
            echo fread($file, $len);
            die;
        }

        // => /wp-admin/admin-ajax.php?action=pilipay_wc_payment_result
        public static function get_pay_result_callback_url()
        {
            return add_query_arg(array(
                'action' => 'pilipay_wc_payment_result',
            ), admin_url('admin-ajax.php'));
        }

        /**
         * @param $postId int
         * @return string => /wp-admin/admin-ajax.php?action=pilipay_wc_update_tracking_number
         */
        public static function get_update_tracking_number_url($postId)
        {
            return add_query_arg(array(
                'post' => $postId,
                'action' => 'pilipay_wc_update_tracking_number',
            ), admin_url('admin-ajax.php'));
        }

        /**
         * ensure pilipay lib is loaded
         */
        public static function ensure_pilipay_loaded()
        {
            require_once(dirname(__FILE__) . '/lib/pilipay/autoload.php');
            PilipayLogger::instance()->setHandler(array(__CLASS__, 'log'));
            PilipayConfig::setUseProductionEnv(WC_Gateway_Pilipay_Gateway::instance()->use_production_env);
        }

        /**
         * @return bool check wether WooCommerce is active
         */
        public function is_woocommerce_active()
        {
            return in_array('woocommerce/woocommerce.php', apply_filters('active_plugins', get_option('active_plugins')))
                   && !is_plugin_active_for_network('woocommerce/woocommerce.php');
        }

        /**
         * output a box of error
         * @param $msg string
         */
        public function report_error($msg)
        {
            echo '<div class="error"><p>' . __('Pilibaba Payment Error: ', PILIPAY_WC) . $msg . '</p></div>';
        }

        /**
         * when showing an shop_order post, add some meta boxes:
         */
        public function add_meta_boxes_shop_order()
        {
            /**@var $order WC_Order */
            $order = wc_get_order();
            if ($order && $order->payment_method == PILIPAY_STANDARD ){

                if (!$order->has_status(array('pending', 'on-hold'))) {
                    add_meta_box('pilipay_wc_shipping', __("Tracking Number For Pilibaba Payment"),
                        array($this, 'render_tracking_number'), null, 'normal', 'high');
                }

                if (get_post_meta($order->id, PILIPAY_WC_TEST_ORDER_TAG, true)){
                    add_meta_box('pilipay_wc_test_tag', __("Note Of Pilibaba Payment"),
                        array($this, 'render_test_tag'), null, 'normal', 'high');
                }

            }
        }

        /**
         * render tracking number section in admin backend's editing order page.
         * @param $post WP_Post
         */
        public function render_tracking_number($post)
        {
            self::log('debug', sprintf('enter %s -- with %s', __METHOD__, print_r(func_get_args(), true)));

            $trackingNumber = get_post_meta($post->ID, PILIPAY_WC_TRACKING_NUMBER, true);
            $trackingNumber = esc_attr($trackingNumber); // avoid xss/csrf
            $targetUrl = $this->get_update_tracking_number_url($post->ID);
            $targetUrl = esc_attr($targetUrl);

            self::ensure_pilipay_loaded();
            $order = new PilipayOrder();
            $order->orderNo = $post->ID;
            $order->merchantNO = WC_Gateway_Pilipay_Gateway::instance()->get_merchant_number();
            $barcodePicUrl = $order->getBarcodePicUrl();

            echo <<<HTML_CODE
<div class="info">
    <p>
        1. Notice the following barcode. Please <a href="{$barcodePicUrl}" download="barcode-order-{$post->ID}.jpg" >download it</a>, print it, and paste it on the parcel before shipping the parcel out:
        <br/>
        &nbsp; &nbsp; &nbsp;<a href="{$barcodePicUrl}" download="barcode-order-{$post->ID}.jpg" ><img src="{$barcodePicUrl}" /></a>
    </p>
    <p>
        2. Please update the tracking number after shipped the parcel to one of <a href="http://en.pilibaba.com/addressList" target="_blank">Pilibaba's warehouses</a>.
    </p>
    <p class="tracking-number-for-pilipay" action="" >
        Tracking Number: <input type="text" id="pilipayTrackingNumber" value="{$trackingNumber}" />
        <a id="pilipayUpdateTrackingNumber" class="button" href="javascript:void(0)" data-target-url="{$targetUrl}" >Update</a>
    </p>
</div>
HTML_CODE;

            wp_enqueue_script('pilipay-wc-tracking-number', PILIPAY_WC_PLUGIN_URL . '/js/tracking-number.js', array('jquery'));
        }

        public function render_test_tag(){
            echo <<<HTML_CODE
    <p>
        Note:
        This order is a test order.
        It has NOT been actually paid, even if the order's status indicates it's paid, as it is placed when you disabled Production Mode in Pilibaba Payment Settings.
    </p>
HTML_CODE;
            if (!WC_Gateway_Pilipay_Gateway::instance()->use_production_env){
                $settings = PILIPAY_WC_SETTINGS_URL;
                echo <<<HTML_CODE
    <p>
        Please enable Production Mode in <a href="{$settings}">the settings</a> if you are not to run a test.
    </p>
HTML_CODE;
            }
        }

        /**
         * [Pilibaba Payment] checkout button on cart page.
         */
        public function proceed_to_checkout()
        {
            if (!WC_Gateway_Pilipay_Gateway::instance()->is_available()){
                return;
            }

            if (!WC_Gateway_Pilipay_Gateway::instance()->enabled_express_checkout){
                return;
            }

            Pilipay_WC_Module::log('debug', sprintf('call %s with %s', __METHOD__, print_r(func_get_args(), true)));

            $checkoutTargetUrl = add_query_arg(array('action' => PILIPAY_WC_PREFIX . 'checkout'), home_url());
            $checkoutImgUrl = PILIPAY_WC_PLUGIN_URL . 'img/checkout-btn.png';
            $alt = 'Pilibaba支付, 支持银联, 直邮中国';

            echo "<a href=\"{$checkoutTargetUrl}\" title=\"{$alt}\"><img src=\"{$checkoutImgUrl}\" style=\"height: 4em; width: auto; display: inline-block; margin-top: -4px;\" alt=\"{$alt}\" /></a>";
        }

        /**
         * @param $level
         * @param $msg
         */
        public static function log($level, $msg)
        {
            static $hasLoggedRequest = null;

            if ($level == 'debug' && !PILIPAY_WC_DEBUG_ENABLE) {
                return;
            }

            $log = date('Y-m-d H:i:s') . " $level: $msg" . PHP_EOL;

            if (is_null($hasLoggedRequest)) {
                $log = PHP_EOL . str_repeat('#', 80) . PHP_EOL
                    . sprintf('%s %s', $_SERVER['REQUEST_METHOD'], $_SERVER['REQUEST_URI']) . PHP_EOL
                    . str_repeat('#', 80) . PHP_EOL
                    . $log;
                $hasLoggedRequest = true;
            }

            if ($level == 'debug') {
                $e = new Exception();
                $log .= str_replace(dirname(dirname(__FILE__)), '', $e->getTraceAsString()) . PHP_EOL;
            }

            @file_put_contents(PILIPAY_WC_LOG_PATH, $log, FILE_APPEND);
        }

        public static function debug_log_all_hooks($x)
        {
            $currentFilter = current_filter();
            if (strpos($currentFilter, 'woocommerce') !== false) {
                self::log('debug', ' invoke hook ' . $currentFilter);
            }
            return $x;
        }

        public static function instance()
        {
            if (!self::$_instance){
                self::$_instance = new Pilipay_WC_Module();
            }
            return self::$_instance;
        }

        private static $_instance;
    }

    $pilipayWcModule = Pilipay_WC_Module::instance();

endif; // (!class_exists('Pilipay_WC_Module'))
