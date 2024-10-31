<?php

class WC_Gateway_Pilipay_Gateway extends WC_Payment_Gateway
{
    public $shipping_fee;
    public $enabled_express_checkout;
    public $use_production_env;
    public $warehouse_address;

    public function __construct()
    {
        self::$_instance = $this;

        $this->id = PILIPAY_STANDARD;
        $this->icon = PILIPAY_WC_PLUGIN_URL . 'img/pilipay.png';
        $this->has_fields = false; // Bool. Can be set to true if you want payment fields to show on the checkout (if doing a direct integration).
        $this->method_title = __('Pilibaba Payment', PILIPAY_WC);
        $this->method_description = __('Pilibaba Payment - express checkout and shipping for Chinese customers', PILIPAY_WC);

        $this->init_form_fields();
        $this->init_settings();

        $this->title = $this->get_option('title');
        $this->shipping_fee = $this->get_option('shippingFee');
        $this->shipping_fee = is_numeric($this->shipping_fee) ? $this->shipping_fee : '';
        $this->enabled_express_checkout = ($this->get_option('enabledExpressCheckout') != 'no');
        $this->use_production_env = ($this->get_option('useProductionEnv') != 'no');
        $this->warehouse_address = json_decode(base64_decode($this->get_option('warehouseAddress')), true);

        add_action('admin_notices', array($this, 'checks'));
        add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
        add_action('pilipay_wc_update_tracking_number', array($this, 'update_tracking_number'), 10, 2);
    }

    public function is_enabled()
    {
        return $this->enabled != 'no';
    }

    /**
     * @return bool whether is available
     */
    public function is_available()
    {
        return $this->is_enabled()
            && $this->get_merchant_number()
            && $this->get_merchant_secret_key();
    }

    /**
     * checks any problems for this gateway to be available
     */
    public function checks()
    {
        if (!$this->is_enabled()) {
            return;
        }

        // PHP Version
        if (version_compare(phpversion(), '5.3', '<')) {
            echo '<div class="error"><p>' . sprintf(__('Pilibaba Payment Error: Pilibaba Payment requires PHP 5.3 and above. You are using version %s.', PILIPAY_WC), phpversion()) . '</p></div>';
        }

        Pilipay_WC_Module::ensure_pilipay_loaded();

        if (!PilipayConfig::check($errors)){
            foreach ($errors as $err) {
                echo '<div class="error"><p>' . sprintf(__('Pilibaba Payment Error: %s'), $err) . '</p></div>';
            }
        }

        // Check required fields
        if ((!$this->get_merchant_number() || !$this->get_merchant_secret_key())) {
            echo '<div class="error"><p>' . sprintf(__('Pilibaba Payment Error: Please <a href="%s">enter your merchant number and secret key</a> in order to make Pilibaba Payment available.', PILIPAY_WC), PILIPAY_WC_SETTINGS_URL) . '</p></div>';
        } elseif (!$this->use_production_env){
            echo '<div class="notice-warning notice"><p>' . sprintf(__('Pilibaba Payment Warning: Please <a href="%s">enable production mode</a> if you are not to run a test.', PILIPAY_WC), PILIPAY_WC_SETTINGS_URL) . '</p></div>';
        }
    }

    /**
     * deal payment result  -- callback from pilibaba
     */
    public function deal_payment_result()
    {
        Pilipay_WC_Module::log('debug', sprintf('call %s with %s', __METHOD__, print_r(func_get_args(), true)));
        Pilipay_WC_Module::ensure_pilipay_loaded();

        try {
            $pay_result = PilipayPayResult::fromRequest();

            Pilipay_WC_Module::log('info', sprintf('Got pilipay result: %s', print_r($_REQUEST, true)));

            // verify request
            if (!$pay_result->verify($this->get_merchant_secret_key())) {
                Pilipay_WC_Module::log('warning', sprintf('invalid payment result!'));
                return $pay_result->returnDealResultToPilibaba(31, 'Invalid request', $this->get_return_url());
            }

            // load the order
            /**@var $order WC_Order */
            $order = wc_get_order($pay_result->orderNo);

            // check payment result
            if (!$pay_result->isSuccess()) {
                $order->update_status('failed', __('Pilibaba Payment failed', PILIPAY_WC));

                $error_info = sprintf(__('%s (error code: %s)', PILIPAY_WC),
                    $pay_result->getErrorMsg(), $pay_result->getErrorCode());
                wc_add_notice(__('Payment error: ', 'woothemes') . $error_info, 'error');
                return $pay_result->returnDealResultToPilibaba(33, 'Pilibaba payment is failed', $this->get_return_url($order));
            }

            $order->payment_complete($pay_result->dealId);
            $order->add_order_note(__('Pilibaba Payment completed', PILIPAY_WC));

            return $pay_result->returnDealResultToPilibaba(1, 'success', $this->get_return_url($order));
        } catch (PilipayError $e) {
            if (!empty($pay_result)) {
                return $pay_result->returnDealResultToPilibaba($e->getCode(), $e->getMessage(), $this->get_return_url(isset($order) ? $order : null));
            } else {
                die($e->getCode() . ' ' . $e->getMessage());
            }
        }
    }

