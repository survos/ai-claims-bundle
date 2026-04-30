<?php

declare(strict_types=1);

namespace Survos\AiClaimsBundle\Twig\Components;

use Survos\AiClaimsBundle\Entity\Claim;
use Survos\AiClaimsBundle\Repository\ClaimRepository;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

/**
 * Generic claims list for any subject (image, item, …).
 *
 * Usage:
 *   <twig:AiClaimsList subjectType="image" :subjectId="image.id"/>
 *   <twig:AiClaimsList :subject="image"/>       {# type derived from class short-name #}
 *   <twig:AiClaimsList :subject="image" source="enrich_from_thumbnail@1.0"/>
 */
#[AsTwigComponent('AiClaimsList', template: '@SurvosAiClaims/components/AiClaimsList.html.twig')]
final class AiClaimsList
{
    public ?object $subject = null;

    public ?string $subjectType = null;

    public ?string $subjectId = null;

    public ?string $scope = null;

    /** Optional single-source filter, e.g. 'enrich_from_thumbnail@1.0'. */
    public ?string $source = null;

    /**
     * Optional comma-separated list of sources to include, e.g.
     * 'ocr_mistral@1.0,transcribe_handwriting@1.0,extract_metadata@1.0'.
     * Takes precedence over $source when both are set.
     */
    public ?string $sources = null;

    /**
     * Optional comma-separated list of predicates to include, e.g.
     * 'dcterms:subject,foaf:Person,dcterms:spatial'. Applied after the
     * source filter; empty = all predicates.
     */
    public ?string $predicates = null;

    /**
     * Optional comma-separated list of predicates to exclude. Useful for keeping
     * audit views compact when bulky text claims have a dedicated panel.
     */
    public ?string $excludePredicates = null;

    /**
     * Render style: 'table' (default — grouped by source, every field visible)
     * or 'chips' (value + basis + confidence marker; good for tag-like predicates).
     */
    public string $layout = 'table';

    public function __construct(private readonly ClaimRepository $claims) {}

    /** @return list<Claim> */
    public function getClaims(): array
    {
        [$type, $id] = $this->resolve();
        if ($type === null || $id === null) {
            return [];
        }

        if ($this->sources !== null && trim($this->sources) !== '') {
            $wanted = array_map('trim', explode(',', $this->sources));
            $wanted = array_values(array_filter($wanted, static fn($s) => $s !== ''));
            if ($wanted === []) {
                $claims = $this->claims->findForSubject($type, $id, $this->scope);
            } else {
                $claims = [];
                foreach ($wanted as $src) {
                    foreach ($this->claims->findForSubjectAndSource($type, $id, $src, $this->scope) as $c) {
                        $claims[] = $c;
                    }
                }
            }
        } elseif ($this->source !== null) {
            $claims = $this->claims->findForSubjectAndSource($type, $id, $this->source, $this->scope);
        } else {
            $claims = $this->claims->findForSubject($type, $id, $this->scope);
        }

        if ($this->predicates !== null && trim($this->predicates) !== '') {
            $wantedPred = array_flip(array_values(array_filter(
                array_map('trim', explode(',', $this->predicates)),
                static fn($s) => $s !== '',
            )));
            if ($wantedPred !== []) {
                $claims = array_values(array_filter(
                    $claims,
                    static fn(Claim $c) => isset($wantedPred[$c->predicate]),
                ));
            }
        }

        if ($this->excludePredicates !== null && trim($this->excludePredicates) !== '') {
            $excludedPred = array_flip(array_values(array_filter(
                array_map('trim', explode(',', $this->excludePredicates)),
                static fn($s) => $s !== '',
            )));
            if ($excludedPred !== []) {
                $claims = array_values(array_filter(
                    $claims,
                    static fn(Claim $c) => !isset($excludedPred[$c->predicate]),
                ));
            }
        }

        return $claims;
    }

    public function getResolvedSubjectType(): ?string
    {
        return $this->resolve()[0];
    }

    public function getResolvedSubjectId(): ?string
    {
        return $this->resolve()[1];
    }

    /**
     * @return list<array{
     *     label: string,
     *     confidence: float,
     *     basis: ?string,
     *     predicate: string,
     *     source: string
     * }>
     */
    public function getTagClaimViews(): array
    {
        $claims = $this->getClaims();
        $rows = [];

        for ($i = 0, $count = count($claims); $i < $count; $i++) {
            $claim = $claims[$i];
            $label = $this->claimLabel($claim->value);

            if ($legacy = $this->legacyTripletView($claims, $i, $label)) {
                $rows[] = $legacy;
                $i += 2;
                continue;
            }

            if ($this->confidenceWord($claim->value) !== null) {
                continue;
            }

            $rows[] = [
                'label' => $label,
                'confidence' => $claim->confidence,
                'basis' => $claim->basis,
                'predicate' => $claim->predicate,
                'source' => $claim->source,
            ];
        }

        usort($rows, static fn(array $a, array $b): int => $b['confidence'] <=> $a['confidence']);

        return $rows;
    }

    /**
     * Older imports sometimes stored keyword triplets as three adjacent claims:
     * term, confidence word, basis. Render those as one tag claim without
     * treating "medium" or "high" as tags.
     *
     * @param list<Claim> $claims
     * @return array{label: string, confidence: float, basis: ?string, predicate: string, source: string}|null
     */
    private function legacyTripletView(array $claims, int $offset, string $label): ?array
    {
        if ($offset + 2 >= count($claims)) {
            return null;
        }

        $claim = $claims[$offset];
        $confidence = $this->confidenceWord($claims[$offset + 1]->value);
        if (
            $confidence !== null
            && $this->isSameClaimSeries($claim, $claims[$offset + 1])
            && $this->isSameClaimSeries($claim, $claims[$offset + 2])
        ) {
                $rows[] = [
                    'label' => $label,
                'confidence' => $confidence,
                'basis' => $this->claimLabel($claims[$offset + 2]->value),
                    'predicate' => $claim->predicate,
                    'source' => $claim->source,
                ];
        }

        return null;
    }

    /** @return array{0: ?string, 1: ?string} */
    private function resolve(): array
    {
        $type = $this->subjectType;
        $id   = $this->subjectId;

        if (($type === null || $id === null) && $this->subject !== null) {
            $type ??= strtolower((new \ReflectionClass($this->subject))->getShortName());
            if ($id === null && method_exists($this->subject, 'getId')) {
                $id = (string) $this->subject->getId();
            } elseif ($id === null && property_exists($this->subject, 'id')) {
                $id = (string) $this->subject->id;
            }
        }

        return [$type, $id];
    }

    private function isSameClaimSeries(Claim $a, Claim $b): bool
    {
        return $a->predicate === $b->predicate && $a->source === $b->source;
    }

    private function confidenceWord(mixed $value): ?float
    {
        if (!is_string($value)) {
            return null;
        }

        return match (strtolower(trim($value))) {
            'high' => 0.9,
            'medium' => 0.6,
            'low' => 0.3,
            default => null,
        };
    }

    private function claimLabel(mixed $value): string
    {
        if (is_array($value)) {
            foreach (['name', 'term', 'label', 'value', 'text', 'claim'] as $field) {
                if (isset($value[$field]) && (is_string($value[$field]) || is_numeric($value[$field]))) {
                    return trim((string) $value[$field]);
                }
            }

            return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '—';
        }

        if ($value === null || $value === '') {
            return '—';
        }

        if (is_bool($value)) {
            return $value ? 'yes' : 'no';
        }

        return trim((string) $value);
    }
}
