<?php

namespace DHLParcel\Shipping\Model\Cache;

class Api extends \Magento\Framework\Cache\Frontend\Decorator\TagScope
{
    const TYPE_IDENTIFIER = 'dhlparcel_shipping';
    const CACHE_TAG = 'DHLPARCEL_SHIPPING';

    public function __construct(\Magento\Framework\App\Cache\Type\FrontendPool $cacheFrontendPool)
    {
        parent::__construct($cacheFrontendPool->get(self::TYPE_IDENTIFIER), self::CACHE_TAG);
    }

    public function createKey($method, $params = [])
    {
        foreach ($params as $key => $param) {
            $params[$key] = base64_encode($param);
        }
        return $method . ':' . implode('_', $params);
    }
}