    /**
     * init form fields - these fields will be displayed in backend as forms
     */
    public function init_form_fields()
    {
        parent::init_form_fields();

        $whereToGet = __('<br/> Note: You can get this field from <a href="http://en.pilibaba.com/account/member-info" target="_blank" title="Merchant Information Page in Pilibaba.com">Merchant Information Page in pilibaba.com.</a>', PILIPAY_WC);

        $this->form_fields = array(
            'enabled' => array(
                'title' => __('Enable/Disable', 'woocommerce'),
                'type' => 'checkbox',
                'label' => __('Enable Pilibaba Payment', PILIPAY_WC),
                'default' => 'yes',
            ),
            'title' => array(
                'title' => __('Title', PILIPAY_WC),
                'type' => 'text',
                'description' => __('This controls the title which the user sees during checkout.', PILIPAY_WC),
                'desc_tip' => true,
                'default' => __('Pilibaba 支付（支持银联卡支付，直邮中国）', PILIPAY_WC),
            ),
            'merchantNo' => array(
                'title' => __('Merchant Number', PILIPAY_WC),
                'type' => 'text',
                'description' => __('This number identifies you from other Pilibaba\'s merchants. ', PILIPAY_WC) . $whereToGet,
                'autofocus' => 'autofocus',
            ),
            'secretKey' => array(
                'title' => __('Secret Key', PILIPAY_WC),
                'type' => 'password',
                'description' => __('This key makes the payment process much securer. ', PILIPAY_WC) . $whereToGet,
                'default' => '',
            ),
            'shippingFee' => array(
                'title' => __('Shipping Fee(tax inc.)', PILIPAY_WC),
                'type' => 'text',
                'description' => sprintf(__('This controls the shipping fee to a Pilibaba\'s warehouse. <br/>' .
                    'Unit: %s -- the current currency in WooCommerce. <br/>' .
                    'Note: Leave it blank if you want to use the default shipping fee in WooCommerce.', PILIPAY_WC), get_woocommerce_currency()),
                'default' => __('', PILIPAY_WC),
            ),
            'enabledExpressCheckout' => array(
                'title' => __('Express Checkout', PILIPAY_WC),
                'type' => 'checkbox',
                'label' => __('Enable Express Checkout', PILIPAY_WC),
                'default' => 'yes',
                'description' => __('This function allows customers to express checkout via a button on Cart page', PILIPAY_WC),
            ),
            'useProductionEnv' => array(
                'title' => __('Production Mode', PILIPAY_WC),
                'type' => 'checkbox',
                'label' => __('Enable Production Mode', PILIPAY_WC),
                'default' => 'yes',
                'description' => __('If you want to run a test, please disable this. <br/>Warning: If production mode is not enabled, orders will <strong>NOT</strong> be actually paid. They will be simulated as if be paid. ', PILIPAY_WC),
            ),
            'warehouseAddress' => array(
                'title' => __( 'Warehouse Address', PILIPAY_WC),
                'type' => 'select',
                'options' => $this->get_warehouse_address_options(),
                'description' => __('Please select a warehouse address for shipping.', PILIPAY_WC),
            ),
        );
    }

    /**
     * @return array warehouse address options for admin backend
     */
    public function get_warehouse_address_options(){
        $address_list = $this->get_warehouse_address_list();
        $options = array('' => '-- please select one --');

        foreach ($address_list as $addr) {
            $key = base64_encode(json_encode($addr));
            if ($addr['country'] == 'HongKong'){
                $options[$key] = $addr['city'].', China';
            } else {
                $options[$key] = $addr['city'].', '.$addr['country'];
            }
        }

        return $options;
    }

    /**
     * @return array warehouse address list of pilibaba
     */
    public function get_warehouse_address_list(){
        static $address_list = null;

        if ($address_list == null){
            $address_list = include(dirname(__FILE__).DIRECTORY_SEPARATOR.'warehouse_address_list.php');
        }

        return $address_list;
    }

