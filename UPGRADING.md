# Upgrading

## 0.1.x to 0.2.0

Version 0.2.0 requires `builtbyberry/laravel-swarm:^0.27` and `laravel/ai:^1.0`.
It no longer supports core 0.20–0.26 or AI 0.x. Upgrade the application's core
and native AI dependencies together, following core's upgrade guide, before
selecting this companion. Applications retaining older dependencies should
remain on a compatible 0.1.x companion release.

The companion's PHP 8.4+ and Laravel 13 requirements remain. Its public
`Embedder` stays text-only, and `VectorMemoryReader::search(MemoryScope $scope,
string $query, int $limit = 5): string` is unchanged. Existing memory and vector
rows are preserved; this release adds no migration or configuration key.
Retain the configured provider/model and dimensions when upgrading: the
PostgreSQL `vector(N)` column retains its existing width. Changing providers or
vector width needs its own application migration/re-embedding plan.

AWS SDK and Laravel MCP remain optional. HTTP-backed OpenAI/Voyage embeddings
work without them. Native AI 1.0 metadata conflicts with AWS SDK below 3.369.1
and MCP below 1.0; these are native compatibility floors, not advice to install
an old SDK version. Choose a security-patched supported SDK when using Bedrock.
Selecting Bedrock without its SDK returns installation guidance. This release's
wire fixtures do not certify AWS SDK transport or add native MCP behavior.

The old compatibility lanes are intentionally removed because their core
constraints cannot solve with AI 1.x. Six CI lanes retain minimum/current AI1,
PHP 8.4/8.5 scan, and PHP 8.4 real pgvector coverage. Temporary pinned core 0.27
candidate metadata is only used by test fixtures. Do not copy it into application
Composer configuration; published installation is verified separately after
release. See [the evidence and limits](docs/ai-1-compatibility-evidence.md).
