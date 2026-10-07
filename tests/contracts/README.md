# Checked cart lifecycle contracts

These tests qualify the maintenance candidate's components. They do not install
the candidate, configure a signing secret, activate a database drop-in, or prove
production compatibility. Keep the retained package metadata and vendor bundle;
do not run Composer to recreate this release-asset checkout.

The focused fresh-order regression is `cart-session-native-pending-contract.php`.
It uses the genuine `new WC_Order()` constructor, native getters/setters/save,
the native datastore wrapper and inherited CPT creation/post-status code. Its
unmodified control proves that CPT creation can persist `wc-pending` while raw
edit status remains empty. The protected positive Stripe control materializes
canonical pending before the sole native save, retains strict deferred checks,
and observes no status transition, paid field or order note. SQL, metadata,
cache, source/cohort qualification and the account fence are recording seams;
this is not native database, checkout HTTP, email or provider acceptance.
`cart-session-pending-cohort-contract.php` separately exercises the actual
lifecycle with native WP_Hook/plugin.php: it rejects other non-owner callbacks
on the three status hooks, changed registry objects/arity, and missing
source pins. These tests use the same source environment inputs below; the cohort
test also requires `WL_SETTINGS_SOURCE` and `WL_WOOCOMMERCE_SOURCE`. The only
permitted non-owner status callback is the positively observed native
`DraftOrders::register_draft_order_status` at priority 10 with one argument.
Its exact class, declaring class, method and source digest are checked in addition
to the existing explicit manifest, receiver binding and frozen registry. The
pinned callback only appends `wc-checkout-draft`; all pending/status entries stay
unchanged. Its other methods, subclasses and unrelated status filters receive
no permission. Native callback tests invoke only that method on a receiver
constructed without its package initializer; no hook installation, scheduler,
cleanup or service behavior is exercised.

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
# New source-only paid controls additionally require WL_SETTINGS_SOURCE.
php tests/contracts/cart-session-paid-checkout-contract.php
php tests/contracts/cart-session-deferred-cart-retirement-contract.php
php tests/contracts/cart-session-paid-cohort-contract.php
php tests/contracts/cart-session-native-customer-adoption-contract.php
php tests/contracts/cart-session-armed-adoption-contract.php
php tests/contracts/cart-session-http-boundary-contract.php
php tests/contracts/cart-session-owned-handler-contract.php
php tests/contracts/cart-session-gateway-freeze-contract.php
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
| Gateway freeze | Actual native WC_Payment_Gateways/COD and WP hooks with real lifecycle request guard; singleton initialized before first freeze, repeat request stability, pre-invocation factory denial and post-registration/late-change denial | Settings/options/base gateway and scope classifier; no site bootstrap, HTTP, account, order or payment |
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

The armed-adoption component executes the real handler private adoption path, the genuine `wc_set_customer_auth_cookie()` caller and armed `init_session_cookie()`, then prepares and verifies a destination JWT. It arranges an already-reserved creation attempt by reflection and uses controlled native auth/user and lifecycle permit/cohort seams, with the actual storage reservation/transfer implementation over the existing recording driver. Success verifies destination claim/header and selected body token; denied consumption, failed lifecycle adoption and signing failure retain pending0 and withhold credentials. This focused regression proves the issuance correction, not native permit/cookie authenticity or installed-cohort acceptance.

## Protected deferred account preparation

The new positive-total branch keeps the captured native order and datastore,
creation save/ID/customer/UUID fence, and a separate deferred phase. It qualifies
the exact Settings function at priority 10 with four arguments, passes the
captured receiver, refuses every result except the exact pending/empty redirect
array, and executes native `read_meta_data(true)` before checking one positive
persisted metadata ID for both deferred=yes and the original UUID. Fresh checkout
metadata full saves have their own role; initial creation evidence cannot prove a
later save. Full saves during deferral/readback/qualification, including same-ID
substitute objects and swallowed exceptions, latch failure before throwing.

The 39 paid component cases use actual native WC_Order/WC_Data/WC_Meta_Data and
the owning Settings function, with controlled hooks, metadata datastore, SQL,
adoption and cohort handshake. The 17 cohort cases use actual WP_Hook and the
retained lifecycle with explicit controlled ownership/source classes, checking
missing/wrong descriptor/arity/order, late writer/getter/read/metadata callbacks,
and callback reorder before invocation. They do not qualify the native CPT/WP
metadata persistence chain or installed callbacks. Native CPT and WordPress
metadata source pins are prerequisites; the future distinct source/callback
cohort must be reviewed before a connected native paid journey can run.

A protected pending result means prepared deferred checkout. Its destination
cart is explicitly emptied and flushed, while bounded summary authorization
survives. After the captured after-checkout hook, the selected free/deferred proof
is revalidated again by the final HTTP lifecycle before the existing atomic
session/fence completion. Completion does not attest payment or browser receipt;
a lost final commit reply or delivery failure can retain complete durable state
while credentials are withheld. Pending admission/recovery/provider/intent and
return gates remain separate. The accepted native free fixture and its completed
cleanup are not changed or replayed by these source controls.

## Negotiated ordinary deferred cart retirement

The optional checkout `metaData` request `_wl_checkout_cart_retirement` accepts
exactly one `v1:<lowercase UUIDv4>` value. Without it, ordinary deferred checkout
retains its legacy cart behavior. The request is never saved as order metadata.
A negotiated ordinary Stripe checkout checks the admitted session, order owner,
unpaid deferred order, awaiting order ID and submitted cart hash while the
session scope remains held. It then runs native `empty_cart(true)`, including
the persistent cart, before payment can leave the checkout page. It carries the already-validated
awaiting-session authorization into the existing bounded receipt map before
clearing, so reused legacy guest orders retain intent/receipt access. Protected guest
account creation keeps its separate destination lifecycle and issues no ordinary
retirement acknowledgment.

Only completed retirement can persist `_wl_checkout_cart_retired` as
`v1:<request UUIDv4>:<fresh 32 lowercase hexadecimal characters>`. The browser
metadata whitelist cannot write either protocol key. Native metadata readback
checks exactly one persisted row and the current order, owner, datastore, cart
and session after callbacks. The frontend must recognize this marker only on the
current checkout response bound to its private request nonce and receipt lifetime.
Missing or invalid acknowledgment keeps the legacy post-confirmation cart path;
old receipt metadata never grants cart mutation authority.

A request-local flag burns before native cart effects. Any later exception,
including failed acknowledgment saving or an after-checkout hook, makes the
session unavailable and preserves the existing order for verification/retry.
It retains the internal exception cause without publishing private details.

The retirement runner has 33 component cases using the actual checkout closure,
process helper, owned handler, native WC cart/session and native WCData metadata
methods. It covers fresh/reused orders and ordinary guests, preserved guest order binding, legacy callers, invalid
requests, browser spoofing, fresh replacement of old markers and failed or drifted
acknowledgment writes. Order insertion/factory, totals/validation, origin admission,
hooks, SQL/auth/cache remain explicit recording seams. These checks establish no
installed HTTP, provider, payment failure/retry or browser redirect acceptance.
