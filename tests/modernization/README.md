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
The retained asset's bundled vendor trees are unchanged; do not run Composer
install/update in this asset checkout. No plugin version or release tag changes.

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
