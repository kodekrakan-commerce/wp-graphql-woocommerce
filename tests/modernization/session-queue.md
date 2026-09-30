# Session queue candidate: bounded evidence and qualification hold

This is a source-only compatibility patch over the imported official WooGraphQL
1.0.3 asset, baseline `db04ae143e0def0c1dac3bf2c55e1f89a2f3a295`. It does not
qualify or activate the candidate. The platform B1a fixture remains on
WooGraphQL 0.21.2.2 until the integrated candidate passes its remaining gates.

Run the native regressions without WordPress, Docker, Composer or vendor changes:

```sh
php -l includes/utils/class-session-transaction-manager.php
php tests/modernization/session-queue.php
```

The harness loads the actual `Session_Transaction_Manager` and
`QL_Session_Handler` source. WordPress hooks/transients, WooCommerce persistence,
cart/customer and MySQL connections are boundary doubles. It explicitly switches
between independent request/connection contexts and advances a controlled clock
when a manager waits. Queue writes assert successful mutex ownership. It tests:

- Preserved timestamp fractions, equal-clock ID uniqueness and chronological sort.
- Two interleaved request managers, per-mutation persistence, request-wide batch
  ownership, and refresh of the final cart and customer before a waiter runs.
- An alive owner older than the timeout, rejected waiters, rejected later batch
  fields, and nonowner shutdown that removes only its own queued entry.
- An older request arriving after admission without displacing the active head.
- Failed queue-lock acquisition without writes, reload or mutation execution.
- Replacement of a stale head immediately before lock acquisition, without
  accidentally evicting the replacement.
- Abandoned-owner recovery and clearing a previously populated in-memory cart
  when the authoritative cart has become empty.
- Failed cleanup mutex acquisition, retained orphan metadata and released
  execution ownership.
- Lost execution ownership before later mutation/shutdown persistence.
- Forced persistent-cache reads and targeted request-local `notoptions` repair.
- Admission coverage for `checkout`, `addCartItems` and `fillCart`.
- Enqueue/admission queue persistence failures that reject execution and stale saves.
- The production QL handler non-HTTP `init()` registers `save_data` at priority
  20; admission/ownership failures remove that callback and prevent dirty-cart
  shutdown persistence. The new regression failed before removing priority 20.

Owner run: PHP 8.4.11, all 13 scenarios pass. PHP 8.2 and actual HTTP/WordPress/
WooCommerce/MySQL/cache integration are required separately; these doubles do
not establish those integrations or physical parallel execution.

## Ownership and recovery contract

A short MySQL advisory mutex protects all queue reads/changes. A distinct
execution advisory lock is acquired only by the admitted head and remains held
on its database connection through the entire HTTP request. A later mutation in
that request keeps its in-memory state and ownership; each completed mutation
still saves dirty session data. Shutdown saves dirty data before releasing
execution ownership. The queue and both lock keys capture the original session
identity so authentication cannot move cleanup to a different queue.

Queue timestamps order pending entries and provide abandoned-entry recovery.
An admitted head cannot be displaced by a late older ID. Expiration alone never
permits eviction: recovery checks the current head while holding the queue
mutex and must successfully acquire the execution lock first. Thus a live slow
checkout stays protected, even when its queue transient expires (the execution
lock is authoritative). A terminated request's DB connection releases its lock;
a later request can recover its stale queue entry. Missing timestamps from
legacy entries count as stale, subject to the same execution-lock requirement.

Admission waits are bounded by the existing
`woographql_session_transaction_timeout` filter (default 30 seconds, minimum
1 second), plus at most the final one-second queue mutex attempt. A mutex failure
fails immediately. Failure returns a retryable GraphQL error, rejects subsequent
session-mutating fields, and removes this session's shutdown save hooks at both QL priorities (10 and 20)
and the customer's shutdown save hook
so pre-admission data cannot overwrite a successful owner. A priority -1
WordPress shutdown guard checks execution ownership before WooCommerce saves;
the native shutdown callback subsequently removes only this request's queue
entry and releases its execution lock. Cleanup that cannot acquire its mutex
retains orphan metadata for guarded recovery instead of changing the queue
unlocked. No shutdown cleanup should be interpreted as mutation success.

The first admitted mutation reloads QL session data, replaces the pre-wait
customer and its shutdown save callback, clears cart contents, then restores
cart state through WooCommerce's current `get_cart_from_session()` API. The
explicit clear is necessary because WC 10.6's loader does not replace cart
contents when its restored cart is empty. There is no repeated session reload
between owned batch mutations that could discard current in-memory changes.

## Mandatory integration holds and tradeoffs

- **Guest/user migration is not serialized.** `QL_Session_Handler::init()` runs
  `init_session_token()` before creating the manager. That method can migrate
  guest data and call `save_data($guest_id)` before admission; `save_if_dirty()`
  can also change the customer ID while the old execution key is held. Frozen
  keys fix cleanup only. Migration-aware locking and connected login/session
  transfer tests are mandatory before activation or release.
- **Authoritative session absence needs handler normalization.** Existing
  `reload_data()` replaces `_data` only if `get_session()` returns an array.
  Deleted/migrated-away rows can return false and retain old `_data`. That
  handler change has separate ownership; this bounded manager patch cannot
  qualify the absent-session path by itself.
- **Database reconnect is not fenced mid-mutation.** Ownership checks before
  another batch mutation, completion and shutdown detect a lost connection,
  but `$wpdb` can reconnect and retry a write within a running mutation. Named
  locks are connection-scoped and cannot fence that already-retried write.
  Stable DB connections are a prerequisite; reconnect-safe persistence requires
  a wider storage contract and remains a qualification limitation.
- MySQL/MariaDB must support multiple named locks held on one connection.
  Connection pooling that changes the physical DB connection between calls is
  incompatible. Native MySQL lock behavior, fatal-error/shutdown ordering and
  timeout responses must be checked in the actual qualification runtime.
- Mutations taking longer than the configured wait window can cause concurrent
  requests to return the retryable busy error. This is a deliberate availability
  tradeoff; they do not take a live owner's cart by timeout.
- Upgrades require a quiescent request boundary. Legacy workers do not hold the
  new execution lock, so this protocol cannot establish their liveness while
  both old and new plugin code are running.
- Current advisory lock names retain the inherited customer hash namespace.
  Stores sharing a MySQL server can serialize identical customer IDs across
  stores unnecessarily; adding a database/blog namespace requires coordinated
  protocol rollout and is deferred from this bounded correction.
- Persistent-cache reads request an authoritative refresh. Real cache-drop-in
  behavior and outages still require integrated verification. Initial session
  initialization, read-only requests and third-party session writes outside the
  listed mutation hooks are not made transactional by this change.
