# Tinylist_Dropshipping (Magento 2)

Magento 2 connector module for the Tinylist dropshipping platform — the
Magento 2 counterpart of [`module-m1`](../module-m1). It bundles four things:

1. **Integration** — a pre-declared `Tinylist Connector` integration with a
   curated, read-focused resource set.
2. **Payment method** — an offline `Tinylist Dropshipping` method, admin-only.
3. **Invoice endpoint** — an order's invoice as a base64 PDF, plus metadata.
4. **Carrier tracking endpoint** — resolve an `(order, tracking)` pair to a live
   carrier status. Sameday, DPD Romania and FAN Courier; pluggable for more.

Target platform: **Magento 2.4.6 – 2.4.8**, PHP 8.1 – 8.4.

---

## Install

```bash
# from the Magento root, as a path/VCS composer repository
composer require tinylist/module-dropshipping

# or drop the checkout in place
mkdir -p app/code/Tinylist && cp -R /path/to/module-m2 app/code/Tinylist/Dropshipping

bin/magento module:enable Tinylist_Dropshipping
bin/magento setup:upgrade
bin/magento setup:di:compile          # required: the module uses generated factories
bin/magento cache:flush
```

There is no install/upgrade **data** script and no schema — nothing to roll back
on uninstall.

---

## What changed from Magento 1

Most of the Magento 1 module's plumbing has no Magento 2 equivalent, because
Magento 2 solved the same problems differently.

| Magento 1 | Magento 2 | Why |
| --- | --- | --- |
| `etc/api.xml`, `etc/wsdl.xml`, `etc/wsi.xml` | `etc/webapi.xml` only | One route declaration serves REST *and* SOAP. Magento generates the WSDL from the service interfaces' return types, so there is nothing to hand-maintain and no WS-I variant. |
| `sql/…/install-1.0.0.php` creating a SOAP/XML-RPC role | `etc/integration/config.xml` + `etc/integration/api.xml` | Magento 2 has no API roles. Integrations are the replacement, and they issue the bearer token Tinylist already expects. |
| 90-entry ACL resource list | 11 resources | Magento 2's ACL is coarser, and the cart resources are gone entirely — see below. |
| `modman` | `composer.json` + `registration.php` | Standard Magento 2 packaging. |
| `Helper/Data.php` (existed only to reach `__()`) | — | `Magento\Framework\Phrase`. |
| Provider registry in `config.xml` | `etc/di.xml` | Constructor-injected pool; no XML-node walking at runtime. |
| `Mage_Payment_Model_Method_Abstract` subclass | `Magento\Payment\Model\Method\Adapter` virtual types | `AbstractMethod` is deprecated and flagged by Adobe's extension quality checks. |
| Tracking status returned as a **JSON string** | a typed object | Magento 2 serializes the return type itself, so a REST caller gets a real JSON object rather than a string containing JSON, and SOAP gets a typed struct. Field names are unchanged from Magento 1, so a client can parse both with the same code. |

**No cart resources.** The Magento 1 role granted the full `cart/*` flow. Tinylist
places Magento 2 orders through the *customer*-token cart flow
(`POST /V1/integration/customer/token`, then `/V1/carts/mine/...`), which
authenticates as the B2B buyer account. The integration token is never used for
placement, so those grants would be dead weight.

**Two behavioural corrections** carried into this port:

- The Magento 1 `Dpd` and `Fancourier` providers were dead code — only `sameday`
  was ever registered in `config.xml`, so they were never instantiated and never
  appeared in the Enabled Carriers switch. All three are registered here.
- The Magento 1 DPD provider disabled TLS certificate verification
  (`CURLOPT_SSL_VERIFYHOST`/`VERIFYPEER` false). That is not carried over.

---

## 1. Integration

`etc/integration/config.xml` pre-declares an integration named
**Tinylist Connector**; `etc/integration/api.xml` lists the resources it asks
for. After `setup:upgrade` it appears under **System → Extensions →
Integrations**, already scoped. Click **Activate** → **Allow**, then copy the
**Access Token** into Tinylist's merchant settings (it becomes
`merchants.api_key_encrypted`, sent as `Authorization: Bearer <token>`).

| Group | Granted |
| --- | --- |
| Catalog | `catalog`, `catalog_inventory`, `products`, `attributes_attributes`, `sets` |
| Sales (read) | `sales`, `actions_view`, `shipment`, `sales_invoice` |
| This module | `Tinylist_Dropshipping::invoice`, `Tinylist_Dropshipping::tracking` |

Write operations are deliberately not requested.

