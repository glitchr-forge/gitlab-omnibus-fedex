<?php

namespace Omnibus\Fedex\Action;

use Omnibus\Action\ActionInterface;
use Omnibus\Action\ApiAwareInterface;
use Omnibus\Action\ApiAwareTrait;
use Omnibus\Exception\CarrierException;
use Omnibus\Fedex\Api;
use Omnibus\Fedex\Mapping;
use Omnibus\Model\Label;
use Omnibus\Request\Request;
use Omnibus\Request\Shipping;

/** The Ship API: the shipment booked, its label (PDF 4x6; option label_format: PDF, PNG or ZPLII). */
final class ShippingAction implements ActionInterface, ApiAwareInterface
{
    /** @use ApiAwareTrait<Api> */
    use ApiAwareTrait;

    public function __construct()
    {
        $this->apiClass = Api::class;
    }

    public function supports(Request $request): bool
    {
        return $request instanceof Shipping;
    }

    public function execute(Request $request): void
    {
        \assert($request instanceof Shipping);
        $s = $request->shipment;
        $format = strtoupper((string) $s->option('label_format', 'PDF'));
        $data = $this->api->call('POST', '/ship/v1/shipments', [
            'labelResponseOptions' => 'LABEL',
            'accountNumber' => ['value' => $this->api->accountNumber],
            'requestedShipment' => array_filter([
                'shipper' => Mapping::party($s->sender),
                'recipients' => [Mapping::party($s->recipient)],
                'shipDatestamp' => ($s->shippingDate ?? new \DateTimeImmutable())->format('Y-m-d'),
                'serviceType' => $s->service ?? 'FEDEX_INTERNATIONAL_PRIORITY',
                'packagingType' => 'YOUR_PACKAGING',
                'pickupType' => $s->option('pickup_type', 'DROPOFF_AT_FEDEX_LOCATION'),
                'shippingChargesPayment' => ['paymentType' => 'SENDER'],
                'labelSpecification' => ['imageType' => $format, 'labelStockType' => 'PAPER_4X6'],
                'customerReferences' => $s->reference ? [['customerReferenceType' => 'CUSTOMER_REFERENCE', 'value' => mb_substr($s->reference, 0, 40)]] : null,
                'requestedPackageLineItems' => array_map([Mapping::class, 'package'], $s->parcels),
            ]),
        ]);
        $shipment = $data['output']['transactionShipments'][0] ?? [];
        $piece = $shipment['pieceResponses'][0] ?? [];
        $number = (string) ($shipment['masterTrackingNumber'] ?? $piece['trackingNumber'] ?? '');
        if ('' === $number) {
            throw new CarrierException('fedex', 'FedEx booked no shipment.');
        }
        $document = $piece['packageDocuments'][0] ?? [];
        $encoded = $document['encodedLabel'] ?? null;

        $request->setResult(new Label('fedex', $number, \is_string($encoded) ? base64_decode($encoded) : null,
            'ZPLII' === $format ? Label::ZPL : ('PNG' === $format ? 'image/png' : Label::PDF),
            $document['url'] ?? null, 'https://www.fedex.com/fedextrack/?trknbr='.rawurlencode($number)));
    }
}
