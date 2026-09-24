# AI 1.x compatibility evidence

This is candidate evidence for companion v0.2.0, recorded 2026-09-24. It does
not establish publication, Packagist-only installation, AWS SDK transport, or
release readiness. No production runtime, migration, config, or public reader
signature changed. The embedder remains text-only.

## Frozen source and dependency identities

- Companion implementation starts at release scaffold
  `a34c5033c852c6a243f36fe64f9d769441bd0674`, based on main
  `9f33d350e65f05927fca2cf0f2b2141ab026e1ba`.
- Core candidate: `48ad4ef690363ca40ba7d3bd50e63e7fbe76ba4b`.
  CI-only package metadata assigns 0.27.0 to that exact official source/archive.
- Both actual resolutions selected official AI v1.0.0 at
  `101c7ea33cd8569d82570f753fbf38e48b7d3d95`; current equalled minimum on this date.
- Current selected Laravel v13.33.0 at
  `91188a17ceaa3dbace6e8a5f7abd0d042e466359`.
- Minimum selected Laravel v13.16.0 at
  `66d5cdac5afd508dc6519ca59f5cc9b2c93a2b67`.

Each lane resolved separately with its own lock and installed metadata. The
[verification script](../.github/scripts/compatibility.php) checks official git
and archive URLs, immutable references, exact candidate/minimum identity,
lock/installed agreement and compatible native constraints. Production
`composer.json` contains core `^0.27` and AI `^1.0` without repositories,
replacement packages, or dependency version aliases; it was restored after
preparation. The ordinary development branch alias is `0.2.x-dev`.

## Acceptance and executable evidence

| Contract | Tests |
|---|---|
| Native OpenAI/Voyage model, ordered text, requested dimension key and numeric vector response | [LaravelAiEmbedderTest](../tests/Feature/LaravelAiEmbedderTest.php), `native embedding HTTP contracts preserve provider model ordered text and dimension keys` (two datasets) |
| Fixed width rejection; real HTTP failure; empty batch makes no request | Same file, `native wire dimensions are checked before returning an embedding` and `native wire HTTP errors remain failures and empty batches do not call HTTP` |
| Public persistence/query/ranking/update/forget and provider-before-transaction | [NativeEmbeddingCompatibilityTest](../tests/Feature/NativeEmbeddingCompatibilityTest.php), `native HTTP embeddings persist query rank update and forget through the public consumer` |
| Outermost redaction reaches outgoing native input and canonical storage | Same file, `the outer capture policy redacts native outgoing text and persisted memory` |
| Default failure removes stale vector but persists memory; throw preserves both tables; query unavailable | Same file, `native provider failure preserves default storage and removes an old vector`, `native provider failure under throw policy leaves both tables unchanged`, and single-input missing/empty result cases |
| Authorization before ranking, resolved scope id, empty/withheld no-call, defensive result filtering | [SwarmVectorMemoryReaderTest](../tests/Feature/SwarmVectorMemoryReaderTest.php), `the ranker receives only policy authorized entries and their resolved scope id`, `empty or policy withheld scopes never reach the embedder or ranker` |
| Actual database mutations roll back on post-write index failure | [VectorMemoryStoreTest](../tests/Feature/VectorMemoryStoreTest.php), `an index failure rolls back canonical and vector writes on the same connection` (put and forget) |
| Real active index/driver/native vector column and candidate/scope/limit filtering | Retained [VectorIndexTest](../tests/Feature/VectorIndexTest.php); these run in both scan and hosted pgvector jobs |
| Optional SDK absence, missing-SDK guidance and actual native metadata floors | NativeEmbeddingCompatibilityTest's two `optional provider SDKs...` / `native AI metadata...` tests |

Only the HTTP transport is faked in native consumer proofs; native gateway,
embedder, canonical store, index and public reader execute. Requests cannot
escape the exact-route fixtures, cache is disabled, and credentials are fake.
The transaction-level assertion compares to the test's baseline rather than
incorrectly demanding zero inside RefreshDatabase. Existing Run-parent seeding
and foreign keys remain enabled.

## Local gates and hosted boundary

On PHP 8.5.8 with SQLite scan:

