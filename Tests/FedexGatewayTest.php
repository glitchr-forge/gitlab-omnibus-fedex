<?php

namespace Omnibus\Fedex\Tests;

use Omnibus\Fedex\FedexGatewayFactory;
use Omnibus\Model\TrackingStatus;
use Omnibus\Tests\Fixtures;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class FedexGatewayTest extends TestCase
{
    private array $calls = [];

    private function gateway(): \Omnibus\GatewayInterface
    {
        $http = new MockHttpClient(function (string $method, string $url, array $options): MockResponse {
            self::assertStringStartsWith('https://apis-sandbox.fedex.com', $url);
            $path = (string) parse_url($url, \PHP_URL_PATH);
            $this->calls[] = [$method, $path, \is_string($options['body'] ?? null) && str_starts_with($options['body'], '{') ? json_decode($options['body'], true) : []];

            return match (true) {
                '/oauth/token' === $path => new MockResponse(json_encode(['access_token' => 'tok', 'expires_in' => 3599])),
                '/rate/v1/rates/quotes' === $path => new MockResponse(json_encode(['output' => ['rateReplyDetails' => [
                    ['serviceType' => 'INTERNATIONAL_ECONOMY', 'serviceName' => 'FedEx International Economy', 'ratedShipmentDetails' => [['totalNetCharge' => 31.2, 'currency' => 'EUR']], 'operationalDetail' => ['transitTime' => 'THREE_DAYS']],
                    ['serviceType' => 'FEDEX_INTERNATIONAL_PRIORITY', 'serviceName' => 'FedEx International Priority', 'ratedShipmentDetails' => [['totalNetCharge' => 48.5, 'currency' => 'EUR']], 'operationalDetail' => ['transitTime' => 'ONE_DAY']],
                ]]])),
                '/ship/v1/shipments' === $path => new MockResponse(json_encode(['output' => ['transactionShipments' => [['masterTrackingNumber' => '794644790000', 'pieceResponses' => [['trackingNumber' => '794644790000', 'packageDocuments' => [['contentType' => 'LABEL', 'encodedLabel' => base64_encode('%PDF-1.4')]]]]]]]])),
                '/track/v1/trackingnumbers' === $path => new MockResponse(json_encode(['output' => ['completeTrackResults' => [['trackResults' => [['latestStatusDetail' => ['code' => 'IT', 'description' => 'In transit'], 'scanEvents' => [
                    ['date' => '2026-10-02T08:00:00+02:00', 'eventType' => 'IT', 'eventDescription' => 'In transit', 'scanLocation' => ['city' => 'ROISSY', 'countryCode' => 'FR']],
                    ['date' => '2026-10-01T18:00:00+02:00', 'eventType' => 'PU', 'eventDescription' => 'Picked up', 'scanLocation' => ['city' => 'STRASBOURG', 'countryCode' => 'FR']],
                ]]]]]]])),
                '/location/v1/locations' === $path => new MockResponse(json_encode(['output' => ['locationDetailList' => [['locationId' => 'STRA', 'locationType' => 'FEDEX_ONSITE', 'contactAndAddress' => ['contact' => ['companyName' => 'PRESSE DU CENTRE'], 'address' => ['streetLines' => ['5 RUE DU DOME'], 'city' => 'STRASBOURG', 'postalCode' => '67000', 'countryCode' => 'FR']], 'distance' => ['value' => 0.8, 'units' => 'KM'], 'normalHours' => [['dayOfWeek' => 'MON', 'operationalHours' => [['begins' => '09:00', 'ends' => '19:00']]]]]]]])),
                default => new MockResponse(json_encode(['errors' => [['code' => 'NOT.FOUND', 'message' => 'No such resource '.$path]]]), ['http_code' => 404]),
            };
        });

        return (new FedexGatewayFactory($http))->create(['client_id' => 'id', 'client_secret' => 'secret', 'account_number' => '510087', 'sandbox' => true]);
    }

    public function testRatesComeCheapestFirstWithTransitDays(): void
    {
        $rates = $this->gateway()->rate(Fixtures::shipment());
        self::assertSame(['INTERNATIONAL_ECONOMY', 'FEDEX_INTERNATIONAL_PRIORITY'], array_map(fn ($r) => $r->service, $rates));
        self::assertSame(3120, $rates[0]->amount);
        self::assertSame(3, $rates[0]->days);
        self::assertSame(1, $rates[1]->days);
        self::assertSame('510087', $this->calls[1][2]['accountNumber']['value']);
    }

    public function testAShipmentIsBookedWithItsPdfLabel(): void
    {
        $label = $this->gateway()->ship(Fixtures::shipment());
        self::assertSame('794644790000', $label->trackingNumber);
        self::assertSame('%PDF-1.4', $label->content);
        self::assertSame('application/pdf', $label->format);
        self::assertSame('PDF', $this->calls[1][2]['requestedShipment']['labelSpecification']['imageType']);
    }

    public function testTrackingOrdersTheScansOldestFirst(): void
    {
        $tracking = $this->gateway()->track('794644790000');
        self::assertSame(TrackingStatus::IN_TRANSIT, $tracking->status);
        self::assertSame('Picked up', $tracking->events[0]->description);
        self::assertSame('ROISSY FR', $tracking->latest()->location);
    }

    public function testLocationsAreFoundNearAnAddress(): void
    {
        $points = $this->gateway()->pickupPoints(Fixtures::shipment()->recipient);
        self::assertSame('STRA', $points[0]->id);
        self::assertSame('PRESSE DU CENTRE', $points[0]->name);
        self::assertSame(800, $points[0]->distance);
        self::assertSame([['09:00', '19:00']], $points[0]->openingHours[1]);
    }
}
