# Offline session-manager contract regressions

Run from the owning plugin checkout:

```sh
/opt/homebrew/opt/php@8.4/bin/php tests/modernization/session-transaction-manager-contract.php
```

The runner loads the exact `includes/utils/class-session-transaction-manager.php`
from this checkout; it neither copies nor substitutes its implementation. Tiny
WordPress transient/action/filter and handler stubs supply its framework boundary.
GraphQL field and response actions invoke its constructor-registered callbacks.
Request hook registries are isolated while the transient queue is shared.

A acquires its head through the actual field hook and receives the manager's real
timestamp. B enters the same mutation hook behind A. A namespaced `usleep`
override records each requested 500ms wait without sleeping and interrupts after
three waits while A remains valid and held. B must remain blocked until that
synthetic deadline, without returning to its resolver or reloading. This proves
the admission invariant without waiting for the real 30-second stale threshold.
The baseline instead returns after its first recursive queue check: A remains
head, B remains second, and B's resolver can run with no session reload.

Completion cases require matching `addToCart` to remove A, delete an empty queue
or preserve the exact later waiter, clear A's ID, emit completion and save the
dirty session once. A second response callback must be harmless. The later
waiter's synthetic ID uses the real manager's queue insertion method in an
isolated request hook scope. A wrong mutation must not pop A; an unrelated field
and a response without an active transaction must remain no-ops.

Against unchanged retained base `63202b2a9123bdbb18357ad2c0f06b4978ab5cb0`
(`0.21.2.2` asset), the initial baseline is **four failures and one pass**; adding
the positive eventual-admission case gives **five failures and one pass**, with
exit status 1. Desired semantics are assertions, not expected-failure skips.
The runner prints PHP version, loaded source checksum and failure observations.

The positive case holds A through the first wait, completes it through its actual
response hook on the second, then requires B to acquire/reload once without an
extra wait. B's completion must preserve the later C entry and save once. This
rejects an implementation that merely blocks forever.

A seventh regression covers a throwing completion callback: the error must
propagate, local admission must clear after removal of its queue entry, and a
later field must acquire/reload a fresh transaction. The initial six-case repair
failed that new case; its error observation is retained separately. The repair
uses `finally` without suppressing the callback's error.

The local repair tracks actual admission separately from queue membership and
corrects the matching-mutation condition. Passing this cohort establishes those
control-flow repairs only. All seven cases pass on PHP8.4.11 and selected PHP8.2.34. The latter ran in
the existing foundation image with networking disabled, read-only source mounts,
128MiB container/64MiB PHP limits and no database or site bootstrap. Image ID is
`sha256:d6f0988a35bbd8ab8639aeeedc37727807a309c5cecccf5baa2b10718fce2f18`;
source SHA256 is `52435dca92a24f2c319a58fc3b00783123867014d80d885107e550dabf4c0594`. Transient atomicity, initial cart/session loading,
save-before-release, migration and reconnect fencing remain separate acceptance
gates; this source candidate is not staged or released.

This is bounded offline execution of the original class, not real WordPress,
database/transient atomicity, HTTP overlap, cookies, JWTs, cart persistence or
cache qualification. The synthetic wait deadline aborts the fixture; it is not
an application timeout or a proposed production implementation. No dependencies,
Composer, network or Docker are needed. Connected overlap acceptance remains a
separate gate.

## Follow-up acceptance for this maintenance line

This retains the 0.21.2-derived platform API instead of making a major upstream
upgrade a prerequisite for a narrow defect repair. It is an owned maintenance
candidate, not an assertion of upstream security support for an old release.
At the earlier queue-only checkpoint, the retained asset's bundled vendor trees
were unchanged. The later JWT candidate below imports its reviewed bundle
directly; do not run Composer install/update in this asset checkout. No plugin
version or release tag changes.

Before production acceptance, demonstrate actual same-token HTTP overlap with
a held first request and a second request, recording session reads, cart load,
mutation, all session/customer saves and lock release. A later request must
contain both updates. A coherent lifecycle lock must precede persisted reads and
remain owned through every protected save. Atomicity of the transient queue and
its 30-second stale expiry are not established by these regressions.

