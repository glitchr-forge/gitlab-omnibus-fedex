<?php

namespace Omnibus\Fedex;

use Omnibus\Config;
use Omnibus\Exception\InvalidConfigException;
use Omnibus\Fedex\Action\PickupAction;
use Omnibus\Fedex\Action\RatingAction;
use Omnibus\Fedex\Action\ShippingAction;
use Omnibus\Fedex\Action\TrackingAction;
use Omnibus\GatewayFactory;
use Symfony\Component\HttpClient\HttpClient;

/**
 *   options:
 *     client_id: '%env(FEDEX_CLIENT_ID)%'          # a project in the FedEx Developer Portal
 *     client_secret: '%env(FEDEX_CLIENT_SECRET)%'
 *     account_number: '%env(FEDEX_ACCOUNT)%'
 *     sandbox: true
 *     rates: [...]                                  # optional: configured prices instead of the Rate API
 */
final class FedexGatewayFactory extends GatewayFactory
{
    protected function populateConfig(Config $config): void
    {
        $config->defaults([
            'omnibus.factory_name' => 'fedex',
            'omnibus.factory_title' => 'FedEx',
            'omnibus.required_options' => ['client_id', 'client_secret', 'account_number'],
            'sandbox' => false,
            'omnibus.api' => function (Config $c) {
                $http = $this->http ?? (class_exists(HttpClient::class) ? HttpClient::create() : throw new InvalidConfigException('The "fedex" gateway needs symfony/http-client.'));

                return new Api($http, (string) $c['client_id'], (string) $c['client_secret'], (string) $c['account_number'], (bool) $c['sandbox']);
            },
            'omnibus.action.rating' => static fn (Config $c) => $c->get('rates') ? null : new RatingAction(),
            'omnibus.action.shipping' => new ShippingAction(),
            'omnibus.action.tracking' => new TrackingAction(),
            'omnibus.action.pickup' => new PickupAction(),
        ]);
    }
}
