<?php

declare(strict_types=1);

namespace Survos\ClaimsBundle\Service;

use Survos\ClaimsBundle\Entity\Claim;
use Survos\DataContracts\Vocabulary\DcTerms;
use Survos\DataContracts\Vocabulary\ItemField;
use Survos\DataContracts\Vocabulary\MuseumVocab;

/**
 * Flattens a ClaimAggregator result onto a normalized JSONL row.
 *
 * Scalar predicates: winning value replaces the corresponding row field.
 * List predicates:   items[] are extracted into arrays keyed by MuseumVocab
 *                    short codes (per, pla, obj, org) — the same codes the
 *                    normalizer uses for entity JSONL files (per.jsonl, etc.).
 */
final class ClaimProjector
{
    /**
     * @param array<string, mixed> $row
     * @param array<string, array{value: mixed, confidence: int, basis: ?string, source: string, items?: list<array{value:mixed}>}> $claims
     * @return array<string, mixed>
     */
    public function project(array $row, array $claims): array
    {
        foreach (self::SCALAR_MAP as $predicate => $field) {
            if (isset($claims[$predicate])) {
                $row[$field] = $claims[$predicate]['value'];
            }
        }

        foreach (self::LIST_MAP as $predicate => $field) {
            if (isset($claims[$predicate]['items'])) {
                $row[$field] = array_column($claims[$predicate]['items'], 'value');
            }
        }

        return $row;
    }

    private const SCALAR_MAP = [
        DcTerms::TITLE->value       => ItemField::TITLE,
        DcTerms::DESCRIPTION->value => ItemField::DESCRIPTION,
        DcTerms::ABSTRACT->value    => ItemField::DESCRIPTION,
        ItemField::DENSE_SUMMARY    => ItemField::DENSE_SUMMARY,
        DcTerms::DATE->value        => ItemField::DATE,
        DcTerms::LANGUAGE->value    => ItemField::LANGUAGE,
        DcTerms::TYPE->value        => ItemField::CONTENT_TYPE,
    ];

    private const LIST_MAP = [
        DcTerms::SUBJECT->value    => MuseumVocab::SUBJECT,        // obj
        DcTerms::SPATIAL->value    => MuseumVocab::PLACE,          // pla
        Claim::PRED_PERSON         => MuseumVocab::PERSON,         // per
        Claim::PRED_ORGANISATION   => MuseumVocab::ORGANISATION,   // org
    ];
}
