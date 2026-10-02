# Checked cart lifecycle contracts

These tests qualify the maintenance candidate's components. They do not install
the candidate, configure a signing secret, activate a database drop-in, or prove
production compatibility. Keep the retained package metadata and vendor bundle;
do not run Composer to recreate this release-asset checkout.

Use PHP 8.2 with the reviewed source trees available locally:

```sh
export WL_WORDPRESS_SOURCE=/path/to/qualified/wordpress
export WL_WOOCOMMERCE_SOURCE=/path/to/qualified/woocommerce
export WL_WPGRAPHQL_SOURCE=/path/to/qualified/wp-graphql
export WL_MU_PLUGINS_SOURCE=/path/to/qualified/wl-mu-plugins

php tests/contracts/cart-session-storage-contract.php
php tests/contracts/cart-session-transfer-contract.php
php tests/contracts/cart-session-creation-contract.php
php tests/contracts/cart-session-http-boundary-contract.php
php tests/contracts/cart-session-owned-handler-contract.php
php tests/contracts/cart-session-lifecycle-contract.php
php tests/contracts/cart-session-lifecycle-integration-contract.php
```

The HTTP and lifecycle runners start native PHP subprocesses and a temporary
localhost HTTP server. They use `output_buffering=0`, terminate their own server,
and remove their own temporary ledgers. External networking, a WordPress
bootstrap and a database are unnecessary. Fixture and component checksums prevent
silently qualifying different inputs; changed source requires a reviewed pin and
affected-behavior recheck.

| Runner | Verified boundary | Important substitutes |
| --- | --- | --- |
| Storage | Actual storage helper, driver interface, WooCommerce cache helpers and typed GraphQL errors; captured ownership, checked CRUD/cache invalidation, failure and pure response observation | Driver implementation, SQL, object cache and account hydration |
| Transfer | Actual storage, frozen source fingerprint, sorted dual ownership, checked destination/retirement transaction and uncertainty outcomes | Driver/SQL/transaction/cache implementations; no native account, authentication or order |
| Creation reservation | Actual storage and canonical immutable attempt marker, checked marker-only commit, internal UUID reuse and exact transfer revalidation; failed/uncertain attempts cannot resume | Driver/SQL/transaction/cache implementations; no native customer insertion, admission or checkout caller |
| HTTP | Actual exception handling, native headers/status/cookies, response encoding before finalization and output suppression after emission | Application cleanup and finalization |
| Owned handler | Actual handler, storage, JWT and WooCommerce session parent; preflight, guest creation/retirement marker denial before hydration, identity admission, dirty writes, auth detach and terminal fencing | Lifecycle, WordPress hooks, authentication, SQL and cache |
| Lifecycle | Actual lifecycle and HTTP boundary with genuine `WP_Hook`, `plugin.php` and GraphQL executor; captured writers, callback/source cohorts and terminal outcomes | Handler/storage, cart/customer, router, authentication and SQL |
| Composed integration | Actual handler, operation coordinator, lifecycle, storage and HTTP boundary; genuine hook dispatcher, GraphQL instrumentation/mutation types/executor and AppContext; rejected mixed operations and later filtered input cannot publish partial data or cart credentials; marker denial constructs customer/cart before the request guard and exercises shutdown persistence | Customer/cart, router, authentication, SQL and cache; AppContext is constructed without site bootstrap |

The composed positive control completes one checked write, seal and release.
Rejection controls prevent the captured customer/cart/session flush, retain the
existing row and abort only the captured active scope. A failed active or released
driver takes precedence as literal `WL_CART_SESSION_UNAVAILABLE` without a
translation callback. HTTP controls preserve unrelated authentication headers and
cookies while withholding queued cart credentials. Canonical guest-marker denial
seals and releases healthy storage before disabling cart-session construction,
keeps handler writes inert and retains `WL_CART_SESSION_INVALID`. Its controlled
Router catch models status 500; later storage uncertainty dominates as 503.
This does not establish installed WooCommerce/Router or native SQL behavior.

The HTTP boundary drops representational metadata such as a queued
`Content-Encoding` before writing its preencoded JSON. Its post-emission buffer
suppresses PHP shutdown/destructor output, including ordinary WordPress buffer
flushing. This does not undo shutdown database side effects, qualify streaming or
unknown output callbacks, or intercept raw/SAPI output bypasses.

Creation and transfer APIs remain dormant. Reservation burns one guest creation
attempt before future native customer insertion; its marker never authenticates
or identifies an account whose insertion failed to return. Acknowledged reservation
is required before its freeze, and transfer rollback preserves that marker. Future
caller authority, both-marker admission checks, callback qualification, account
reconciliation/retention and order preservation remain separate acceptance gates.

## Installation and connected acceptance remain separate

GraphQL requests require the optional, early-loaded guarded database capability
from the owning MU repository. Composer MU discovery cannot activate `db.php`.
The lifecycle also requires explicitly reviewed, exact source and callback cohorts
through `WOOGRAPHQL_CART_SESSION_SOURCE_COHORT` and
`WOOGRAPHQL_CART_SESSION_CALLBACK_COHORT`. The built-in source cohort is narrow;
the external callback manifest defaults to empty and refuses unsupported
installations. Do not generate expected hashes from whatever is installed and
treat that as review. Pin callable identity/location, priority, argument count,
receiver, stable registry and nonstreaming behavior after investigating effects.

Before release, qualify the actual WordPress/WooCommerce/WPGraphQL/router/output
configuration and driver together, including database errors/reconnect fencing,
account cache invalidation before hydration, all captured native writers and
overlapping A/B requests followed by an independent fresh C read. Both cart
updates must survive without mutation replay. Trusted checkout transfers,
provider authentication, native cookies, HPOS, store consumers and existing
order/payment returns remain additional gates. Temporary transition holds are
not final feature parity.
