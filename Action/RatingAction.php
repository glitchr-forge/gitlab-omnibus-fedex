<?php

namespace Omnibus\Fedex\Action;

use Omnibus\Action\ActionInterface;
use Omnibus\Action\ApiAwareInterface;
use Omnibus\Action\ApiAwareTrait;
use Omnibus\Fedex\Api;
use Omnibus\Fedex\Mapping;
use Omnibus\Model\Rate;
use Omnibus\Request\Rating;
use Omnibus\Request\Request;

/** The Rate API's quotes: every service, with the account's rates. */
final class RatingAction implements ActionInterface, ApiAwareInterface
{
    /** @use ApiAwareTrait<Api> */
    use ApiAwareTrait;

    public function __construct()
    {
        $this->apiClass = Api::class;
    }

    public function supports(Request $request): bool
    {
        return $request instanceof Rating;
    }

    public function execute(Request $request): void
    {
        \assert($request instanceof Rating);
        $s = $request->shipment;
        $data = $this->api->call('POST', '/rate/v1/rates/quotes', [
            'accountNumber' => ['value' => $this->api->accountNumber],
            'rateRequestControlParameters' => ['returnTransitTimes' => true],
            'requestedShipment' => [
                'shipper' => ['address' => Mapping::address($s->sender)],
                'recipient' => ['address' => Mapping::address($s->recipient)],
                'pickupType' => $s->option('pickup_type', 'DROPOFF_AT_FEDEX_LOCATION'),
                'rateRequestType' => ['ACCOUNT'],
                'requestedPackageLineItems' => array_map([Mapping::class, 'package'], $s->parcels),
            ],
        ]);
        $rates = [];
        foreach ($data['output']['rateReplyDetails'] ?? [] as $detail) {
            $rated = $detail['ratedShipmentDetails'][0] ?? [];
            $days = $detail['operationalDetail']['transitTime'] ?? null;
            $rates[] = new Rate('fedex', (string) ($detail['serviceType'] ?? ''), (string) ($detail['serviceName'] ?? $detail['serviceType'] ?? 'FedEx'),
                (int) round(((float) ($rated['totalNetCharge'] ?? $rated['totalNetFedExCharge'] ?? 0)) * 100), strtoupper((string) ($rated['currency'] ?? 'EUR')),
                \is_string($days) && preg_match('/^(\w+)_DAYS?$/', $days, $m) ? (['ONE' => 1, 'TWO' => 2, 'THREE' => 3, 'FOUR' => 4, 'FIVE' => 5, 'SIX' => 6, 'SEVEN' => 7][$m[1]] ?? null) : null);
        }
        usort($rates, static fn (Rate $a, Rate $b) => $a->amount <=> $b->amount);
        $request->setResult($rates);
    }
}