MySQL connection locks require reconnect fencing: loss of the owning connection
must not permit an automatically retried write outside the lock. A query filter
alone is insufficient if the database client retries beneath it. Guest-to-user
migration additionally needs deterministic multi-key acquisition, destination
persistence before guest retirement and rejection of the retired bearer token.
JWT dependency/key policy, HPOS order storage and store integrations are separate
gates. Implementing those boundaries requires their own reviewed changes and
connected evidence; this small queue repair does not claim to implement them.

## Offline retained session-handler and JWT contracts

```sh
export WL_WOOCOMMERCE_SOURCE="/path/to/unpacked/woocommerce"
export WL_WPGRAPHQL_SOURCE="/path/to/unpacked/wp-graphql"
# Optional: enable three old-library/new-library/rollback compatibility cases.
export WL_WOOGRAPHQL_BASELINE_SOURCE="/path/to/retained-pre-upgrade-plugin"
php tests/modernization/session-handler-contract.php
```

The default handler/JWT source is this owning checkout. Set
`WL_WOOGRAPHQL_SOURCE` to an exact archived plugin source root to run the same
contracts against a preserved baseline. No Composer command, site bootstrap,
database, network or Docker operation is performed. Each case runs in its own PHP
process, with a five-second deadline, 64MiB memory limit and bounded output.
Missing external sources produce exit status 2; contract failures produce 1.

The runner loads the actual retained `QL_Session_Handler` and bundled prefixed JWT
through its `vendor/autoload.php`, actual external WooCommerce `WC_Session` and
`WC_Session_Handler` and `WC_Cart_Session`, and actual WPGraphQL-vendored GraphQL
`UserError`, `ProvidesExtensions` and `FormattedError`. It also loads the actual
candidate `Cart_Session_Operation` when present and dispatches its eight-argument
field hook with real parsed/validated schema, AST and `ResolveInfo` objects,
preserving one operation/schema identity per case. It does not copy or substitute those
implementations. Synthetic boundaries supply WordPress hooks, current identity,
HTTP request classification, cookies, database and cache. All synthetic DB/cache
reads, writes, deletes, session timestamp updates, queue transient operations,
expiration refreshes, customer generation and token/cookie output are counted.
Native persistence uses WooCommerce's real inherited session methods and real
shutdown hook registration, backed by the synthetic DB/cache.

The 83 core cases require an effective string key of at least 32 exact bytes,
constant-then-filter precedence, filter-only configuration, binary/whitespace and
multibyte byte boundaries, and configuration failure taking precedence over a
malformed credential. Throwing/invalid header-name filters must quarantine the
constructor without an early fatal error. A throwing key filter and non-string filter values must fail
closed. A changing key filter must resolve once per handler and remain stable
through verification and issuance; replacing a failed request's header with a
valid token must not revive the quarantined handler. Explicit malformed JSON in
the JWT header or payload must be a credential error. Absent headers admit fresh
sessions; present malformed headers, including
explicit null/false framework values, must remain distinct from absence.
Strict `Session <JWT>` parsing, signatures, expiration, issuer, customer claim
shape and identity binding are checked before any persisted read. Canonical
integer account claims remain compatible only for the independently authenticated
same account. Cross-account, anonymous-account and guest-to-auth JWT use must not
read, relabel, save or retire a session.
An expiration filter that throws on its second invocation (after the real
constructor succeeds) must likewise quarantine initialization without throwing,
report unavailable at the guarded boundary, and permit no later effects.

Rejection must quarantine initialization without throwing before WPGraphQL's
error formatting boundary. Actual registered operation and field hooks must then
throw client-safe `UserError` implementing `ProvidesExtensions`, with
`WL_CART_SESSION_INVALID` or `WL_CART_SESSION_UNAVAILABLE`; the real GraphQL
formatter must preserve that extension code. Subsequent direct save/reload/build
and shutdown probes must stay inert, including queue access. Valid same-account,
fresh authenticated and fresh/returning guest requests must restore/save their
own synthetic data and issue a token verifiable with the exact effective key.

