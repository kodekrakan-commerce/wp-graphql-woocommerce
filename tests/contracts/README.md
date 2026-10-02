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
php tests/contracts/cart-session-checkout-order-contract.php
# Requires WL_HEADLESS_LOGIN_SOURCE pointing at the qualified Headless Login tree.
php tests/contracts/cart-session-native-cookie-contract.php
php tests/contracts/cart-session-native-order-save-contract.php
php tests/contracts/cart-session-native-customer-adoption-contract.php
php tests/contracts/cart-session-http-boundary-contract.php
php tests/contracts/cart-session-owned-handler-contract.php
php tests/contracts/cart-session-lifecycle-contract.php
php tests/contracts/cart-session-lifecycle-integration-contract.php
php tests/contracts/cart-session-checkout-origin-contract.php
php tests/modernization/free-order-completion-contract.php
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
| Checkout order fence | Actual storage fixed-row canonical reader, creation-backed atomic transfer insertion, pending-ID binary CAS and atomic final-session/completion CAS; fresh facades cannot resume old authority | Driver/SQL/transaction/cache; no native customer/order or HTTP |
| Dormant native cookie caller | Genuine Headless Login AuthCookie::set_auth_cookie with remember=false, genuine WP_Hook and actual lifecycle registration; one-argument send_auth_cookies preserves ordinary policy | Auth/token/user/settings and Woo; CLI only, no delivered credentials or complete login |
| Creation reservation | Actual storage and canonical immutable attempt marker, checked marker-only commit, internal UUID reuse and exact transfer revalidation; failed/uncertain attempts cannot resume | Driver/SQL/transaction/cache implementations; no native customer insertion, admission or checkout caller |
| HTTP | Actual exception handling, native headers/status/cookies, response encoding before finalization and output suppression after emission | Application cleanup and finalization |
| Owned handler | Actual handler, storage, JWT and WooCommerce session parent; preflight, guest creation/retirement marker denial before hydration, identity admission, dirty writes, auth detach and terminal fencing | Lifecycle, WordPress hooks, authentication, SQL and cache |
| Lifecycle | Actual lifecycle and HTTP boundary with genuine `WP_Hook`, `plugin.php` and GraphQL executor; captured writers, callback/source cohorts and terminal outcomes | Handler/storage, cart/customer, router, authentication and SQL |
| Composed integration | Actual handler, operation coordinator, lifecycle, storage and HTTP boundary; genuine hook dispatcher, GraphQL instrumentation/mutation types/executor and AppContext; rejected mixed operations and later filtered input cannot publish partial data or cart credentials; marker denial constructs customer/cart before the request guard and exercises shutdown persistence | Customer/cart, router, authentication, SQL and cache; AppContext is constructed without site bootstrap |
| Checkout origin | Actual retained self-bound Checkout closure, preparation and final customer branch with the handler, operation and lifecycle; genuine WP hooks, GraphQL instrumentation, mutation type and executor; exact invocation binding, immutable single-root eligibility, one-use consumption and finally cleanup | Controlled before-checkout caller reflects into the actual protected customer branch; natural validation, session update and order processing are omitted; customer/cart, Router, authentication, SQL and cache remain substitutes |

The dormant checkout-origin runner has 36 source contracts. A native-registration
case runs actual Checkout registration, TypeRegistry registration and WPMutationType
construction/resolution; only schema type materialization is recorded. It preserves
the native lowercase `checkout` name. The previous fixture's capitalization masked
a real capture defect. The runner checks source,
receiver, hook order and argument count at capture, entry and final consumption,
including input/context/Info/path drift, replacement closures, recapture, reentry,
late policy and registry changes. Distinct ordinary noncreating roots remain valid;
they cannot acquire single-root creation eligibility. Eligible single-root checkout now passes preflight without evaluating creation policy there. The actual final filtered branch consumes origin once and requires its native creation/source/callback cohort before reservation or effects. This origin runner intentionally omits that cohort and still proves zero account/authentication/order effects; it does not qualify successful creation, adoption or checkout.
The older 56 operation cases retain a synthetic lowercase checkout callback and
qualify preflight holds only. Their four synthetic checkout controls now require
rejection before callback effects, because they cannot supply the default factory
binding. The new runner supplies canonical Checkout binding.

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

The 32 composed cases now pass the actual Executor's untouched `ExecutionResult`
into the lifecycle, matching native single-request HTTP. Rejected object responses
format once through the exact pinned class before terminal discard, then repeat
health/cohort checks and publish only validated plain errors. Partial data and
extensions are withheld; formatter failures, malformed errors and unsupported
objects remain unavailable. The existing intentional auth-detach path has already
fenced its old grant before formatting; it cannot rehydrate or flush that cart.
Router's thrown-error catch still supplies arrays. Earlier fixture `toArray()`
conversion masked the native object rejection defect.

The HTTP boundary drops representational metadata such as a queued
`Content-Encoding` before writing its preencoded JSON. Its post-emission buffer
suppresses PHP shutdown/destructor output, including ordinary WordPress buffer
flushing. This does not undo shutdown database side effects, qualify streaming or
unknown output callbacks, or intercept raw/SAPI output bypasses.

The source now connects creation and transfer to the qualified final checkout branch. Reservation burns one guest creation
attempt before native customer insertion; its marker never authenticates
or identifies an account whose insertion failed to return. Acknowledged reservation
is required before its freeze, and transfer rollback preserves that marker. The destination fixed-row fence is inserted in that transfer transaction. Authenticated admission reads it before hydration: pending closes healthy storage and returns a transition error; malformed or uncertain storage remains unavailable. The original adopted permit alone can bind an order ID and complete the empty final session atomically. A complete row permits later ordinary authenticated purchases without reviving its old creation attempt. Controlled storage/admission tests do not establish native insertion, cookie adoption, full order saves or HTTP delivery. Account reconciliation/retention and connected native journey/failure acceptance remain separate gates.

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
updates must survive without mutation replay. The source permits the first free creation journey only for the qualified CPT order store and single-site installation. Native checkout/account/cookie journey execution, provider authentication, HPOS, paid creation and payment reconciliation, store consumers and existing order/payment returns remain additional gates. Temporary transition holds are
not final feature parity.

## Free-order completion result

The retained helper requires literal `true` from native `payment_complete()` before
returning success or a redirect. WooCommerce can save status/paid date before a
callback fails and returns `false`; that saved state must not substitute for the
completion result. Failure preserves existing native state and does not authorize
a checkout retry. The helper's four controlled-order cases cover false after
recorded save, true, a thrown exception and missing order. They do not execute the
WooCommerce datastore or callbacks. The separate actual Checkout closure contract
covers a controlled helper failure before its ID returns: the durable order remains
unpurged. Natural checkout/cart clearing and native failure/reconciliation behavior
remain connected-runtime acceptance work.

The native order-save component regression executes the installed `WC_Order::payment_complete()` and `WC_Abstract_Order::save()` against a declared controlled datastore. It demonstrates the swallowed before-save exception, pending durable order, and literal `true` completion return, and checks that fresh payment save evidence rejects completion. The native customer adoption component executes actual `new WC_Customer(id, true)` and session datastore read/write with a controlled account datastore and empty metadata boundary. It verifies that the new ID rejects old guest addresses, the retained allowlist preserves addresses for the totals probe and final session, and account identity/role/email remain the new account's. These components do not qualify account insertion, HTTP cookie delivery, or the complete native creation journey.
