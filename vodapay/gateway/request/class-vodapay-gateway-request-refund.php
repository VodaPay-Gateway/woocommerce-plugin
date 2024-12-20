<?php

if (!defined('ABSPATH')) {
    exit;
}

use Ngenius\NgeniusCommon\Formatter\ValueFormatter;

/**
 * Class VodaPayGatewayRequestRefund
 */
class VodaPayGatewayRequestRefund
{
    /**
     * @var Config
     */
    protected $config;

    /**
     * Constructor
     *
     * @param VodaPayGatewayConfig $config
     */
    public function __construct(VodaPayGatewayConfig $config)
    {
        $this->config = $config;
    }

    /**
     * Builds ENV refund request
     *
     * @param object $order_item
     * @param float $amount
     *
     * @return array|null
     */
    public function build($order_item, $amount, $url)
    {
        $currencyCode = $order_item->currency;

        $amount = ValueFormatter::floatToIntRepresentation($currencyCode, $amount);

        return [
            'token'   => $this->config->get_token(),
            'request' => [
                'data'   => [
                    'amount'              => [
                        'currencyCode' => $currencyCode,
                        'value'        => $amount,
                    ],
                    'merchantDefinedData' => [
                        'pluginName'    => 'woocommerce',
                        'pluginVersion' => WC_GATEWAY_VODAPAY_VERSION
                    ]
                ],
                'method' => 'POST',
                'uri'    => $url,
            ],
        ];
    }
}
