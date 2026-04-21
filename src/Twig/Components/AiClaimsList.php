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
}