| Gate | Current dependencies | Minimum dependencies |
|---|---|---|
| Full `composer test -- --fail-on-skipped --fail-on-incomplete --fail-on-risky` | 46 tests / 127 assertions, pass | 46 tests / 127 assertions, pass |
| Added optional metadata test after approved evidence amendment | 1 test / 11 assertions, pass | 1 test / 11 assertions, pass |
| Dependency guard self-tests | 2 positive lanes / 144 negative controls, pass | Same, pass |
| `composer analyse` | Pass, no errors | Covered by current lane as configured |
| `composer lint` | Pass; amended file formatted and checked separately | Same source |

The final source has 47 tests; the recorded local evidence is the unchanged
46-test suite plus the subsequent isolated metadata test, not a claimed new
47-test full-suite run. Minimum provenance startup reports an upstream
Symfony translation implicit-nullability deprecation on PHP 8.5; the suite
passes with no skips/incomplete/risky tests. No upstream code was patched.

[CI](../.github/workflows/tests.yml) now has six meaningful lanes: PHP 8.4/8.5
minimum/current scan (four), and PHP 8.4 minimum/current PostgreSQL17 native
pgvector (two). Old-core lanes were removed because those advertised ranges
are intentionally dropped and cannot solve with AI 1. All jobs retain
fail-on-skipped/incomplete/risky. Actual PostgreSQL driver/index/vector-column
assertions prevent SQLite from passing as pgvector. Final-head hosted runs,
independent implementation review and merge evidence are still required; no
local PostgreSQL execution is claimed by this document.

## Optional provider evidence and safety boundary

Installed native AI 1 metadata suggests AWS SDK and MCP but requires neither.
Its declared conflicts are AWS `<3.369.1` and MCP `<1.0`; the test reads the
actual installed native manifest and verifies those floor predicates.

The original exact AWS 3.369.1 dry run and older 3.369.0 dry run exited 2 under
Composer advisory `PKSA-4t1p-xpk2-nsss`. They do **not** prove successful minimum
installation or native-floor solver rejection. The original minimum fixture
also omitted Laravel's aggregate replacement and reported unresolved
`illuminate/concurrency`; adding `laravel/framework:^13.16` corrected that
fixture. Original blocked output is retained in the execution packet.

