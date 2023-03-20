<?php

namespace Omnibus\Fedex;

use Omnibus\Core\GatewayFactory;
use Omnibus\Fedex\Action\ShippingAction;
use Omnibus\Fedex\Action\TrackingAction;
use Omnibus\Fedex\Action\PackingSlipAction;

class FedexGatewayFactory extends GatewayFactory
{
    protected function populateConfig(ArrayObject $config)
    {
        $config->defaults([
            'omnibus.factory_name' => 'fedex',
            'omnibus.factory_title' => 'Fedex'
        ]);
    }
}