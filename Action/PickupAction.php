<?php

namespace Omnibus\Fedex\Action;

use Omnibus\Action\ActionInterface;
use Omnibus\Action\ApiAwareInterface;
use Omnibus\Action\ApiAwareTrait;
use Omnibus\Fedex\Api;
use Omnibus\Fedex\Mapping;
use Omnibus\Model\Address;
use Omnibus\Model\PickupPoint;
use Omnibus\Request\Pickup;
use Omnibus\Request\Request;

/** The Location API: FedEx locations that take parcels, nearest first. */
final class PickupAction implements ActionInterface, ApiAwareInterface
{
    /** @use ApiAwareTrait<Api> */
    use ApiAwareTrait;

    public function __construct()
    {
        $this->apiClass = Api::class;
    }

    public function supports(Request $request): bool
    {
        return $request instanceof Pickup;
    }

    public function execute(Request $request): void
    {
        \assert($request instanceof Pickup);
        $data = $this->api->call('POST', '/location/v1/locations', [
            'locationsSummaryRequestControlParameters' => ['distance' => ['units' => 'KM', 'value' => 25], 'maxResults' => min(50, max(1, $request->limit))],
            'locationSearchCriterion' => 'ADDRESS',
            'location' => ['address' => Mapping::address($request->near)],
            'locationCapabilities' => [['capabilityType' => 'DROPOFF_SERVICE']],
        ]);
        $points = [];
        foreach ($data['output']['locationDetailList'] ?? [] as $location) {
            $address = $location['contactAndAddress']['address'] ?? [];
            $contact = $location['contactAndAddress']['contact'] ?? [];
            $hours = [];
            foreach ($location['normalHours'] ?? [] as $day) {
                $n = array_search(strtoupper((string) ($day['dayOfWeek'] ?? '')), ['', 'MON', 'TUE', 'WED', 'THU', 'FRI', 'SAT', 'SUN'], true);
                if ($n) {
                    $hours[$n] = array_map(static fn ($h) => [$h['begins'] ?? '', $h['ends'] ?? ''], $day['operationalHours'] ?? []);
                }
            }
            $points[] = new PickupPoint('fedex', (string) ($location['locationId'] ?? ''), (string) ($contact['companyName'] ?? $location['locationType'] ?? 'FedEx'),
                new Address((string) ($contact['companyName'] ?? ''), array_values((array) ($address['streetLines'] ?? [])), (string) ($address['postalCode'] ?? ''), (string) ($address['city'] ?? ''), (string) ($address['countryCode'] ?? $request->near->country)),
                isset($location['geoPositionalCoordinates']['latitude']) ? (float) $location['geoPositionalCoordinates']['latitude'] : null, isset($location['geoPositionalCoordinates']['longitude']) ? (float) $location['geoPositionalCoordinates']['longitude'] : null,
                $hours, isset($location['distance']['value']) ? (int) round(((float) $location['distance']['value']) * 1000) : null);
        }
        $request->setResult(\array_slice($points, 0, $request->limit));
    }
}