Pre-declaring an integration in XML is a convenience, not a requirement — and
Adobe has marked the `integration/config.xsd` schema deprecated. An integration
created by hand under the same name with the same resources behaves identically,
so if a future release drops XML-declared integrations, only this convenience is
lost, not the module.

---

## 2. Payment method — "Tinylist Dropshipping"

- **Disabled by default** (`payment/tinylist_dropshipping/active = 0`).
- When enabled: offered to **admins** creating orders
  (`can_use_internal = 1`), **hidden on the storefront** (`can_use_checkout = 0`)
  and kept out of multishipping.
- **New Order Status**: a config dropdown of *processing*-state statuses. Placed
  orders go straight into that status.

Configure under **Stores → Configuration → Sales → Payment Methods → Tinylist
Dropshipping** (Enabled / Title / New Order Status / Sort Order).

The method is assembled from `Magento\Payment\Model\Method\Adapter` virtual
types in `etc/di.xml` rather than a method class. Its behaviour comes from
config, and its one side effect from `Model/Payment/Command/Initialize.php`:
because `can_initialize` is `1`, the Adapter runs that command during
`place()`, which sets the order's state and status.

There is no `view/` directory. Admin order-create renders offline methods
generically, and the method never reaches the storefront, so no Knockout
component or checkout renderer is needed.

---

## 3. Carrier tracking status

**Stores → Configuration → General → Tinylist Dropshipping → Carrier Tracking
Status → Enabled Carriers** — a multiselect over the registered providers. All
three are enabled by default.

Enabling all three is safe because a provider is only ever contacted for a
tracking number it has already matched: a store without DPD installed has no
`dpdro_order_shipment` rows and no `dpdro_shipping` tracks, so the DPD provider
never claims anything. Deselect a carrier when you want it explicitly *refused*
(`carrier_not_enabled`) rather than silently unmatched.

Given an `(orderIncrementId, trackNumber)` pair, `Model/Carrier/StatusResolver.php`:

1. loads the order by increment id,
2. asks each enabled provider whether the pair is its own — via Magento shipment
   tracks, or the carrier module's own table,
3. calls that carrier's API and returns a normalized envelope.

If no enabled provider claims the pair, the *disabled* ones get a look too, so a
shipment on a switched-off carrier reports `carrier_not_enabled` instead of the
misleading `tracking_not_found`.

### Providers

