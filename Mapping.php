<?php

namespace Omnibus\Fedex;

use Omnibus\Model\Address;
use Omnibus\Model\Parcel;
use Omnibus\Model\TrackingStatus;

/** FedEx's shapes for ours. */
final class Mapping
{
    public static function party(Address $a): array
    {
        return array_filter([
            'contact' => array_filter(['personName' => mb_substr($a->name, 0, 70), 'companyName' => $a->company ? mb_substr($a->company, 0, 70) : null, 'phoneNumber' => $a->phone ? preg_replace('/\D+/', '', $a->phone) : null, 'emailAddress' => $a->email]),
            'address' => self::address($a),
        ]);
    }

    public static function address(Address $a): array
    {
        return array_filter(['streetLines' => array_values(array_filter($a->street)), 'city' => $a->city, 'postalCode' => $a->postcode, 'countryCode' => strtoupper($a->country)]);
    }

    public static function package(Parcel $p): array
    {
        $item = ['weight' => ['units' => 'KG', 'value' => round(max(0.1, $p->weight / 1000), 2)]];
        if ($p->length && $p->width && $p->height) {
            $item['dimensions'] = ['length' => $p->length, 'width' => $p->width, 'height' => $p->height, 'units' => 'CM'];
        }

        return $item;
    }

    public static function status(?string $code): TrackingStatus
    {
        return match (strtoupper((string) $code)) {
            'DL' => TrackingStatus::DELIVERED,
            'OD' => TrackingStatus::OUT_FOR_DELIVERY,
            'HL' => TrackingStatus::AVAILABLE_FOR_PICKUP,
            'IT', 'PU', 'AR', 'DP', 'IX', 'AF', 'OC' => TrackingStatus::IN_TRANSIT,
            'DE', 'SE', 'CA' => TrackingStatus::EXCEPTION,
            'RS' => TrackingStatus::RETURNED,
            'IN', 'PX' => TrackingStatus::PENDING,
            default => TrackingStatus::UNKNOWN,
        };
    }
}
