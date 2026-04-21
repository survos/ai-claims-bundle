# Integration guide

Wiring the bundle into a Symfony app, end-to-end.

## 1. Install

```bash
composer require survos/ai-claims-bundle
```

Flex registers the bundle in `config/bundles.php` automatically.

The bundle prepends its Doctrine ORM mapping automatically, so the
`Claim` entity is discovered without extra app configuration.

## 2. Configure list predicates

Tell the aggregator which predicates produce lists. Everything else is
treated as a scalar.

```yaml
# config/packages/survos_ai_claims.yaml
survos_ai_claims:
    list_predicates:
        - dcterms:subject    # keywords
        - dcterms:spatial    # places
        - foaf:Person
        - ssai:speculation
```

Missing entries default to scalar — safe but might collapse lists, so
register every list-valued predicate the app uses.

## 3. Create the table

Dev:

```bash
bin/console doctrine:schema:update --force
```

Prod:

```bash
bin/console make:migration
bin/console doctrine:migrations:migrate
```

## 4. Pick a scope convention

The bundle's `scope` column is nullable and app-interpreted. Pick one
convention and apply it everywhere:

- **Tenanted app** — use `'tenant:' . $tenant->code`.
- **User-scoped app** — use `'user:' . $user->getId()`.
- **Global** — use `'global'` or `null`.

Enforce scope at every call site. The bundle only stores; access control
is an app concern.

If the consuming app cascades deletes (e.g. deleting a Tenant should wipe
its claims), register a Doctrine listener:

```php
#[AsDoctrineListener(event: Events::preRemove)]
final class TenantClaimsCleanupListener
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {}

    public function preRemove(PreRemoveEventArgs $args): void
    {
        $entity = $args->getObject();
        if (!$entity instanceof Tenant) {
            return;
        }
        $this->em->createQuery('DELETE Survos\AiClaimsBundle\Entity\Claim c WHERE c.scope = :s')
            ->setParameter('s', 'tenant:' . $entity->code)
            ->execute();
    }
}
```

The bundle does not ship this because it has no opinion on what a "tenant"
is in the consuming app.

## 5. Write claims from your tool

Each AI (or human) tool that produces claims:

```php
use Survos\AiClaimsBundle\Service\ClaimDraft;
use Survos\AiClaimsBundle\Service\ClaimIngestor;
use Survos\DataBundle\Vocabulary\DcTerms;

final class EnrichFromThumbnailTool
{
    public function __construct(
        private readonly ClaimIngestor $ingestor,
        private readonly EntityManagerInterface $em,
    ) {}

    public function runOn(Image $image, string $scope): void
    {
        $result = $this->callLlm($image);  // returns a typed DTO

        $drafts = [
            new ClaimDraft(DcTerms::TITLE->value, $result->title, 0.9,
                basis: $result->titleBasis),
            new ClaimDraft(DcTerms::DESCRIPTION->value, $result->description, 0.8),
            new ClaimDraft(DcTerms::TYPE->value, $result->contentType, 0.95),
        ];

        foreach ($result->keywords as $kw) {
            $drafts[] = new ClaimDraft(
                predicate:  DcTerms::SUBJECT->value,
                value:      $kw['term'],
                confidence: $this->mapConfidence($kw['confidence']),
                basis:      $kw['basis'],
            );
        }

        $this->ingestor->record(
            scope:       $scope,
            subjectType: 'image',
            subjectId:   $image->getId(),
            source:      'enrich_from_thumbnail@1.0',
            drafts:      $drafts,
        );
        $this->em->flush();
    }

    private function mapConfidence(string $level): float
    {
        return match ($level) {
            'high'   => 0.9,
            'medium' => 0.6,
            'low'    => 0.3,
            default  => 0.5,
        };
    }
}
```

## 6. Read the aggregated view

Anywhere you previously read the AI blob, read the aggregator instead:

```php
$view = $aggregator->aggregate('image', $image->getId(), $scope);
$title       = $view['dcterms:title']['value']       ?? null;
$keywords    = $view['dcterms:subject']['value']     ?? [];  // list
$contentType = $view['dcterms:type']['value']        ?? null;
```

For search indexing, the list of contributing sources per claim is
available under `$view[$pred]['items']` — useful for tier-based indexing
(only high-confidence terms in the main index, medium/low as suggestions).

## 7. Export / import

```bash
bin/console claims:export --scope=tenant:rhs > rhs.jsonl
bin/console claims:import --scope=tenant:rhs < rhs.jsonl
```

Shell redirection is the default workflow here on purpose, since it keeps
the commands composable for quick inspection and ad hoc transforms.

If you want file-based JSONL I/O instead of shell redirection, use:

```bash
bin/console claims:export --scope=tenant:rhs --output=rhs.jsonl.gz
bin/console claims:import --scope=tenant:rhs --input=rhs.jsonl.gz
```

Those code paths use `survos/jsonl-bundle`'s `JsonlWriter` and
`JsonlReader`. The plain stdin/stdout path keeps minimal NDJSON handling
for shell use. Import skips existing claim ids by default so rerunning
the same backup is safe.

## 8. Human corrections

No new API — just a different `source`:

```php
$this->ingestor->record(
    scope:       $scope,
    subjectType: 'image',
    subjectId:   $image->getId(),
    source:      'human:' . $user->getEmail(),
    drafts:      [new ClaimDraft(DcTerms::TITLE->value, $corrected, 1.0,
        basis: 'Operator verified.')],
);
```

The aggregator treats this claim like any other. Give humans a higher
default confidence and the aggregator will pick their claim when it
disagrees with the AI.
