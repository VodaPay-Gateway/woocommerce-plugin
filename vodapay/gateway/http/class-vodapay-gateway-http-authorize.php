<?php

/**
 * VodaPayGatewayHttpAuthorize class.
 */
class VodaPayGatewayHttpAuthorize extends VodaPayGatewayHttpAbstract
{
    /**
     * Processing of API request body
     *
     * @param array $data
     *
     * @return string
     */
    protected function pre_process(array $data): string
    {
        return wp_json_encode($data);
    }
}
