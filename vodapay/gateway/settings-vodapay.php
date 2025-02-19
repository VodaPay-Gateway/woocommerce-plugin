<?php

/**
 * Settings for VodaPay Digital Payment Gateway.
 */
defined('ABSPATH') || exit;

class SettingsVodaPay extends WC_Settings_API
{
    public static $currencies = [
        'AED',
        'ALL',
        'AOA',
        'AUD',
        'BHD',
        'BWP',
        'CAD',
        'DKK',
        'EGP',
        'EUR',
        'GBP',
        'GHS',
        'GNF',
        'HKD',
        'INR',
        'JOD',
        'JPY',
        'KES',
        'KWD',
        'LKR',
        'MAD',
        'MWK',
        'MYR',
        'NAD',
        'NGN',
        'OMR',
        'PHP',
        'PKR',
        'QAR',
        'SAR',
        'SEK',
        'SGD',
        'THB',
        'TRY',
        'TZS',
        'UGX',
        'USD',
        'XAF',
        'XOF',
        'ZAR',
        'ZMW',
        'ZWL'
    ];

    public function overrideFormFieldsVariable()
    {
        return array(
            'enabled'                       => array(
                'title'   => __('Enable/Disable', 'vodapay'),
                'label'   => __('Enable VodaPay Digital Payment Gateway', 'vodapay'),
                'type'    => 'checkbox',
                'default' => 'no',
            ),
            'title'                         => array(
                'title'       => __('Title', 'vodapay'),
                'type'        => 'text',
                'description' => __('The title which the user sees during checkout.', 'vodapay'),
                'default'     => __('VodaPay Digital Payment Gateway', 'vodapay'),
            ),
            'description'                   => array(
                'title'       => __('Description', 'vodapay'),
                'type'        => 'textarea',
                'css'         => 'width: 400px;height:60px;',
                'description' => __('The description which the user sees during checkout.', 'vodapay'),
                'default'     => __('You will be redirected to payment gateway.', 'vodapay'),
            ),
            'environment'                   => array(
                'title'   => __('Environment', 'vodapay'),
                'type'    => 'select',
                'class'   => 'wc-enhanced-select',
                'options' => array(
                    'uat'  => __('Sandbox', 'vodapay'),
                    'live' => __('Live', 'vodapay'),
                ),
                'default' => 'uat',
            ),
            'uat_api_url'                   => array(
                'title'   => __('Sandbox API URL', 'vodapay'),
                'type'    => 'text',
                'default' => 'https://api-gateway.sandbox.vodapaygateway.vodacom.co.za',
            ),
            'live_api_url'                  => array(
                'title'   => __('Live API URL', 'vodapay'),
                'type'    => 'text',
                'default' => 'https://api-gateway.vodapaygateway.vodacom.co.za',
            ),
            'paymentAction'                 => array(
                'title'   => __('Payment Action', 'vodapay'),
                'type'    => 'select',
                'class'   => 'wc-enhanced-select',
                'options' => array(
                    'authorize' => __('Authorize', 'vodapay'),
                    'sale'      => __('Sale', 'vodapay'),
                    'purchase'  => __('Purchase', 'vodapay'),
                ),
                'default' => 'sale',
            ),
            'orderStatus'                   => array(
                'title'   => __('Status of new order', 'vodapay'),
                'type'    => 'select',
                'class'   => 'wc-enhanced-select',
                'options' => array(
                    'vodapay_pending' => __('VodaPay Pending', 'vodapay'),
                ),
                'default' => 'vodapay_pending',
            ),
            'default_complete_order_status' => array(
                'title'   => __("Set successful orders to 'Processing'", 'vodapay'),
                'type'    => 'checkbox',
                'default' => 'no',
            ),
            'outletRef'                     => array(
                'title' => __('Outlet Reference ID', 'vodapay'),
                'type'  => 'text',
            ),
            'outlet_override_currency'      => [
                'title'       => __('Outlet 2 Currencies (Optional)', 'vodapay'),
                'type'        => 'multiselect',
                'class'       => 'wc-enhanced-select',
                'options'     => self::$currencies,
                'label'       => __('Outlet 2 Currencies (Optional)', 'vodapay'),
                'description' => __(
                    'If these currencies are selected, Outlet 2 Reference ID will be used.',
                    'vodapay'
                ),
            ],
            'outlet_override_ref'           => array(
                'title' => __('Outlet 2 Reference ID (Optional)', 'vodapay'),
                'type'  => 'text',
            ),
            'apiKey'                        => array(
                'title' => __('API Key', 'vodapay'),
                'type'  => 'textarea',
                'css'   => 'width: 400px;height:50px;',
            ),
            'curl_http_version'             => array(
                'title'   => __('HTTP Version', 'vodapay'),
                'type'    => 'select',
                'class'   => 'wc-enhanced-select',
                'options' => array(
                    "CURL_HTTP_VERSION_NONE"              => __('None', 'vodapay'),
                    "CURL_HTTP_VERSION_1_0"               => __('1.0', 'vodapay'),
                    "CURL_HTTP_VERSION_1_1"               => __('1.1', 'vodapay'),
                    "CURL_HTTP_VERSION_2_0"               => __('2.0', 'vodapay'),
                    "CURL_HTTP_VERSION_2TLS"              => __('2 (TLS)', 'vodapay'),
                    "CURL_HTTP_VERSION_2_PRIOR_KNOWLEDGE" => __('2 (prior knowledge)', 'vodapay'),
                ),
                'default' => 'CURL_HTTP_VERSION_NONE',
            ),
            'customOrderFields'             => array(
                'title'       => __('Custom Order Meta', 'vodapay'),
                'type'        => 'text',
                'css'         => 'width: 400px;height: auto;',
                'desc_tip'    => true,
                'description' => __(
                    'Add order meta to the custom merchant fields using a meta key (e.g. _billing_first_name)',
                    'vodapay'
                ),
            ),
            'debug'                         => array(
                'title'       => __('Debug Log', 'vodapay'),
                'type'        => 'checkbox',
                'label'       => __('Enable logging', 'vodapay'),
                'description' => sprintf(
                /* translators: %s: log file path */
                    __('Log file will be %s', 'vodapay'),
                    '<code>' . WC_Log_Handler_File::get_log_file_path('vodapay') . '</code>'
                ),
                'default'     => 'yes',
            ),
            'debugMode'                     => array(
                'title'       => __('Cron Debug Mode', 'vodapay'),
                'type'        => 'checkbox',
                'label'       => __('Enable cron debug mode', 'vodapay'),
                'description' => __(
                    'Activate/deactivate cron debug mode',
                    'vodapay'
                ),
                'default'     => 'no',
            )
        );
    }
}
