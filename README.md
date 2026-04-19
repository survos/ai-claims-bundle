# Survos AiClaimsBundle

Store AI (and human) assertions as **append-only claims** with `confidence`,
`basis`, and `source` — an alternative to opaque AI-result blobs.

An AI output is an assertion, not a fact. Recording it as a structured claim
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
composer require survos/ai-claims-bundle
```

The bundle is a flex-compatible Symfony bundle and auto-registers in
`config/bundles.php`.

One manual step — tell Doctrine where the entity lives, since the bundle
doesn't claim its own entity manager:

```yaml
# config/packages/doctrine.yaml
doctrine:
    orm:
        entity_managers:
            default:
                mappings:
                    SurvosAiClaimsBundle:
                        is_bundle: false
                        type: attribute
                        dir: '%kernel.project_dir%/vendor/survos/ai-claims-bundle/src/Entity'
                        prefix: 'Survos\AiClaimsBundle\Entity'
                        alias: AiClaims
```

Register list-valued predicates so the aggregator projects them correctly:

```yaml
# config/packages/survos_ai_claims.yaml
survos_ai_claims:
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

## Quick start

### Recording a tool run

```php
use Survos\AiClaimsBundle\Service\ClaimDraft;
use Survos\AiClaimsBundle\Service\ClaimIngestor;
use Survos\DataBundle\Vocabulary\DcTerms;

$drafts = [
    new ClaimDraft(DcTerms::TITLE->value,       'Welcome to Ocean City', 0.9,
        basis: "Printed caption reads 'Welcome to Ocean City'."),
    new ClaimDraft(DcTerms::DESCRIPTION->value, 'Beach scene with boardwalk.', 0.8),
    new ClaimDraft(DcTerms::TYPE->value,        'postcard', 0.95),
    new ClaimDraft(DcTerms::SUBJECT->value,     'boardwalk', 0.9),
    new ClaimDraft(DcTerms::SUBJECT->value,     'seaside',   0.8),
    new ClaimDraft('ssai:has_text',             true,        1.0),
];

$runId = $ingestor->record(
    scope:       'tenant:rhs',
    subjectType: 'image',
    subjectId:   $image->getId(),
    source:      'enrich_from_thumbnail@1.0',
    drafts:      $drafts,
);
$em->flush();
```

Prior rows with the same `(scope, subject, source)` are deleted first; the
whole batch shares one `runId`.

### Reading the best-guess view

```php
use Survos\AiClaimsBundle\Service\ClaimAggregator;

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
