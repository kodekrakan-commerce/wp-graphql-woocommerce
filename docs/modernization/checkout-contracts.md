# Native checkout contract harness

`tests/modernization/checkout-contracts.php` is a guarded `wp eval-file` harness for the sealed modernization foundation at `http://127.0.0.1:8106`. It exercises the active GraphQL schema and checkout mutation with native WooCommerce cart, customer, session, order, stock reservation and BACS behavior. It does not load historical checkout implementations. The only payment substitution is a process-local gateway named `stripe`, whose `process_payment()` counts calls and returns failure without provider access. The released WooNuxt Settings deferred-payment hook remains the implementation under test.

## Prerequisites and execution ownership

The source candidate is WooGraphQL **1.0.3.1**, based on the verified upstream 1.0.3 tree. Writing or linting this harness does not activate that candidate. The foundation baseline initially runs owned 0.21.2.2. The coordinating owner must finish the queue/brand and session-secret prerequisite gates, stage reviewed candidate bytes, export a fresh named SQL snapshot, and run the harness. No connected execution is authorized for the harness author before that staging gate.

The foundation directory is `/Users/filipegarrido/simple-store/.codex/worktrees/commerce-modernization/wl-commerce/tests/modernization/sandbox`. Its `wp` service runs PHP 8.2 with WordPress root `/var/www/html`, database `wl_sandbox`, and a private bind at `/var/sandbox-private`. Use the existing `compose.sh` wrapper; do not start another stack or alter its networking.

Before executing, the owner exports the current fixture database to a fresh `.sql` file in `.runtime/private`, independently records its SHA-256 and baseline/staging provenance, and copies the reviewed harness to that private bind. Snapshot content is private and must not be committed or printed. The harness requires:

- `WL_CHECKOUT_SNAPSHOT`: absolute container path under `/var/sandbox-private/` ending in `.sql`.
- `WL_CHECKOUT_SNAPSHOT_SHA256`: lowercase SHA-256 of that exact pre-run SQL file.
- `WL_CHECKOUT_SOURCE_SHA256`: lowercase SHA-256 of the reviewed candidate `includes/data/mutation/class-checkout-mutation.php`.

Run from the foundation directory, substituting the actual verified filename and digests:

```sh
./compose.sh exec -T -u www-data \
  -e WL_CHECKOUT_SNAPSHOT=/var/sandbox-private/checkout-before-YYYYMMDD-HHMMSS.sql \
  -e WL_CHECKOUT_SNAPSHOT_SHA256=VERIFIED_SQL_SHA256 \
  -e WL_CHECKOUT_SOURCE_SHA256=VERIFIED_CANDIDATE_HELPER_SHA256 \
  wp wp --path=/var/www/html eval-file /var/sandbox-private/checkout-contracts.php
```

These are invocation templates, not evidence of execution. The script aborts before installing hooks or mutating WooCommerce state unless WP-CLI, exact boolean `WL_SANDBOX_TEST_MODE === true`, configured and connected `wl_sandbox`, exact home/site URL, PHP 8.2, active WooGraphQL 1.0.3.1, the released Settings defer hook, snapshot freshness (under two hours), snapshot checksum, and active helper checksum all match. It prints runtime version, datastore, active source and checksum provenance before tests. A checksum-protected existing SQL file establishes a rollback artifact; the owner's export log establishes when and from which database it was captured.

## Scenarios and assertions

| Contract | Connected assertions |
| --- | --- |
| Valid deferred checkout | GraphQL returns `pending` / `PENDING`; persisted order needs payment, has no transaction/date-paid, retains native cart and `order_awaiting_payment`; fake native Stripe handler is never called. |
| Identical last-stock retry | Native checkout holds the single available physical unit; identical same-session mutation returns the same order ID without another order or re-entering validation. |
| Changed shipping phone/address/rate/cart and foreign session | Each enters the native checkout validation hook and a temporary validation error prevents success/reuse; original order remains pending and no additional order is created. Quantity and foreign-session variants also encounter native stock validation. |
| Forged payment assertions | `isPaid`, `transactionId`, Stripe references, paid/total/customer/deferred metadata cannot complete or change authoritative fields of a chargeable order. |
| Native BACS | Actual `WC_Gateway_BACS::process_payment()` returns success with persisted `on-hold` / GraphQL `ON_HOLD` and clears the cart. |
| Actual free cart | Zero-price virtual product produces a genuinely zero-total order; native completion clears cart without persisting a forged transaction or calling Stripe. |
| Guest summary binding | Twelve actual BACS checkouts in one native session bind each order to SHA-256 of its key, retain exactly the last ten IDs, contain no raw keys, and persist in WooCommerce's session store. |
| Existing account payload shape | Schema accepts `account: {username, password}`; a new customer receives the requested login/password, owns the order and becomes the current customer in the next real GraphQL operation. |
| Metadata boundaries | GraphQL writes accepted allowlist/attribution/Stripe enum values; 500 bytes survive, 501 bytes and 502-byte multibyte values do not; unknown keys are absent; hook values equal sanitized persisted values. Actual helper calls additionally reject non-scalars and invalid Stripe enum values without overwriting a valid enum. |

For changed-input cases, a process-local `woocommerce_after_checkout_validation` sentinel adds a `WP_Error` after the real checkout validation. This is deliberate: WooCommerce 10.6 excludes its current awaiting order from held-stock checks and can legitimately resume a pending order after validation. Merely changing a valid address/phone does not inherently make checkout invalid. The sentinel tests that the candidate's early retry return cannot bypass validation; it does not assert that ordinary changed addresses must fail checkout. The foreign-session case creates a distinct identity through the native session handler, restores identical native cart item objects, and leaves no awaiting-order binding in that foreign session. HTTP token transport, concurrent admission, JWT migration and provider webhook delivery are separate acceptance gates.

Metadata input is a GraphQL `String`; arrays cannot reach checkout through that schema. Non-scalar rejection therefore calls the active public metadata helper against a native persisted order, while schema-compatible values use actual GraphQL mutations. No source-string test substitutes for these behavioral assertions.

## Bounded state and rollback

The harness creates exactly two hidden synthetic products (physical 3.50 and virtual zero), tracks their IDs, snapshots their price/stock properties, and restores those properties in each scenario's `finally`. It captures only orders/customer IDs created while its hooks are installed. Each scenario releases native reservations and deletes its exact owned orders; outer `finally` restores/deletes owned products, deletes owned users, destroys the current native session and removes process-local hooks. Stripe/BACS availability, shipping rates (two synthetic native flat-rate objects), registration and hold-stock settings are object/filter changes only. Network and mail are blocked process-locally as well as by the foundation's isolation policy. No Stripe option/key, real payment, voucher fixture or baseline product setting is written.

Exact object cleanup is **not** a complete fixture rollback. Native hooks can affect order notes, Action Scheduler rows, stock metadata, session rows, options and caches; fatal exits may also interrupt cleanup. The owner must restore the verified pre-run SQL snapshot after the run, flush appropriate fixture caches, and verify the intended staged/baseline plugin versions and fixture readiness again. This also keeps the harness independent from broader foundation tests. Restore commands and candidate activation remain the coordinating owner's responsibility under the existing sandbox runbook.

## Current evidence

The harness author's verification is local PHP syntax lint only, on host PHP 8.4.11. No WordPress, database or Docker execution was performed while producing these files. PHP 8.2 connected execution, schema assumptions, complete scenario output and rollback verification remain unverified until the coordinating owner stages the candidate and runs the guarded harness. Record actual command/result, snapshot provenance, candidate hashes and fixture restoration evidence in the existing modernization acceptance record after that run.
