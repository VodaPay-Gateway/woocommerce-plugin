<?php

if (!defined('ABSPATH')) {
    exit;
}

$f = dirname(__DIR__, 1);
require_once "$f/vendor/autoload.php";

use Ngenius\NgeniusCommon\Formatter\ValueFormatter;
use Ngenius\NgeniusCommon\NgeniusHTTPTransfer;
use Ngenius\NgeniusCommon\Processor\RefundProcessor;

require_once dirname(__FILE__) . '/config/class-vodapay-gateway-config.php';
require_once dirname(__FILE__) . '/request/class-vodapay-gateway-request-token.php';
require_once dirname(__FILE__) . '/http/class-vodapay-gateway-http-transfer.php';
require_once dirname(__FILE__) . '/http/class-vodapay-gateway-http-abstract.php';

/**
 * Class VodaPayAbstract
 */
class VodaPayAbstract extends WC_Payment_Gateway
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
    private $message;
    public $id;
    public $icon;
    /**
     * @var false
     */
    private bool $hasFields;
    /**
     * @var string[]
     */
    public $supports;
    public $title;
    public $description;
    public $enabled;
    public $environment;
    public $paymentAction;
    public $orderStatus;
    public $outletRef;
    public $apiKey;
    protected bool $debug;
    public $debugMode;

    /**
     * Constructor for the gateway.
     */
    public function __construct()
    {
        $this->id = 'vodapay';
        // URL of the icon that will be displayed on checkout page near your gateway name
        $this->icon         = '';
        $this->hasFields    = false; // in case you need a custom credit card form
        $this->method_title = 'VodaPay Digital Payment Gateway';

        // will be displayed on the options page
        $this->method_description = 'Payment Gateway from VodaPay Digital Payment Gateway';

        // gateways can support subscriptions, refunds, saved payment methods
        $this->supports = array(
            'products',
            'refunds',
        );
        include_once dirname(__FILE__) . '/settings-vodapay.php';

        // Method with all the options fields
        $settingsVodaPay   = new SettingsVodaPay();
        $this->form_fields = $settingsVodaPay->overrideFormFieldsVariable();

        // Load the settings.
        $this->init_settings();

        $this->method_title       = __('VodaPay Digital Payment Gateway', 'vodapay');
        $this->method_description = __(
            'VodaPay works by sending the customer to VodaPay Digital Payment Gateway to complete their payment.',
            'vodapay'
        );

        $this->title         = $this->get_option('title');
        $this->description   = $this->get_option('description');
        $this->enabled       = $this->get_option('enabled');
        $this->environment   = $this->get_option('environment');
        $this->paymentAction = $this->get_option('payment_action');
        $this->orderStatus   = $this->get_option('orderStatus');
        $this->outletRef     = $this->get_option('outlet_ref');
        $this->apiKey        = $this->get_option('api_key');
        $this->debug         = 'yes' === $this->get_option('debug', 'no');
        $this->debugMode     = $this->get_option('debugMode');

        self::$logEnabled = $this->debug;
    }

    /**
     * get_instance
     *
     * Returns a new instance of self, if it does not already exist.
     *
     * @access public
     * @static
     * @return VodaPayGateway
     */
    public static function get_instance()
    {
        if (!isset(self::$instance)) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * Process Authorization Reversal
     *
     * @param WC_Order $order
     * @param VodaPayGatewayConfig $config
     * @param object $orderItem
     */
    public function vodapay_void(WC_Order $order, VodaPayGatewayConfig $config, object $orderItem)
    {
        include_once dirname(__FILE__) . '/request/class-vodapay-gateway-request-void.php';
        include_once dirname(__FILE__) . '/http/class-vodapay-gateway-http-void.php';
        include_once dirname(__FILE__) . '/validator/class-vodapay-gateway-validator-void.php';

        $requestClass = new VodaPayGatewayRequestVoid($config);
        $requestHttp  = new VodaPayGatewayHttpVoid();
        $validator    = new VodaPayGatewayValidatorVoid();

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
        if ($result) {
            $data           = [];
            $data['status'] = $result['orderStatus'];
            $data['state']  = $result['state'];
            $this->updateData($data, array('nid' => $orderItem->nid));
            $this->message = 'The void transaction was successful.';
            $order->update_status($result['orderStatus']);
            $order->add_order_note($this->message);
        }
    }

    /**
     * Process Refund
     *
     * @param int $orderId
     * @param float|null $amount
     * @param string $reason
     *
     * @return bool
     */
    public function process_refund($order_id, $amount = null, $reason = '')
    {
        $this->message = '';
        if (isset($amount) && $amount > 0) {
            include_once dirname(__FILE__) . '/request/class-vodapay-gateway-request-refund.php';
            include_once dirname(__FILE__) . '/http/class-vodapay-gateway-http-refund.php';
            include_once dirname(__FILE__) . '/validator/class-vodapay-gateway-validator-refund.php';
            include_once dirname(__FILE__) . '/class-vodapay-gateway-payment.php';

            $orderItem = $this->fetch_order($order_id);

            $order = wc_get_order($order_id);

            $config     = new VodaPayGatewayConfig($this, $order);
            $tokenClass = new VodaPayGatewayRequestToken($config);

            if ($config->is_complete()) {
                $token = $tokenClass->get_access_token();

                return $this->validateRefund($token, $config, $order, $orderItem, $amount);
            }
        }

        return false;
    }


    public function validateRefund($token, $config, $order, $orderItem, $amount): bool|array
    {
        if ($token) {
            $config->set_token($token);
            $requestClass = new VodaPayGatewayRequestRefund($config);
            $requestHttp  = new VodaPayGatewayHttpRefund();
            $validator    = new VodaPayGatewayValidatorRefund();
            $refundUrl    = $this->get_refund_url($orderItem->reference);

            $requestBuild = $requestClass->build($orderItem, $amount, $refundUrl);

            $transferClass = new NgeniusHttpTransfer(
                $requestBuild['request']['uri'],
                $config->get_http_version(),
                $requestBuild['request']['method'],
                $requestBuild['request']['data']
            );

            $transferClass->setPaymentHeaders($token);

            $response = $requestHttp->place_request($transferClass);

            $result         = $validator->validate($response);
            $responseResult = $this->validateRefundResult($result, $order, $orderItem);
            if ($responseResult) {
                return true;
            }
        }

        return false;
    }

    public function validateRefundResult($result, $order, $orderItem)
    {
        if ($result) {
            $currencyCode   = $orderItem->currency;
            $capturedAmount = $result['captured_amt'];
            $refundedAmount = $result['refunded_amt'];

            ValueFormatter::formatCurrencyDecimals($currencyCode, $refundedAmount);

            $data                 = [];
            $data['status']       = $result['orderStatus'];
            $data['state']        = $result['state'];
            $data['captured_amt'] = $capturedAmount;
            $this->updateData($data, array('nid' => $orderItem->nid));
            $orderMessage  = 'Refunded an amount ' . $currencyCode . $refundedAmount;
            $this->message = 'Success! ' . $orderMessage . ' of an order #' . $orderItem->order_id;
            $orderMessage  .= ' | Transaction ID: ' . $result['transaction_id'];
            $order->add_order_note($orderMessage);
            WC_Admin_Notices::add_custom_notice('vodapay', $this->message);

            return true;
        }
    }

    /**
     * @return refund_url
     * Get response from api for order ref code end
     */
    public function get_refund_url($orderRef)
    {
        $payment  = new VodaPayGatewayPayment();
        $response = $payment->get_response_api($orderRef);

        if (isset($response->errors)) {
            return $response->errors[0]->message;
        }

        $payment = $response->_embedded->payment[0];

        return RefundProcessor::extractUrl($payment);
    }
}
