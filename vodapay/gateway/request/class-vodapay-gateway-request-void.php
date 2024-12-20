<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class VodaPayGatewayRequestVoid
 */
class VodaPayGatewayRequestVoid
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
     * Builds ENV void request
     *
     * @param object $order_item
     *
     * @return array|null
     */
    public function build($order_item)
    {
        return [
            'token'   => $this->config->get_token(),
            'request' => [
                'data'   => [],
                'method' => 'PUT',
                'uri'    => $this->config->get_order_void_url($order_item->reference, $order_item->payment_id),
            ],
        ];
    }
}
