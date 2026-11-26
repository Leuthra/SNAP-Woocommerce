# Changelog

All notable changes to this fork are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses
[Semantic Versioning](https://semver.org/).

Releases up to 2.32.3 were published by Midtrans; their history is in
[`readme.txt`](readme.txt).

## [2.33.1] - 2026-11-26

### Changed
- Plugin headers: tested with WordPress 7.1 and WooCommerce 11.3, requires
  PHP 7.4 and the WooCommerce plugin. The fork maintainer is listed as author
  next to Midtrans.
- Documentation: real changelog, security policy, fork notice and
  installation guide.

## [2.33.0] - 2026-11-26

### Security
- Fix unauthenticated Cross-Site Scripting, CVE-2026-39758 (affects <= 2.32.3).
  The notification endpoint no longer prints the request body and always
  responds as `text/plain`.
- Verify `signature_key` of Midtrans HTTP notifications. New **Signature Key**
  setting for Midtrans accounts whose signature key is separate from the
  Server Key. When it is set, notifications with an invalid signature are
  rejected.
- `order_id` from a notification body is only trusted when the signature is
  valid, or when Midtrans API confirms the transaction belongs to that order.
- Orders are put on-hold instead of completed when the paid amount is less
  than the amount requested from Midtrans.
- Escape Midtrans API error messages shown on checkout.
- Validate the Snap token and transaction id taken from the URL, and encode
  ids used in API paths.
- Prevent open redirect through the finish URL cookie; the cookie is now
  `HttpOnly`.

### Fixed
- Order status not updated for DANA and BI-SNAP bank transfer (Danamon, BSI,
  SeaBank, Saqu VA).
- Paid orders being cancelled or put on-hold by a notification from another,
  abandoned transaction of the same order.
- Snap API error `customer_details.email format is invalid` when a customer
  field is empty.
- Finish URL page (BCA KlikPay) order lookup under WooCommerce HPOS.
- Notifications are answered with HTTP 500 when the transaction status cannot
  be fetched, so Midtrans retries them instead of losing them.

### Added
- Separated payment buttons: DANA, Kredivo, CIMB VA, Danamon VA, BSI VA,
  SeaBank VA, Saqu VA.
- An active Snap token is reused when the customer pays again for the same
  order with the same payment method, instead of creating another
  transaction or VA number.
- Filters: `midtrans_snap_order_id`, `midtrans_sub_enabled_payments`,
  `midtrans_reuse_active_snap_token`,
  `midtrans_verify_notification_signature`,
  `midtrans_validate_notification_amount`.

[2.33.1]: https://github.com/Leuthra/SNAP-Woocommerce/releases/tag/v2.33.1
[2.33.0]: https://github.com/Leuthra/SNAP-Woocommerce/releases/tag/v2.33.0
