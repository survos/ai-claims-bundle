<?php

declare(strict_types=1);

namespace Survos\AiClaimsBundle\Service;

use Survos\AiClaimsBundle\Entity\Claim;
use Survos\AiClaimsBundle\Repository\ClaimRepository;

/**
 * Projects raw claims into a "best-guess per predicate" view.
 *
 * For scalar predicates (one winner): max-confidence wins, most-recent
 * createdAt tiebreaks.
 *
 * For list predicates (keywords, places, etc.): union across claims,
 * deduped on value, confidence = max across contributing sources.
 *
 * The list-predicate set is configurable — consumers register their
 * own list-valued predicates via the constructor.
 */
final class ClaimAggregator
{
    /** @var array<string, true> */
    private readonly array $listPredicateIndex;

    /**
     * @param list<string> $listPredicates Predicates that aggregate as a list
     *                                      (e.g. 'dcterms:subject', 'dcterms:spatial',
     *                                      'ssai:speculation', 'ssai:person').
     */
    public function __construct(
        private readonly ClaimRepository $claims,
        array $listPredicates = [],
    ) {
        $this->listPredicateIndex = array_fill_keys($listPredicates, true);
    }

    /**
     * Return the best-guess claim(s) per predicate for one subject.
     *
     * @return array<string, array{
     *   value: mixed,
     *   confidence: float,
     *   basis: ?string,
     *   source: string,
     *   items?: list<array{value: mixed, confidence: float, basis: ?string, source: string}>,
     * }>
     */
    public function aggregate(string $subjectType, string $subjectId, ?string $scope = null): array
    {
        $rows = $this->claims->findForSubject($subjectType, $subjectId, $scope);

        /** @var array<string, list<Claim>> */
        $byPredicate = [];
        foreach ($rows as $row) {
            $byPredicate[$row->predicate] ??= [];
            $byPredicate[$row->predicate][] = $row;
        }

        $out = [];
        foreach ($byPredicate as $predicate => $claims) {
            $out[$predicate] = $this->isListPredicate($predicate)
                ? $this->projectList($claims)
                : $this->projectScalar($claims);
        }

        return $out;
    }

    private function isListPredicate(string $predicate): bool
    {
        return isset($this->listPredicateIndex[$predicate]);
    }

    /** @param list<Claim> $claims */
    private function projectScalar(array $claims): array
    {
        usort($claims, static function (Claim $a, Claim $b): int {
            if ($a->confidence !== $b->confidence) {
                return $b->confidence <=> $a->confidence;
            }
            return $b->createdAt <=> $a->createdAt;
        });

        $winner = $claims[0];
        return [
            'value'      => $winner->value,
            'confidence' => $winner->confidence,
            'basis'      => $winner->basis,
            'source'     => $winner->source,
        ];
    }

    /** @param list<Claim> $claims */
    private function projectList(array $claims): array
    {
        /** @var array<string, array{value: mixed, confidence: float, basis: ?string, source: string}> */
        $byKey = [];
        foreach ($claims as $c) {
            $key = is_scalar($c->value) ? (string) $c->value : json_encode($c->value, JSON_UNESCAPED_UNICODE);
            if ($key === false) {
                continue;
            }
            if (!isset($byKey[$key]) || $c->confidence > $byKey[$key]['confidence']) {
                $byKey[$key] = [
                    'value'      => $c->value,
                    'confidence' => $c->confidence,
                    'basis'      => $c->basis,
                    'source'     => $c->source,
                ];
            }
        }

        $items = array_values($byKey);
        usort($items, static fn(array $a, array $b): int => $b['confidence'] <=> $a['confidence']);

        return [
            'value'      => array_column($items, 'value'),
            'confidence' => $items[0]['confidence'] ?? 0.0,
            'basis'      => null,
            'source'     => 'aggregated',
            'items'      => $items,
        ];
    }
}
