<?php

namespace Omnibus\Fedex\Action;

use Omnibus\Action\ActionInterface;
use Omnibus\Action\ApiAwareInterface;
use Omnibus\Action\ApiAwareTrait;
use Omnibus\Fedex\Api;
use Omnibus\Fedex\Mapping;
use Omnibus\Model\Tracking as TrackingModel;
use Omnibus\Model\TrackingEvent;
use Omnibus\Model\TrackingStatus;
use Omnibus\Request\Request;
use Omnibus\Request\Tracking;

/** The Track API: the parcel's scan events, oldest first. */
final class TrackingAction implements ActionInterface, ApiAwareInterface
{
    /** @use ApiAwareTrait<Api> */
    use ApiAwareTrait;

    public function __construct()
    {
        $this->apiClass = Api::class;
    }

    public function supports(Request $request): bool
    {
        return $request instanceof Tracking;
    }

    public function execute(Request $request): void
    {
        \assert($request instanceof Tracking);
        $data = $this->api->call('POST', '/track/v1/trackingnumbers', ['includeDetailedScans' => true, 'trackingInfo' => [['trackingNumberInfo' => ['trackingNumber' => $request->trackingNumber]]]], str_replace('-', '_', $request->locale).(str_contains($request->locale, '_') || str_contains($request->locale, '-') ? '' : '_'.strtoupper($request->locale)));
        $result = $data['output']['completeTrackResults'][0]['trackResults'][0] ?? [];
        if (!empty($result['error'])) {
            $request->setResult(new TrackingModel('fedex', $request->trackingNumber, TrackingStatus::UNKNOWN));

            return;
        }
        $events = [];
        foreach ($result['scanEvents'] ?? [] as $scan) {
            $events[] = new TrackingEvent(new \DateTimeImmutable((string) ($scan['date'] ?? 'now')), Mapping::status($scan['eventType'] ?? null), (string) ($scan['eventDescription'] ?? ''), trim(implode(' ', array_filter([$scan['scanLocation']['city'] ?? null, $scan['scanLocation']['countryCode'] ?? null]))) ?: null, $scan['eventType'] ?? null);
        }
        usort($events, static fn (TrackingEvent $a, TrackingEvent $b) => $a->at <=> $b->at);
        $status = Mapping::status($result['latestStatusDetail']['code'] ?? ($events ? $events[array_key_last($events)]->code : null));
        $request->setResult(new TrackingModel('fedex', $request->trackingNumber, $status, $events));
    }
}