    /**
     * @return string get the secret key of the merchant
     */
    public function get_merchant_secret_key()
    {
        return $this->get_option('secretKey');
    }

    /**
     * @return string get the merchant number
     */
    public function get_merchant_number()
    {
        return $this->get_option('merchantNo');
    }

    /**
     * @return bool
     */
    public function process_admin_options()
    {
        Pilipay_WC_Module::log('debug', sprintf('call %s with %s', __METHOD__, print_r(func_get_args(), true)));
        if (!parent::process_admin_options()) {
            return false;
        }

        $this->errors = array();

        if (!sanitize_text_field($this->validate_text_field('merchantNo'))) {
            $this->errors[] = __('Error: merchant number is required!', PILIPAY_WC);
        }

        if (!sanitize_text_field($this->validate_password_field('secretKey'))) {
            $this->errors[] = __('Error: secret key is required!', PILIPAY_WC);
        }

        $shipping_fee = sanitize_text_field($this->validate_text_field('shippingFee'));
        if (!empty($shipping_fee) && !is_numeric($shipping_fee)){
            $this->errors[] = __('Error: shipping fee must be a valid number or be blank', PILIPAY_WC);
        }

        if (!empty($this->errors)) {
            $this->display_errors();
            return false;
        }

        return true;
    }

    /**
     * display errors on backend admin settings page of woocommerce
     */
    public function display_errors()
    {
        foreach ($this->errors as $err) {
            WC_Admin_Settings::add_error($err);
        }
    }

    /**
     * @param $cart WC_Cart
     * @return int order ID
     */
    public function create_order_from_cart($cart)
    {
        global $wpdb;

        try {
            // Start transaction if available
            $wpdb->query('START TRANSACTION');

            $current_user = wp_get_current_user();
            if ($current_user){
                $customer_id = $current_user->ID;
            } else {
                $customer_id = null;
            }

            $customer_note = '';

            $order_data = array(
                'status' => apply_filters('woocommerce_default_order_status', 'pending'),
                'customer_id' => $customer_id,
                'customer_note' => $customer_note,
                'created_via' => 'checkout'
            );

            // new order
            $order = wc_create_order($order_data);

            if (is_wp_error($order)) {
                throw new Exception(sprintf(__('Error %d: Unable to create order. Please try again.', 'woocommerce'), 400));
            } else {
                $order_id = $order->id;
                do_action('woocommerce_new_order', $order_id);
            }

            // Store the line items to the new/resumed order
            foreach ($cart->get_cart() as $cart_item_key => $values) {
                $item_id = $order->add_product(
                    $values['data'],
                    $values['quantity'],
                    array(
                        'variation' => $values['variation'],
                        'totals' => array(
                            'subtotal' => $values['line_subtotal'],
                            'subtotal_tax' => $values['line_subtotal_tax'],
                            'total' => $values['line_total'],
                            'tax' => $values['line_tax'],
                            'tax_data' => $values['line_tax_data'] // Since 2.2
                        )
                    )
                );

                if (!$item_id) {
                    throw new Exception(sprintf(__('Error %d: Unable to create order. Please try again.', 'woocommerce'), 402));
                }

                // Allow plugins to add order item meta
                do_action('woocommerce_add_order_item_meta', $item_id, $values, $cart_item_key);
            }

            // Store fees
            foreach ($cart->get_fees() as $fee_key => $fee) {
                $item_id = $order->add_fee($fee);

                if (!$item_id) {
                    throw new Exception(sprintf(__('Error %d: Unable to create order. Please try again.', 'woocommerce'), 403));
                }

                // Allow plugins to add order item meta to fees
                do_action('woocommerce_add_order_fee_meta', $order_id, $item_id, $fee, $fee_key);
            }

            // Store shipping for all packages
//            foreach (WC()->shipping->get_packages() as $package_key => $package) {
//                $item_id = $order->add_shipping('pilibaba shipping'); // todo..
//
//                if (!$item_id) {
//                    throw new Exception(sprintf(__('Error %d: Unable to create order. Please try again.', 'woocommerce'), 404));
//                }
//
//                // Allows plugins to add order item meta to shipping
//                do_action('woocommerce_add_shipping_order_item', $order_id, $item_id, $package_key);
//            }

            // Store tax rows
            foreach (array_keys($cart->taxes + $cart->shipping_taxes) as $tax_rate_id) {
                if ($tax_rate_id && !$order->add_tax($tax_rate_id, $cart->get_tax_amount($tax_rate_id), $cart->get_shipping_tax_amount($tax_rate_id)) && apply_filters('woocommerce_cart_remove_taxes_zero_rate_id', 'zero-rated') !== $tax_rate_id) {
                    throw new Exception(sprintf(__('Error %d: Unable to create order. Please try again.', 'woocommerce'), 405));
                }
            }

            // Store coupons
            foreach ($cart->get_coupons() as $code => $coupon) {
                if (!$order->add_coupon($code, $cart->get_coupon_discount_amount($code), $cart->get_coupon_discount_tax_amount($code))) {
                    throw new Exception(sprintf(__('Error %d: Unable to create order. Please try again.', 'woocommerce'), 406));
                }
            }

            $shipping_address = array(
                'email' => $current_user ? $current_user->user_email : '',
            );

            if (!empty($this->warehouse_address)){
                $shipping_address['first_name'] = $this->warehouse_address['firstName'];
                $shipping_address['last_name'] = $this->warehouse_address['lastName'];
                $shipping_address['company'] = $this->warehouse_address['company'];
                $shipping_address['address_1'] = $this->warehouse_address['address'];
                $shipping_address['city'] = $this->warehouse_address['city'];
                $shipping_address['state'] = $this->warehouse_address['state'];
                $shipping_address['postcode'] = $this->warehouse_address['zipcode'];
                $shipping_address['country'] = $this->warehouse_address['country_code'];
                $shipping_address['phone'] = $this->warehouse_address['tel'];
            }

            $billing_address = array(
                'email' => $current_user ? $current_user->user_email : '',
            );

            $order->set_address($billing_address, 'billing');
            $order->set_address($shipping_address, 'shipping');
            $order->set_payment_method($this);
            $order->set_total($cart->shipping_total, 'shipping');
            $order->set_total($cart->get_cart_discount_total(), 'cart_discount');
            $order->set_total($cart->get_cart_discount_tax_total(), 'cart_discount_tax');
            $order->set_total($cart->tax_total, 'tax');
            $order->set_total($cart->shipping_tax_total, 'shipping_tax');
            $order->set_total($cart->total);

            // If we got here, the order was created without problems!
            $wpdb->query('COMMIT');
        } catch (Exception $e) {
            // There was an error adding order data!
            $wpdb->query('ROLLBACK');
            return new WP_Error('checkout-error', $e->getMessage());
        }

        return $order_id;
    }


