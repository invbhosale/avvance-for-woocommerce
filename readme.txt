
=== Avvance for WooCommerce ===
Contributors: usb-avvance
Tags: payments, checkout, financing, bnpl
Requires at least: 6.6
Tested up to: 6.8.2
Requires PHP: 8.1
Stable tag: 1.3.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Avvance payment method for WooCommerce. Redirect checkout to Avvance to complete the application, with webhooks for status/settlement, admin refunds/voids, and a cart fallback that can resume the loan application or check status via Notification-Status.

== Description ==
* Redirect flow (consumerOnboardingURL) using Financing Initiation API.
* Status mapping: **Authorized → paid**, **Settled → note only**, **Denied/System error → alternate payment**, **Pending customer action → note**.
* Admin order action to **Cancel via Avvance (Void/Refund)**.
* **Cart fallback banner** lets the shopper resume application or **Check status** (calls Notification-Status) to finish the order if authorized/settled.

== Settings ==
- UAT/Prod auth & API base URLs
- OAuth client key/secret
- Partner ID, Merchant ID (MID)

== API Mapping ==
- OAuth2 Token: `/auth/oauth2/v1/token`
- Financing Initiation: `/poslp/services/avvance-loan/v1/create`
- Notification Status: `/poslp/services/avvance-loan/v1/notification-status`
- Void: `/poslp/services/avvance-loan/v1/void`
- Refund: `/poslp/services/avvance-loan/v1/refund`

== Changelog ==
= 1.3.0 =
* Added cart fallback banner and AJAX status check (Notification-Status).
* Added partnerReturnErrorUrl to return shoppers to cart for alternate payment.
* Finalized status mapping and webhook handling.

= 1.2.0 =
* Status mapping aligned to Authorized/Settled/Pending/Denied.

= 1.1.0 =
* OAuth2 client-credentials, environment settings, admin cancel action.

= 1.0.0 =
* Initial MVP.