Signing preparation must occur at the first guarded operation/field before
transaction admission or resolver work. A throwing signing filter or a signed
token filter returning an array must report unavailable, prevent initial expiry
writes, and produce no queue/dirty write or response token. Body token getters
may return false or the semantic unavailable error after quarantine; header
callbacks must stay inert. Successful preparation must call each signing filter
once and reuse that output across subsequent body/header reads.
Two review regressions require emitted credentials to satisfy the same wire and
claim contract as incoming credentials: a signed-token filter appending `=`
padding, or a before-sign filter changing fresh account 1's customer claim to
boolean `true`, must fail unavailable before queue/DB/expiry effects and output.
Both cases initially failed against the previously passing 79-case candidate;
its observed effects included queue access, a dirty DB write and token output,
plus an expiry update for the padded returning-guest token.

Six mid-authentication cases change the synthetic identity after successful JWT
initialization in GraphQL and native HTTP modes. Shutdown/save/reload/build must
quietly suppress persistence and token output, preserve or clear the original
binding, and report `WL_CART_SESSION_TRANSITION_INVALID` at the next operation
guard and, in GraphQL mode, the next field hook. Initial credential mismatch
still reports `WL_CART_SESSION_INVALID`.
Two native no-JWT/no-key cases cover independent fresh and returning cookie
sessions, including restore, cookie emission and shutdown persistence. These
native-cookie cases do not claim qualification of login, checkout or account
creation transitions.

Four coupled cases use the actual handler's auth detachment. Direct and magic
memory access, native cookie reinitialization, persistence reload/delete/timestamp
and save entrypoints, shutdown and body/customer/header token access must remain
inert before/after an identity change. The hook registry proves detachment removes
only the captured customer `save` callback at priority 10, retaining an unrelated
callback at that priority. A captured customer **proxy**, explicitly not an actual
`WC_Customer` or customer data store, calls real guarded handler get/set/save
methods directly afterward; original synthetic rows must remain unchanged.
Actual `WC_Cart_Session::persistent_cart_update()` and `persistent_cart_destroy()`
run on a reflection-created instance without its constructor/cart. In detached
and quarantined authenticated cases, both must consult the scoped policy and
perform zero user-meta writes/deletes; no cart access is reached. An accepted
same-account destroy control reaches the metadata boundary, proving the genuine
method is active. User-meta persistence remains a recorded synthetic boundary.

Two bounded checkout probes load the actual `Checkout_Mutation` and invoke its
protected `process_customer()` via reflection after a real ordinary guest
checkout operation passes the coordinator's metadata preflight. Newly posted
`createaccount=true`, or a server registration policy changed after preflight,
must produce the typed transition error before account/auth-cookie/persistence
boundaries. These probes use the actual handler and registration policy method,
with synthetic WordPress options and account/auth boundaries. They do not
execute the full checkout/order/payment lifecycle.
Two further GraphQL/native method probes install a stateful registration filter
after ordinary preflight: its first decision is false and every later decision
would be true. Actual `process_customer()` must evaluate that creation policy
once, complete without account/auth effects, and preserve anonymous identity and
original rows. This catches a separate hold check evaluating false followed by a
second creation check evaluating true; the typed GraphQL hold belongs inside the
actual creation branch before `wc_create_new_customer()`.

With `WL_WOOGRAPHQL_BASELINE_SOURCE`, three additional cases use its genuine
bundled JWT library in a separate subprocess. A fixed synthetic strong-key token
minted by the old library must initialize under the candidate; the candidate's
issued token must verify under the old library's decoder for cryptographic
rollback compatibility. This does not execute the old full handler or native
HTTP rollback. Tokens signed using
the retained public fallback must fail as invalid under a configured strong key,
or unavailable when configuration is absent. Token material passes only through
a private temporary file removed afterward; the helper emits booleans only.
Neither keys, tokens, raw exception messages, variable dumps nor raw stderr are
printed. Output contains assertion text, effect counts and loaded source hashes.