The [primary AWS advisory](https://github.com/aws/aws-sdk-php/security/advisories/GHSA-27qh-8cxx-2cr5)
affects SDK 3.11.7–3.371.3 and identifies 3.371.4 as patched. Consequently the
approved evidence amendment keeps the native minimum as metadata evidence and
uses current patched SDK resolution, without disabling or ignoring advisories.

The corrected disposable consumer requires frozen core 0.27.0, official AI 1.0.0,
Laravel `^13.16` and AWS `^3.369.1`. Running
`composer update --dry-run --no-scripts --no-plugins --no-interaction --no-progress`
passed and selected AWS **3.397.0**; separately retrieved official package
metadata identifies source/archive `8d2c3adc6ab2d7c6160a9034867cd4bd2b535619`.
This is a successful dependency solve, not an SDK installation/transport test.
Ordinary package tests still have neither SDK nor MCP installed; native Bedrock
selection gives installation guidance and makes no HTTP call.

## Controlled fault detection

All ten faults below failed for the targeted assertion and restored green.
Verifier faults caused `Guard accepted negative control`, not merely expected
rejection of malformed input. The lock-agreement fault retained field-presence
checks so missing-field bootstrap errors were not counted as proof. Native
metadata mutation affected the disposable installed manifest only.

| Fault | Focused command selection | Red exit | Restored exit |
|---|---|---|---|
| dimension-forwarding | `tests/Feature/LaravelAiEmbedderTest.php --filter=native embedding HTTP contracts --compact` | 1 | 0 |
| response-extraction | `tests/Feature/LaravelAiEmbedderTest.php --filter=native embedding HTTP contracts --compact` | 1 | 0 |
| authorized-candidates | `tests/Feature/SwarmVectorMemoryReaderTest.php --filter=the ranker receives only --compact` | 2 | 0 |
| atomic-rollback | `tests/Feature/VectorMemoryStoreTest.php --filter=an index failure rolls back --compact` | 1 | 0 |
| core-pin | `.github/scripts/compatibility-test.php <production-manifest>` | 255 | 0 |
| core-version | `.github/scripts/compatibility-test.php <production-manifest>` | 255 | 0 |
| ai-minimum | `.github/scripts/compatibility-test.php <production-manifest>` | 255 | 0 |
| ai-range | `.github/scripts/compatibility-test.php <production-manifest>` | 255 | 0 |
| lock-installed | `.github/scripts/compatibility-test.php <production-manifest>` | 255 | 0 |
| native-optional-metadata | `tests/Feature/NativeEmbeddingCompatibilityTest.php --filter=native AI metadata --compact` | 1 | 0 |

For each probe the source bytes were saved, mutated, tested, restored exactly,
and the same focused command rerun. The following SHA-256 values are the
identical before/restored identities; the third column records the faulty bytes.

| Fault | Before = restored SHA-256 | Faulty SHA-256 |
|---|---|---|
| dimension-forwarding | `46e10a3535867ff8219bb976deea229b138988231fb8b88ad7578fdecf3bcf7a` | `9ce944f75cdb4d761b51ca60bebd6e2d2e000f6eb9fc2254fa318127842cabf6` |
| response-extraction | `46e10a3535867ff8219bb976deea229b138988231fb8b88ad7578fdecf3bcf7a` | `18e7e0beb5983541b7d2b0878e90e9c594156489136d325886d84a2c9660822c` |
| authorized-candidates | `d4b8950647af0231db7b350a738024055259194c294e5640225f5db129a1f3b7` | `90a58e39e631ca42a3a99ad9d87f17f0d70e7e5d6b1c7b94ca113962a3f746f2` |
| atomic-rollback | `13f79a7d839e67debb27162f9440fa17e963b65ea2babd074f610e9e72b4d4ab` | `e6ad07a8583193438ce32d252af18afcf0921e7f27545edae05ccfbb00064121` |
| core-pin | `a3fe6bde7f1d5e8cbc8e55fc335b00d234e5bccc146aed72922458d2895096d9` | `487793673196e92ef3d3f63abde1940f33ebbc81fb3ceb8abf1279ccd3556086` |
| core-version | `a3fe6bde7f1d5e8cbc8e55fc335b00d234e5bccc146aed72922458d2895096d9` | `ca61260fb504370046f8241f9776aa92a14c04efbdca695b6708fe3ddfdda873` |
| ai-minimum | `a3fe6bde7f1d5e8cbc8e55fc335b00d234e5bccc146aed72922458d2895096d9` | `1bc6a38218bc4542757f67374e25440542ac189d7fa1f3eeeb9f2b09b47f8bfd` |
| ai-range | `a3fe6bde7f1d5e8cbc8e55fc335b00d234e5bccc146aed72922458d2895096d9` | `972edaf7fedbc23df5d6ff91f88efad14ea7d7fea9e718a2d5f0303f3c75bf37` |
| lock-installed | `a3fe6bde7f1d5e8cbc8e55fc335b00d234e5bccc146aed72922458d2895096d9` | `4837b5419b3bb00ff889478ba411d97d452ade56d3e56fc5e4a4c79aabeaca89` |
| native-optional-metadata | `b80b0098fe68b6ea5bcd741ddcc3c508c64516f363738bdf5384b27af4769943` | `3cf31419b0220991688179263ca5f53dcce7019d184330275b8654e2b07435a4` |

## Review and evidence handoff

Independent plan reviewer `c4_pressure_vector` approved plan hash
`f40872bff073218aaa076b05266b6b9db293246584830a900adca02528274cc5` after C4-VP1,
and optional-dependency amendment hash
`0c8135ec181111bc8d9d45cf7982d58ad059d3b299ee69c72ecc7f1b0f4dde86`.
These approvals concern plans, not implementation approval.

The execution packet `ai-1.0-adoption-2026-09-23/evidence/C4-vector` retains
separate locks/installed identities, full gate logs, exact fault logs/hashes,
original advisory refusals, patched solve output and optional metadata.
The component PR/lifecycle owner attaches that packet and final-head CI/review
links before merging to `release/v0.2.0`. No main merge, tag or publication is
included in this component.