    // when the customer is to pay, redirect them to pilibaba's site to pay
    public function process_payment($order_id)
    {
        Pilipay_WC_Module::log('debug', sprintf('call %s with %s', __METHOD__, print_r(func_get_args(), true)));
        Pilipay_WC_Module::ensure_pilipay_loaded();

        if (!$this->is_enabled()) {
            wc_add_notice(__('Payment error: ', 'woothemes') . __('Pilibaba payment is not enabled!', PILIPAY_WC), 'error');
            return array('result' => 'fail');
        }

        if (!$this->is_available()) {
            wc_add_notice(__('Payment error: ', 'woothemes') . __('Pilibaba payment is not available! Please contact the shop\'s owner to check configurations.', PILIPAY_WC), 'error');
            return array('result' => 'fail');
        }

        try {
            global $woocommerce;

            /**@var $order WC_Order */
            $order = wc_get_order($order_id);
            if (!$order) {
                wc_add_notice(__('Payment error: ', 'woothemes') . __('Invalid order!', PILIPAY_WC), 'error');
                return array('result' => 'fail');
            }

            if (!$this->use_production_env) {
                wc_add_notice(__('This order is just for testing!', PILIPAY_WC), 'success');
                $order->add_order_note(__('This order is just for testing!', PILIPAY_WC), 1);
                update_post_meta($order->id, PILIPAY_WC_TEST_ORDER_TAG, '[TEST-ORDER]!');
            }

            // update shipping fee
            if ($this->shipping_fee !== '' && !is_null($this->shipping_fee)){
                $original_shipping = $order->get_total_shipping();
                $original_total = $order->get_total();
                $order->set_total($this->shipping_fee, 'shipping');
                $order->set_total($original_total - ($original_shipping - (double)$this->shipping_fee) - $order->get_shipping_tax());
            }

            // mark as on-hold (we are waiting for the payment)
            $order->update_status('on-hold', __('Awaiting Piliaba Payment', PILIPAY_WC));

            // reduce stock levels
            $order->reduce_order_stock();

            // remove cart
            $woocommerce->cart->empty_cart();

            $returnUrl = $this->get_return_url($order);

            $orderTime = date("Y-m-d H:i:s");
            $pilipay_order = new PilipayOrder();
            $pilipay_order->merchantNO = $this->get_merchant_number();
            $pilipay_order->appSecret = $this->get_merchant_secret_key();
            $pilipay_order->currencyType = $order->get_order_currency();  // ...
            $pilipay_order->orderNo = $order_id;
            $pilipay_order->orderAmount = $order->get_total(); //...
            $pilipay_order->orderTime = $orderTime;
            $pilipay_order->sendTime = $orderTime;
            $pilipay_order->pageUrl = $returnUrl;
            $pilipay_order->serverUrl = Pilipay_WC_Module::get_pay_result_callback_url();
            $pilipay_order->shipper = $order->get_total_shipping(); // ...

            $products_total_price = 0;
            foreach ($order->get_items() as $orderProduct) {
                $product_id = $orderProduct['product_id'];
                $product = new WC_Product($product_id);
                list($img_url, $img_width, $img_height) = wp_get_attachment_image_src($product->get_image_id());

                $pilipay_product = new PilipayGood();
                $pilipay_product->name = $orderProduct['name'];
                $pilipay_product->pictureUrl = $img_url;
                $pilipay_product->price = $orderProduct['line_total']; // including tax
                $pilipay_product->productId = $product_id;
                $pilipay_product->productUrl = get_permalink($product_id);
                $pilipay_product->quantity = $orderProduct['qty'];
                $pilipay_product->weight = $product->get_weight();
                $pilipay_product->weightUnit = get_option('woocommerce_weight_unit');

                $products_total_price += $pilipay_product->quantity * $pilipay_product->price;
                $pilipay_order->addGood($pilipay_product);
            }

            // calculate additional tax
            //  $pilipay_order->tax = $pilipay_order->orderAmount - $pilipay_order->shipper - $products_total_price;
            $pilipay_order->tax = $order->get_total_tax();

            $submit_result = $pilipay_order->submit();
            if (!$submit_result['success']) {
                if ($submit_result['errorCode'] == 500){
                    $submit_result['message'] = "Sorry, but Pilibaba's server is gone";
                }

                Pilipay_WC_Module::log('error', "submit order to pilibaba fail: erorrCode: {$submit_result['errorCode']} nextUrl: {$submit_result['nextUrl']} message: {$submit_result['message']} ");

                $error_info = sprintf(__('%s (error code: %s)', PILIPAY_WC), $submit_result['message'], $submit_result['errorCode']);
                wc_add_notice(__('Payment error: ', 'woothemes') . $error_info, 'error');
                return array('result' => 'fail');
            }

            return array(
                'result' => 'success',
                'redirect' => $submit_result['nextUrl']
            );
        } catch (PilipayError $e) {
            Pilipay_WC_Module::log('error', "encounter a pilipay error when submiting order: " . $e->getMessage() . " errorCode:" . $e->getCode() . ' trace: ' . $e->getTraceAsString());
            $error_info = sprintf(__('%s (error code: %s)', PILIPAY_WC), $e->getMessage(), $e->getCode());
            wc_add_notice(__('Payment error: ', 'woothemes') . $error_info, 'error');
            return array(
                'result' => 'fail',
            );
        }
    }

    /**
     * @param $order WC_Order
     * @param $trackingNumber
     */
    public function update_tracking_number($order, $trackingNumber)
    {
        Pilipay_WC_Module::log('debug', sprintf('call %s with %s', __METHOD__, print_r(func_get_args(), true)));
        Pilipay_WC_Module::ensure_pilipay_loaded();

        try {
            $pilipay_order = new PilipayOrder();
            $pilipay_order->merchantNO = $this->get_merchant_number();
            $pilipay_order->orderNo = $order->id;

            $pilipay_order->updateTrackNo($trackingNumber);

        } catch (PilipayError $e) {
            Pilipay_WC_Module::log('error', "encounter a pilipay error when updating tracking number: " . $e->getMessage() . " errorCode:" . $e->getCode() . ' trace: ' . $e->getTraceAsString());
            throw $e;
        }
    }

    /**
     * @return null|WC_Gateway_Pilipay_Gateway
     */
    public static function instance()
    {
        if (!self::$_instance) {
            self::$_instance = new WC_Gateway_Pilipay_Gateway();
        }

        return self::$_instance;
    }

    private static $_instance = null;
}