| Provider | Magento 2 module | Carrier code | Credentials | Verified |
| --- | --- | --- | --- | --- |
| Sameday | `SamedayCourier_Shipping` | `samedaycourier` | `carriers/samedaycourier/{username,password,testing}` | ✅ against [sameday-courier/magento2-plugin](https://github.com/sameday-courier/magento2-plugin) |
| DPD Romania | `DpdRo_Shipping` | `dpdro_shipping` | `dpdro_settings` table (`name`/`value` rows) | ✅ against [DPDromania/magento2-shipping](https://github.com/DPDromania/magento2-shipping) |
| FAN Courier | `FANCourier_Customshipping` | `fancourier` | `carriers/fancourier/{client_id,username,password}` | ⚠️ **unverified** |

Three differences from the Magento 1 providers, all forced by the Magento 2
carrier modules being different software from the Blugento ones:

- **Sameday** uses one carrier code (`samedaycourier`) rather than Magento 1's
  `bgsamedaycourier` + `bgsamedayeasybox` — lockers are a shipping method within
  the single carrier here. Its password is stored **encrypted**, so it goes
  through `EncryptorInterface`. It also runs per-country stacks (RO/HU/BG); the
  host map and country are `di.xml` arguments, defaulting to RO.
- **DPD** stores its shipment id in `dpdro_order_shipment` (`orderID` /
  `shipmentID`) — Magento 1's table and columns were named differently. Its
  credentials are read straight from `dpdro_settings` rather than through the DPD
  module's own model, so this module has no hard class dependency on an
  extension that may not be installed.
- **FAN Courier** has no shared token cache to borrow (the Magento 1 provider
  reused the Blugento module's), so the bearer token is cached in Magento's own
  cache for an hour.

Every table read is guarded by an `isTableExists()` check, so a store without a
given carrier module never hits a SQL error.

### ⚠️ FAN Courier needs verification

FAN Courier distributes its Magento 2 module as a download from
[fancourier.ro](https://www.fancourier.ro/en/ecommerce/) rather than a public
repository, so its module name, carrier code and config paths **could not be
checked against source**. The values in `etc/di.xml` are best-effort.

Nothing about that is hard-coded: the module name, carrier codes, API URL and
all three config paths are `di.xml` arguments. Correcting them against a real
store is an XML change with no code involved:

```xml
<type name="Tinylist\Dropshipping\Model\Carrier\Provider\Fancourier">
    <arguments>
        <argument name="moduleName" xsi:type="string">TheRealModule_Name</argument>
        <argument name="usernamePath" xsi:type="string">carriers/real_path/username</argument>
    </arguments>
</type>
```

To find the real values on a store that runs it:

```bash
bin/magento module:status | grep -i fan
bin/magento config:show | grep -i fan
mysql -e "SELECT DISTINCT carrier_code FROM sales_shipment_track"
```

### Adding the next carrier

1. Implement `Model\Carrier\ProviderInterface` — normally by extending
   `Model\Carrier\Provider\AbstractProvider` and implementing `fetch()`.
2. Add a `<type>` block for it in `etc/di.xml` and register it in the
   `ProviderPool` `providers` argument.

It then appears in the Enabled Carriers switch automatically. No changes to the
resolver, the service, the Web API or the ACL.

---

## API

Both endpoints are on the REST API under `/rest/V1/` and on the generated SOAP
WSDL. Authenticate with the integration access token:

```
Authorization: Bearer <access token>
```

### `GET /rest/V1/tinylist/orders/{orderIncrementId}/invoice`

Return an order's invoice as a PDF.

**ACL resource** `Tinylist_Dropshipping::invoice`

All of the order's invoices are rendered into one document, matching the admin's
own "Print Invoices" behaviour; the metadata describes the most recent one.

The render runs inside a store emulation of the order's store. A Web API request
executes in the `webapi_rest` area, which has no design scope or locale
configured — without emulation the PDF comes out in the wrong locale, or the
render fails outright.

**Response**

```json
{
  "available": true,
  "pdf_base64": "JVBERi0xLjQK…",
  "invoice_number": "100000045",
  "issued_at": "2026-08-02 09:14:31",
  "currency": "RON",
  "source": "magento"
}
```

| Field | Type | Notes |
| --- | --- | --- |
| `available` | bool | `false` means the order exists but has no invoice yet — poll again. |
| `pdf_base64` | string \| null | Decode and write to a `.pdf`. Null when `available` is false. |
| `invoice_number` | string \| null | Increment id of the most recent invoice. |
| `issued_at` | string \| null | That invoice's `created_at`, UTC `Y-m-d H:i:s`. |
| `currency` | string \| null | Order currency code. |
| `source` | string \| null | Always `magento`. |

**Errors** — `404` when no order has that increment id; `400` when the PDF
cannot be rendered (details are logged server-side).

### `GET /rest/V1/tinylist/orders/{orderIncrementId}/tracking/{trackNumber}`

Fetch the live carrier status for an `(order, tracking)` pair.

**ACL resource** `Tinylist_Dropshipping::tracking`

This endpoint does **not** signal expected outcomes with HTTP errors. Unknown
order or tracking number, a switched-off carrier, a carrier module that is
missing or unconfigured, an unreachable carrier API — all return `200` with
`success: false`, so a polling caller can tell "nothing yet" from "the request
itself failed".

**Success**

```json
{
  "success": true,
  "order_increment_id": "100000123",
  "track_number": "2ROBG1234567",
  "carrier_code": "samedaycourier",
  "carrier": "sameday",
  "status": {
    "code": "5",
    "status": "in_transit",
    "label": "AWB in transit",
    "state": "…",
    "date": "2026-08-08 14:21:00",
    "county": "Cluj",
    "reason": null,
    "transit_location": "Cluj-Napoca"
  },
  "delivered": false,
  "canceled": false,
  "delivery_attempts": 0,
  "delivered_at": null,
  "history": [ { "…": "same shape as status" } ]
}
```

**Failure**

```json
{
  "success": false,
  "order_increment_id": "100000123",
  "track_number": "BADAWB",
  "error": "The Sameday Courier shipping module is not installed or is disabled on this store.",
  "error_code": "carrier_module_unavailable",
  "history": []
}
```

| Field | Type | Notes |
| --- | --- | --- |
| `success` | bool | `true` on a resolved status. |
| `order_increment_id`, `track_number` | string | Echoed back. |
| `carrier_code` | string \| null | Magento carrier code the number matched on. |
| `carrier` | string \| null | Provider key, e.g. `sameday`. |
| `status` | object \| null | Current status; same shape as a history entry. |
| `delivered` | bool | The carrier's own flag — the authoritative delivery signal, not the history text. |
| `canceled` | bool | Canceled or returned. |
| `delivery_attempts` | int \| null | When the carrier reports it. |
| `delivered_at` | string \| null | Carrier's delivery timestamp. |
| `history` | array | Chronological, oldest first. Each entry has `code, status, label, state, date, county, reason, transit_location`. |
| `error`, `error_code` | string \| null | Failure only. |

Each carrier's native status code is passed through untranslated, so `code` is
only meaningful within one carrier. Classify on the `state`/`status`/`label`
text, or on `delivered`/`canceled`.

**`error_code` values**

| Code | Meaning |
| --- | --- |
| `missing_parameters` | Order number or tracking number was empty. |
| `order_not_found` | No order with that increment id. |
| `tracking_not_found` | That tracking number is on none of the order's shipments. |
| `carrier_not_enabled` | The order's carrier is not in the Enabled Carriers switch. |
| `carrier_module_unavailable` | The carrier's shipping module is missing or disabled. |
| `carrier_not_configured` | The carrier module has no API credentials configured. |
| `carrier_auth_failed` | Authentication with the carrier API failed. |
| `carrier_unreachable` | Network/transport error reaching the carrier API. |
| `carrier_bad_response` | The carrier answered with something unparseable. |
| `carrier_status_unavailable` | The carrier has no status for that AWB yet. |
| `unexpected_error` | Anything else; details are logged server-side. |

### Example

```bash
TOKEN=<integration access token>

curl -s -H "Authorization: Bearer $TOKEN" \
  https://store/rest/V1/tinylist/orders/100000123/invoice \
  | jq -r 'select(.available).pdf_base64' | base64 -d > invoice.pdf

curl -s -H "Authorization: Bearer $TOKEN" \
  https://store/rest/V1/tinylist/orders/100000123/tracking/2ROBG1234567 | jq
```

---

## Layout

```
composer.json
registration.php
etc/
  module.xml
  acl.xml                                  # the two API ACL resources + config section
  config.xml                               # payment + carrier defaults
  di.xml                                   # provider registry, payment adapter, preferences
  payment.xml                              # method declaration
  webapi.xml                               # REST + SOAP routes
  adminhtml/system.xml                     # payment method + carrier-status admin config
  integration/config.xml                   # pre-declared "Tinylist Connector" integration
  integration/api.xml                      # resources it requests
Api/
  OrderInvoiceInterface.php                # service contract: invoice PDF
  OrderTrackingInterface.php               # service contract: tracking status
  Data/InvoiceResultInterface.php          # response shapes — these drive the JSON
  Data/TrackingResultInterface.php         #   keys and the generated WSDL types
  Data/TrackingEventInterface.php
Model/
  OrderInvoice.php                         # invoice service: load, render, encode
  OrderTracking.php                        # tracking service (thin; resolver does the work)
  OrderLocator.php                         # load an order by increment id
  Data/InvoiceResult.php                   # immutable response objects
  Data/TrackingResult.php
  Data/TrackingEvent.php
  Carrier/StatusResolver.php               # dispatcher: match, fetch, envelope
  Carrier/ProviderInterface.php            # what a carrier provider must do
  Carrier/ProviderPool.php                 # the registered providers
  Carrier/Status.php                       # a provider's normalized answer (internal)
  Carrier/StatusException.php              # friendly, caller-facing error
  Carrier/Provider/Context.php             # shared services for providers
  Carrier/Provider/AbstractProvider.php    # matching, HTTP, config, guards
  Carrier/Provider/Sameday.php
  Carrier/Provider/Dpd.php
  Carrier/Provider/Fancourier.php
  Config/Source/Carrier.php                # "Enabled Carriers" options
  Payment/Dropshipping.php                 # the method code
  Payment/Command/Initialize.php           # order → configured processing status
```

---

## Notes / limitations

- **Not runtime-tested against a live store.** Verification here was PHP lint,
  XML well-formedness, and checking every framework class, method, constructor
  argument name, config path and ACL resource id against Magento 2.4-develop
  source and the carriers' own repositories. What that does *not* cover: the
  invoice PDF render under store emulation, the payment adapter's behaviour on a
  real order, and the FAN Courier paths above.
- `setup:di:compile` is required — the module uses generated factories for its
  response objects.
- The invoice endpoint returns the PDF inline as base64. The Tinylist client also
  accepts a `pdf_url` instead; that would suit very large multi-invoice
  documents, but would need a signed-URL controller and is not implemented.
- Both endpoints are consumed by the Tinylist platform's Magento 2 adapter. The
  response field names match the Magento 1 envelope so the two share one mapping
  — keep them in step when changing either.
