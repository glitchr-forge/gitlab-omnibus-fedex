# omnibus/fedex

FedEx for [glitchr/omnibus](https://github.com/glitchr-studio/omnibus): rate quotes (Rate API),
shipments and labels (Ship API), tracking (Track API) and drop-off locations (Location API) - the
REST APIs with OAuth2 client credentials.

```yaml
omnibus:
    gateways:
        fedex:
            factory: fedex
            options:
                client_id: '%env(FEDEX_CLIENT_ID)%'
                client_secret: '%env(FEDEX_CLIENT_SECRET)%'
                account_number: '%env(FEDEX_ACCOUNT)%'
                sandbox: true
                rates: [...]        # optional: configured prices instead of the Rate API
```

Shipment options: `label_format` (PDF, PNG, ZPLII), `pickup_type` (DROPOFF_AT_FEDEX_LOCATION,
CONTACT_FEDEX_TO_SCHEDULE, USE_SCHEDULED_PICKUP). The service is FedEx's code (FEDEX_INTERNATIONAL_PRIORITY,
INTERNATIONAL_ECONOMY, FEDEX_GROUND...).

Credentials: a project in the [FedEx Developer Portal](https://developer.fedex.com) with the Rate,
Ship, Track and Location APIs, its client id and secret (test and production are separate), and
your FedEx account number.

Built from FedEx's published API documentation and tested on recorded answers; not yet run against
the sandbox: that needs the credentials above.

License: LGPL-3.0-or-later.
