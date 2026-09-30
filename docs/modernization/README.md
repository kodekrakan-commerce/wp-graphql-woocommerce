# Owned WooGraphQL 1.0.3-derived candidate

This isolated branch imports the verified official1.0.3 distribution, source
`c401cee2f62df80d463145f16192937de2d656ab`, ZIP SHA256
`202099867ce7b7bc9e1d5ed133096a534f11259f6a465732ec6e24b8e9d0d5b2`.
Import checkpoint `db04ae1` descends from immutable owned0.21.2.2 `63202b2`.
The25 bundled dependency files are preserved byte-for-byte and recorded in
`upstream-bundle-sha256.json`; inherited README CRLF/vendor changelog whitespace
is preserved. Root Composer metadata retains package identity, empty requirements
and prebuilt distribution ownership. Do not run Composer install/update here.
The imported release's Composer lock is provenance, not an installer input for
this empty-requirements distribution manifest.

Candidate application version1.0.3.1 is unpublished; no tag, platform/client lock,
production installation or shared fixture has adopted it. The active B1a fixture
continues using owned0.21.2.2. Never activate the unpatched vanilla import.

## Checkout compatibility port

Retain released deferred gateway hook after gateway validation/order binding,
server order needs_payment authority, exact same-session unpaid deferred Stripe
retry before stock validation, bounded session order-summary key hashes and
browser metadata allowlist/length/scalar/enum handling. Retry matching now includes
shipping phone, and the before-meta-save hook receives the sanitized values
actually stored. Preserve upstream WP_Error validation/notices, shipping-phone
support, native hooks and refreshed order objects after metadata updates.

New unchecked CheckoutInput.fees and createdVia are held out of the owned schema
and helper. The existing username/password account payload continues native
registration authentication; the new authenticate:false contract is held out
until its client/order-ownership semantics are separately adopted and tested.
No browser paid claim/transaction may complete a chargeable order.

## Session and schema prerequisites

Upstream queue IDs truncate fractional microtime, queued entries can be treated
as active ownership after one sleep, and advisory-lock failures are ignored.
The source-only manager fix passes13 deterministic production-class tests under
native PHP8.2.34 and local PHP8.4.11 with external boundaries doubled. This does
not establish HTTP/MySQL concurrency acceptance. Successful enqueue alone never
permits mutation execution. The application/fixture remains on0.21.2.2.

WooGraphQL1.0.3 includes ProductBrand.image with the same thumbnail_id/media-loader
contract as the MU fallback. Owning MU candidate suppresses its field registration
only when an active WooGraphQL supplies that native class; older/inactive cases
retain the fallback. Integrated schema/logo checks are required before adoption.
MU AddToCartPayload.customer/sessionToken support remains necessary.

The bundled JWT7 requires at least32-byte HS256 keys. Legacy WooGraphQL's absent
configured-secret fallback is a fixed short string; upstream now uses wp_salt().
Do not silently rotate live keys, weaken JWT7 checks or replace its vendor bundle.
The cohort must compare old/new tokens with an explicit identical synthetic
32+byte secret and separately record the legacy default invalidation behavior.
Read-only installed key-source/length evidence and a reviewed cart-expiry/key
migration plan are required before any store rollout. A native PHP8.2 library-level probe passed baseline-token replay and candidate
issuance with the same explicit40-byte synthetic secret. The24-byte old default
was rejected by JWT7; a changed default produced a signature rejection. This is
library evidence only; full session-handler/token transport remains unqualified.

Connected checkout/session/HPOS/mutation-permission, same-session concurrent carts,
account-login transfer, provider webhook/retry/failure and browser/store gates
remain open. Source port and syntax/bundle checks alone are not acceptance.

## Architecture disposition — candidate remains on hold

Independent Astra review found manager-only locking insufficient for persisted
session initialization, query/shutdown writes and guest-to-customer migration.
Retain the qualified0.21.2.2 B1a group while preserving this experimental branch.
Stable compatible versions are required; newest upstream is not an adoption goal.
This decision does not claim the old release has proven concurrency safety.

A coherent future implementation requires ownership before persisted session/cart
reads, through final customer/session flush; idempotent handler replacement and
old callback cleanup; absence reload normalized to an empty snapshot; explicit
multi-key migration ownership with deterministic initial ordering/nonblocking
mid-request target acquisition; confirmed destination save before guest deletion;
and a migration tombstone rejecting delayed old guest bearers until expiry.
Do not map an unauthenticated old guest bearer to a customer's full session.

Named locks disappear with database connection loss. Stock wpdb can reconnect
and retry a write inside a mutation, beyond field-boundary ownership checks.
Reconnect fencing and failed-write cache semantics need an explicit supported
storage contract and real disconnect tests. Database drop-ins/proxies and shared
MySQL namespaces remain unresolved. An invasive lifecycle/storage rewrite is not
silently adopted as a small queue patch.

Acceptance must cover real parallel mutation/batch/query requests, live-owner TTL
and abandoned recovery, guest mutation racing login/customer mutation/migration,
two guests under every transfer policy, login/register inside a batch, old-token
replay after migration, missing-row reload, failure/shutdown no writes, connection
loss and persistent-cache stale local reads. Checkout's native fixture harness is
prepared but has not run because these prerequisites remain open.
