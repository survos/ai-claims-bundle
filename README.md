# Survos ClaimsBundle

Store machine, human, and source assertions as **append-only claims** with
`confidence`, `basis`, and `source`.

Generated metadata is an assertion, not a fact. Recording it as a structured claim
lets you:

- know *how confident* the tool was (the `confidence` float)
- know *why* the tool asserted it (the `basis` text)
- know *which tool* produced it, at what version (the `source` string)
- accumulate multiple claims about the same thing and aggregate into a
  best-guess view — humans can later promote or reject individual claims
- export to and import from JSONL for cheap round-trip backup

The bundle is vocab-agnostic (no predicate enum), storage-layer-only
(no inference, no LLM calls), and tenant-agnostic (a nullable `scope`
string that the consumer partitions on).

## Install

```bash
composer require survos/claims-bundle
```

The bundle is a flex-compatible Symfony bundle and auto-registers in
`config/bundles.php`, and the bundle prepends its Doctrine ORM mapping
automatically, like the other Survos bundles.

Register list-valued predicates so the aggregator projects them correctly:

```yaml
# config/packages/survos_claims.yaml
survos_claims:
    list_predicates:
        - dcterms:subject    # keywords
        - dcterms:spatial    # places
        - foaf:Person
```

Generate the migration (or run `doctrine:schema:update --force` in dev):

```bash
bin/console make:migration
bin/console doctrine:migrations:migrate
```

Claims can also be backed up and restored as JSONL:

```bash
bin/console claims:export --scope=tenant:rhs > rhs.jsonl
bin/console claims:import --scope=tenant:rhs < rhs.jsonl
```

Redirection is the default CLI workflow. If you prefer explicit file paths
instead, `--output` and `--input` use `survos/jsonl-bundle`'s
`JsonlWriter` and `JsonlReader`:

```bash
bin/console claims:export --scope=tenant:rhs --output=rhs.jsonl.gz
bin/console claims:import --scope=tenant:rhs --input=rhs.jsonl.gz
```

## Quick start

### Recording a tool run

```php
use Survos\ClaimsBundle\Service\ClaimIngestor;
use Survos\ClaimsBundle\Service\RawClaim;
use Survos\DataContracts\Vocabulary\DcTerms;

$rawClaims = [
    new RawClaim(DcTerms::TITLE->value,       'Welcome to Ocean City', 0.9,
        basis: "Printed caption reads 'Welcome to Ocean City'."),
    new RawClaim(DcTerms::DESCRIPTION->value, 'Beach scene with boardwalk.', 0.8),
    new RawClaim(DcTerms::TYPE->value,        'postcard', 0.95),
    new RawClaim(DcTerms::SUBJECT->value,     'boardwalk', 0.9),
    new RawClaim(DcTerms::SUBJECT->value,     'seaside',   0.8),
    new RawClaim('ssai:has_text',             true,        1.0),
];

$run = $ingestor->record(
    scope:       'tenant:rhs',
    subjectType: 'image',
    subjectId:   $image->getId(),
    source:      'enrich_from_thumbnail@1.0',
    rawClaims:   $rawClaims,
);
$em->flush();
```

Prior rows with the same `(scope, subject, source)` are deleted first; the
whole batch shares one `runId`.

### Reading the best-guess view

```php
use Survos\ClaimsBundle\Service\ClaimAggregator;

$view = $aggregator->aggregate('image', $image->getId(), 'tenant:rhs');

// Scalar predicate — one winner:
$view['dcterms:title']
// → ['value' => 'Welcome to Ocean City', 'confidence' => 0.9,
//    'basis' => "Printed caption …", 'source' => 'enrich_from_thumbnail@1.0']

// List predicate — dedup + union:
$view['dcterms:subject']
// → ['value' => ['boardwalk', 'seaside'], 'confidence' => 0.9,
//    'source' => 'aggregated', 'items' => [...]]
```

## Documentation

- [docs/design.md](docs/design.md) — the claim model and why it exists
- [docs/integration.md](docs/integration.md) — step-by-step consumer wiring

## License

MIT
