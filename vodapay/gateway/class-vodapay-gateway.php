<?php

if (!defined('ABSPATH')) {
    exit;
}

$f = dirname(__DIR__, 1);
require_once "$f/vendor/autoload.php";

require_once dirname(__FILE__) . '/class-vodapay-abstract.php';

use Ngenius\NgeniusCommon\Formatter\ValueFormatter;
use Ngenius\NgeniusCommon\NgeniusHTTPTransfer;
use Ngenius\NgeniusCommon\NgeniusOrderStatuses;

/**
 * VodaPayGateway class.
 */
class VodaPayGateway extends VodaPayAbstract
{
    /**
     * Whether logging is enabled
     *
     * @var bool
     */
    public static bool $logEnabled = false;

    /**
     * Logger instance
     *
     * @var bool|WC_Logger
     */
    public static bool|WC_Logger $log = false;

    /**
     * Singleton instance
     *
     * @var VodaPayGateway
     */
    private static VodaPayGateway $instance;

    /**
     * Notice variable
     *
     * @var string
     */
    private string $message;

    /**
     * get_instance
     *
     * Returns a new instance of self, if it does not already exist.
     *
     * @access public
     * @static
     * @return VodaPayGateway
     */
    public static function get_instance(): VodaPayGateway
    {
        if (!isset(self::$instance)) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * Logging method.
     *
     * @param string $message Log message.
     * @param string $level Optional. Default 'info'. Possible values:
     *                        emergency|alert|critical|error|warning|notice|info|debug.
     */
    public static function log(string $message, string $level = 'debug')
    {
        if (self::$logEnabled) {
            if (empty(self::$log)) {
                self::$log = wc_get_logger();
            }
            self::$log->log($level, $message, array('source' => 'vodapay'));
        }
    }

    /**
     * Cron Job Hook
     */
    public function vodapay_cron_task()
    {
        if (!wp_next_scheduled('vodapay_cron_order_update')) {
            wp_schedule_event(time(), 'hourly', 'vodapay_cron_order_update');
        }
        add_action('vodapay_cron_order_update', array($this, 'cron_order_update'));
    }

    // Add the Gateway to WooCommerce

    /**
     * Initialize module hooks
     */
    public function init_hooks()
    {
        add_action('init', array($this, 'vodapay_cron_task'));
        add_action('woocommerce_api_vodapay', array($this, 'update_vodapay_response'));
        if (is_admin()) {
            add_filter('woocommerce_payment_gateways', array($this, 'vodapay_woocommerce_add_gateway_vodapay'));
            add_action(
                'woocommerce_update_options_payment_gateways_' . $this->id,
                array($this, 'processAdminOptions')
            );
            add_action('add_meta_boxes', array($this, 'vodapay_online_meta_boxes'), 10, 2);
            add_action('woocommerce_order_action_vodapay_capture', array($this, 'vodapay_capture_order_action'), 10, 1);
            add_action('woocommerce_order_action_vodapay_void', array($this, 'vodapay_void_order_action'), 10, 1);
            add_action('woocommerce_order_actions', array($this, 'wc_add_order_meta_box_action'), 1, 2);
        }
    }

    public function wc_add_order_meta_box_action($actions, $order)
    {
        $order_item = $this->fetch_order($order->get_id());

        if ($order_item && 'vp-authorised' === $order_item->status) {
            $actions['vodapay_capture'] = __('Capture VodaPay Digital Payment Gateway', 'vodapay');
            $actions['vodapay_void']    = __('Void VodaPay Digital Payment Gateway', 'vodapay');
        }

        return $actions;
    }

    public function vodapay_capture_order_action($order)
    {
        $vodapay_state = ['vodapay_capture' => true];
        $this->vodapayAction($order, $vodapay_state);
    }

    public function vodapay_void_order_action($order)
    {
        $vodapay_state = ['vodapay_void' => true];
        $this->vodapayAction($order, $vodapay_state);
    }

    /**
     * Handle actions on order page
     *
     * @param $order
     *
     * @return void
     */
    public function vodapayAction($order, $vodapay_state): void
    {
        $this->message = '';
        WC_Admin_Notices::remove_all_notices();
        $orderID    = $order->get_id();
        $order_item = $this->fetch_order($orderID);

        if ($order_item) {
            $config      = new VodaPayGatewayConfig($this, $order);
            $token_class = new VodaPayGatewayRequestToken($config);

            $this->validate_complete($config, $token_class, $order, $order_item, $vodapay_state);
        } else {
            $this->message = 'Order #' . $orderID . ' not found.';
            WC_Admin_Notices::add_custom_notice('vodapay', $this->message);
        }
        add_filter('redirect_post_location', array($this, 'add_notice_query_var'), 99);
    }

    /**
     * VodaPay Digital Payment Gateway Meta Boxes
     */
    public function vodapay_online_meta_boxes($post_type, $post)
    {
        $order_id = $post->ID;
        $order    = wc_get_order($order_id);
        if (!$order) {
            return;
        }

        $payment_method = $order->get_meta('_payment_method', true);
        if ($this->id === $payment_method) {
            add_meta_box(
                'vodapay-payment-actions',
                __('VodaPay Digital Payment Gateway', 'woocommerce'),
                array($this, 'vodapay_online_meta_box_payment'),
                $post_type,
                'side',
                'high'
            );
        }
    }

    /**
     * Generate the VodaPay Digital Payment Gateway payment meta box and echos the HTML
     */
    public function vodapay_online_meta_box_payment($post)
    {
        $order_id = $post->ID;
        $order    = wc_get_order($order_id);
        if (!empty($order)) {
            $order_item    = $this->fetch_order($order_id);
            $currency_code = $order_item->currency . ' ';
            ValueFormatter::formatCurrencyDecimals(trim($currency_code), $order_item->amount);
            $html = '';
            try {
                $ngAuthorised            = "";
                $ngAuthorisedAmount      = $currency_code . $order_item->amount;
                $ngAuthorisedAmountLabel = __('Authorized:', 'vodapay');
                if ('ng-authorised' === $order_item->status) {
                    $ngAuthorised = "
                        <tr>
                            <td> $ngAuthorisedAmountLabel </td>
                            <td> $ngAuthorisedAmount </td>
                        </tr>
";
                }
                $refunded = 0;
                if ('ng-full-refunded' === $order_item->status || 'ng-part-refunded' === $order_item->status ||
                    'refunded' === $order_item->status) {
                    $refunds = $order->get_refunds();
                    foreach ($refunds as $refund) {
                        $refunded += (double)($refund->get_data()["amount"]);
                    }
                }

                $ngAuthorised2 = "";

                $orderStatuses  = NgeniusOrderStatuses::orderStatuses('VodaPay', 'vp');
                $ng_state       = __('Status:', 'vodapay');
                $ng_state_value = $order->get_status();

                $itemState = $order_item->state;
                foreach ($orderStatuses as $status) {
                    if ("wc-" . $ng_state_value === $status["status"]) {
                        $ng_state_value = $status["label"];
                    }
                }

                $ng_payment_id_label = __('Payment_ID:', 'vodapay');
                $ng_payment_id       = $order_item->payment_id;

                $ng_captured_label  = __('Captured:', 'vodapay');
                $ng_captured_amount = $currency_code . $order_item->amount;

                $ng_refunded_label  = __('Refunded:', 'vodapay');
                $ng_refunded_amount = $currency_code . $refunded;

                $html = "
                    <table>
                    <tr>
                        <td> $ng_state </td>
                        <td> $ng_state_value </td>
                    </tr>
                    <tr>
                        <td> $ng_payment_id_label </td>
                        <td> $ng_payment_id </td>
                    </tr>
                    $ngAuthorised
                 
                    <tr>
				        <td> $ng_refunded_label </td>
				        <td> $ng_refunded_amount </td>
				    </tr>
				    $ngAuthorised2

";
                // Don't display captured line on 'STARTED' and 'AUTHORISED' states
                if ($itemState != 'STARTED'
                    && $itemState != 'AUTHORISED'
                    && $itemState != 'REVERSED'
                ) {
                    $html .= "
                    <tr>
                        <td> $ng_captured_label </td>
				        <td> $ng_captured_amount </td>
                    </tr>
";
                }
                $html .= '
 </table>
';

                if ('ng-authorised' === $order_item->status) {
                    $html .= '
 <hr>
 <p style="color: gray;">Void and capture moved to order actions.</p>
 ';
                }

                echo wp_kses_post($html);
            } catch (Exception $e) {
                throw new InvalidArgumentException(wp_kses_post($e->getMessage()));
            }
        }
    }

    // WooCommerce DPO Group settings html

    public function vodapay_woocommerce_add_gateway_vodapay($methods)
    {
        $methods[] = $this->id;

        return $methods;
    }

    public function payment_fields()
    {
        $html = new stdClass();
        parent::payment_fields();
        do_action('dpocard_solution_addfields', $html);
        if (isset($html->html)) {
            echo esc_html($html->html);
        }
    }

    /**
     * Add notice query variable
     *
     * @param string $location
     *
     * @return string
     */
    public function add_notice_query_var(string $location): string
    {
        remove_filter('redirect_post_location', array($this, 'add_notice_query_var'), 99);

        return add_query_arg(array('message' => false), $location);
    }

    /**
     * Processing order
     *
     * @param int $order_id
     *
     * @return array
     * @throws Exception
     * @global object $woocommerce
     */
    public function process_payment($order_id): array
    {
        include_once dirname(__FILE__) . '/request/class-vodapay-gateway-request-authorize.php';
        include_once dirname(__FILE__) . '/request/class-vodapay-gateway-request-sale.php';
        include_once dirname(__FILE__) . '/request/class-vodapay-gateway-request-purchase.php';
        include_once dirname(__FILE__) . '/http/class-vodapay-gateway-http-authorize.php';
        include_once dirname(__FILE__) . '/http/class-vodapay-gateway-http-purchase.php';
        include_once dirname(__FILE__) . '/http/class-vodapay-gateway-http-sale.php';
        include_once dirname(__FILE__) . '/validator/class-vodapay-gateway-validator-response.php';

        global $woocommerce;
        $order       = wc_get_order($order_id);
        $config      = new VodaPayGatewayConfig($this, $order);
        $token_class = new VodaPayGatewayRequestToken($config);
        $data        = [];


        if ($config->is_complete()) {
            $token = $token_class->get_access_token();
            if ($token && !is_wp_error($token)) {
                $config->set_token($token);
                if ($config->get_payment_action() == "authorize") {
                    $request_class = new VodaPayGatewayRequestAuthorize($config);
                    $request_http  = new VodaPayGatewayHttpAuthorize();
                } elseif ($config->get_payment_action() == "sale") {
                    $request_class = new VodaPayGatewayRequestSale($config);
                    $request_http  = new VodaPayGatewayHttpSale();
                } elseif ($config->get_payment_action() == "purchase") {
                    $request_class = new VodaPayGatewayRequestPurchase($config);
                    $request_http  = new VodaPayGatewayHttpPurchase();
                }


                $validator = new VodaPayGatewayValidatorResponse();

                $tokenRequest = $request_class->build($order);

                $transferClass = new NgeniusHttpTransfer(
                    $tokenRequest['request']['uri'],
                    $config->get_http_version(),
                    $tokenRequest['request']['method'],
                    $tokenRequest['request']['data']
                );

                $transferClass->setPaymentHeaders($token);

                if (is_wp_error($tokenRequest['token'])) {
                    $this->checkoutErrorThrow('Invalid Server Config');
                }

                $response = $request_http->place_request($transferClass);

                $result = $validator->validate($response);

                if ($result) {
                    $this->save_data($order);
                    $woocommerce->cart->empty_cart();
                    $data = array(
                        'result'   => 'success',
                        'redirect' => $result,
                    );
                }
            } else {
                $errorMsg = $token->errors['error'][0];

                if ($errorMsg == '') {
                    $errorMsg = 'Invalid configuration';
                }
                $this->checkoutErrorThrow("Error! " . $errorMsg . ".");
            }
        } else {
            $this->checkoutErrorThrow("Error! Invalid configuration.");
        }

        return $data;
    }

    /**
     * @throws Exception
     */
    private function checkoutErrorThrow($message)
    {
        throw new Exception(wp_kses_post($message));
    }

    /**
     * Save data
     *
     * @param object $order
     *
     * @global object $wp_session
     * @global object $wpdb
     */
    public function save_data(object $order)
    {
        global $wpdb;
        global $wp_session;

        $order_id  = $order->get_id();
        $cache_key = 'vodapay_order_' . $order_id;

        // Prepare the data to be saved
        $data = array_merge(
            $wp_session['vodapay'],
            array(
                'order_id' => $order_id,
                'currency' => $order->get_currency(),
                'amount'   => $order->get_total(),
            )
        );

        // Check if the data is already cached
        $cached_data = wp_cache_get($cache_key, 'vodapay');

        if ($cached_data === false) {
            // Data not cached, perform the database operation and cache the data
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
            $wpdb->replace(VODAPAY_TABLE, $data);

            // Cache the data
            wp_cache_set($cache_key, $data, 'vodapay');
        } else {
            // Data is already cached, no need to perform the database operation
        }
    }

    /**
     * Update data
     *
     * @param array $data
     * @param array $where
     *
     * @global object $wpdb
     */
    public function updateData(array $data, array $where): void
    {
        global $wpdb;

        // Define a unique cache key based on the update data and conditions
        $cache_key = 'vodapay_' . md5(serialize($where));

        // Perform the database update
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery , WordPress.DB.DirectDatabaseQuery.NoCaching
        $updated = $wpdb->update(VODAPAY_TABLE, $data, $where);

        // If the update is successful, update the cache
        if ($updated !== false) {
            wp_cache_set($cache_key, $data, 'vodapay_cache');
        }
    }

    /**
     * Processes and saves options.
     * If there is an error thrown, will continue to save and validate fields, but will leave the error field out.
     *
     * @return bool was anything saved?
     */
    public function processAdminOptions(): bool
    {
        $saved = parent::process_admin_options();
        if ('yes' === $this->get_option('enabled', 'no')) {
            if (empty($this->get_option('outletRef'))) {
                $this->add_settings_error(
                    'vodapay_error',
                    esc_attr('settings_updated'),
                    __('Invalid Reference ID'),
                    'error'
                );
            }
            if (empty($this->get_option('apiKey'))) {
                $this->add_settings_error(
                    'vodapay_error',
                    esc_attr('settings_updated'),
                    __('Invalid API Key'),
                    'error'
                );
            }
            add_action('admin_notices', 'vodapay_print_errors');
        }
        if ('yes' !== $this->get_option('debug', 'no')) {
            if (empty(self::$log)) {
                self::$log = wc_get_logger();
            }
            self::$log->clear('vodapay');
        }

        return $saved;
    }

    public function add_settings_error($setting, $code, $message, $type = 'error')
    {
        global $wp_settings_errors;

        $wp_settings_errors[] = array(
            'setting' => $setting,
            'code'    => $code,
            'message' => $message,
            'type'    => $type,
        );
    }

    /**
     * Catch response from VodaPay Digital Payment Gateway
     */
    public function update_vodapay_response()
    {
        $order_ref = filter_input(INPUT_GET, 'ref', FILTER_SANITIZE_FULL_SPECIAL_CHARS);
        include_once plugin_dir_path(__FILE__) . '/class-vodapay-gateway-payment.php';
        $payment = new VodaPayGatewayPayment();
        $payment->execute($order_ref);
        die;
    }

    /**
     * Cron Job Action
     */
    public function cron_order_update()
    {
        include_once plugin_dir_path(__FILE__) . '/class-vodapay-gateway-payment.php';
        $payment = new VodaPayGatewayPayment();

        $cronSuccess = $payment->order_update();
        if ($cronSuccess) {
            $this->log('Cron updated the orders: ' . $cronSuccess);
        }
    }

    /**
     * Can the order be refunded?
     *
     * @param WC_Order $order Order object.
     *
     * @return bool
     */
    public function can_refund_order($order): bool
    {
        $order_item = $this->fetch_order($order->get_id());
        if (in_array($order_item->status, array('vp-complete', 'vp-captured', 'vp-part-refunded'), true)) {
            return true;
        }

        return false;
    }

    /**
     * Fetch Order details.
     *
     * @param int $order_id
     *
     * @return object
     */
    public function fetch_order(int $order_id): object|null
    {
        global $wpdb;

        // Define a unique cache key
        $cache_key = 'vodapay_order_' . $order_id;

        // Try to get the order data from cache
        $order = wp_cache_get($cache_key, 'vodapay_orders');

        // If cache miss, query the database
        if ($order === false) {
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery
            $order = $wpdb->get_row(sprintf('SELECT * FROM %s WHERE order_id=%d', VODAPAY_TABLE, $order_id));

            // Store the result in the cache
            wp_cache_set($cache_key, $order, 'vodapay_orders', 3600); // Cache for 1 hour
        }

        return $order;
    }

    public function validate_complete($config, $token_class, $order, $order_item, $vodapay_state)
    {
        if ($config->is_complete()) {
            $token = $token_class->get_access_token();
            if ($token) {
                $config->set_token($token);
                if ($vodapay_state['vodapay_capture']) {
                    $this->vodapay_capture($order, $config, $order_item);
                } elseif ($vodapay_state['vodapay_void']) {
                    $this->vodapay_void($order, $config, $order_item);
                }
                WC_Admin_Notices::add_custom_notice('vodapay', $this->message);
            }
        } else {
            $this->message = 'Payment gateway credential are not configured properly.';
            WC_Admin_Notices::add_custom_notice('vodapay', $this->message);
        }
    }

    /**
     * Process Capture
     *
     * @param WC_Order $order
     * @param VodaPayGatewayConfig $config
     * @param object $orderItem
     */
    public function vodapay_capture(WC_Order $order, VodaPayGatewayConfig $config, object $orderItem)
    {
        include_once dirname(__FILE__) . '/request/class-vodapay-gateway-request-capture.php';
        include_once dirname(__FILE__) . '/http/class-vodapay-gateway-http-capture.php';
        include_once dirname(__FILE__) . '/validator/class-vodapay-gateway-validator-capture.php';

        $requestClass = new VodaPayGatewayRequestCapture($config);
        $requestHttp  = new VodaPayGatewayHttpCapture();
        $validator    = new VodaPayGatewayValidatorCapture();

        $requestBuild = $requestClass->build($orderItem);

        $transferClass = new NgeniusHttpTransfer(
            $requestBuild['request']['uri'],
            $config->get_http_version(),
            $requestBuild['request']['method'],
            $requestBuild['request']['data']
        );

        $transferClass->setPaymentHeaders($requestBuild['token']);

        $response = $requestHttp->place_request($transferClass);
        $result   = $validator->validate($response);

        $currencyCode = $orderItem->currency;

        $capturedAmount = $result['captured_amt'];
        $totalCaptured  = $result['total_captured'];

        ValueFormatter::formatCurrencyDecimals($currencyCode, $capturedAmount);

        if (isset($result['status']) && $result['status'] === "failed") {
            $order_message = $result['message'];
            $order->add_order_note($order_message);
        } else {
            $data                 = [];
            $data['status']       = $result['orderStatus'];
            $data['state']        = $result['state'];
            $data['captured_amt'] = $totalCaptured;
            $data['capture_id']   = $result['transaction_id'];
            $this->updateData($data, array('nid' => $orderItem->nid));
            $order_message = 'Captured an amount ' . $currencyCode . $capturedAmount;
            $this->message = 'Success! ' . $order_message . ' of an order #' . $orderItem->order_id;
            $order_message .= ' | Transaction ID: ' . $result['transaction_id'];
            $order->payment_complete($result['transaction_id']);
            $order->update_status($result['orderStatus']);
            $order->add_order_note($order_message);
            $eMailer = new WC_Emails();
            $eMailer->customer_invoice($order);
        }
    }
}