On PHP 8.4.11, the final 86-case cohort against exact baseline revision
`ee555344dd1ff69f4221040cfd181b3e6a1a6251` reports **17 passes and 69 failures**
(exit 1). Its handler SHA256 is
`64e71876acb35f7537321fdcde2122ebe9cc3947f23870684bfb95ae1ce629f7`.
The first repaired-source pass found two remaining failures: explicit null and
false server headers were treated as absence and generated/saved new sessions.
After corrections, preparation and detachment integration, the complete cohort
reports **86 passes and zero failures** (exit 0), with candidate handler SHA256
`2040969fd57b81333c5e40e6bab4898202f5ca1959f20cf6acdd983533841dd3`
and operation helper SHA256
`ca99d09b8d0be8805a2b8a5159d2a471b0d2b5cf8b2063e6ccf66a3df5272775`,
checkout mutation SHA256
`cf08dcbe90bf3e56a2bacc1d5cfc12a672f427c53fa679d9ae7eb56121f8e50f`,
and bundled candidate JWT SHA256
`b9987e23ad24db1c8a7b8fb4a5aa9cfbe50cf046cdc198770c274c15daff9a77`.
The same current source and fixtures pass on selected PHP 8.2.34: **86 handler,
56 operation, five checkout and seven manager cases**, all exit 0 without stderr.
These runs used the existing foundation image
`sha256:d6f0988a35bbd8ab8639aeeedc37727807a309c5cecccf5baa2b10718fce2f18`,
networking disabled, read-only source mounts and ephemeral containers removed
afterward. No site was bootstrapped. This remains offline component evidence.
The earlier 79-case handler and 47-case operation cohorts also passed on selected
PHP 8.2.34 against handler
`4ba35f67a52e6aefb597c002fa6f6c7d76e2a16fef82bac4fa6d7f2f58049e7d`
in the existing foundation
image, with networking disabled, read-only source mounts and an ephemeral tmpfs
for compatibility fixtures. The container was removed afterward; the foundation
site was not started. This is offline component execution, not connected site
acceptance. Those results are historical after the emitted-token review repair
and later source changes; they do not qualify the expanded candidate above.
The compatibility baseline JWT SHA256 is
`9a79b419d63026e2009ec5900245dbe2ae8b5b40b9a65c487e6d4f4b0ba51d31`.
Later source changes require a new run; these hashes identify the observed pass.
The runner verifies each child's source hashes against the parent snapshot and
fails if source files change during or between isolated cases.

The separate `session-operation-contract.php` runner has 56 passing cases on PHP
8.4.11, using the actual external GraphQL Parser/Schema/Executor/ResolveInfo,
WPGraphQL `InstrumentSchema` and `WPMutationType::get_resolver()` through a minimal
subclass without a TypeRegistry bootstrap. It exercises operation qualification,
account-transition selection and completion hooks through a recording handler
and synthetic auth callbacks. Run it with `WL_WPGRAPHQL_SOURCE` set, using
`php tests/modernization/session-operation-contract.php`. Those substitutes mean
it does not establish real provider authentication, WooCommerce customer data
store behavior or connected login/checkout acceptance. The coupled handler cases
above add actual handler/memory/persistence-fence evidence to that separate cohort.

The separate `checkout-session-error-contract.php` has five passing cases on PHP
8.4.11. It executes the actual checkout closure through real GraphQL execution
and formatting, with actual `AppContext` instantiated via reflection and
controlled helper/order/factory/hooks. Before-order unavailable errors must not
create/retrieve/purge an order; unavailable, invalid and transition errors after
a simulated durable order must preserve the exact typed error/extension and
avoid purge; the success payload must remain unchanged. This establishes closure
control flow with substituted order boundaries, not actual order storage or
payments. Run it with `WL_WPGRAPHQL_SOURCE` and
`php tests/modernization/checkout-session-error-contract.php`.

This is bounded offline component evidence. WordPress bootstrapping, real auth,
real DB/cache behavior, simultaneous HTTP requests, end-user cookie behavior,
checkout/login parity, cross-process lock atomicity, HPOS and store integrations
remain separate acceptance gates. Synthetic identities and successful fixture
writes establish no actual WordPress, database or HTTP acceptance.
