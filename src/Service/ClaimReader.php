<?php

declare(strict_types=1);

namespace Survos\ClaimsBundle\Service;

use Doctrine\DBAL\Connection;

/**
 * Read-only DBAL access to the central claims store (mediary's Postgres `claim` table),
 * via the `mediary_ro` connection registered by this bundle in reader-only mode
 * (survos_claims.reader_only: true + MEDIARY_RO_DATABASE_URL). This is the consumer-side
 * counterpart to {@see ClaimIngestor} (the writer):
 * apps (e.g. folio enrich) READ claims here keyed by media id, while only mediary writes.
 *
 * All schema knowledge for reads lives here, so callers depend on these methods rather
 * than the table layout — the same containment babel-bundle uses for translation memory.
 * Raw SQL/DBAL by design (no ORM entities in consumers → no per-app migrations).
 *
 * {@see isAvailable()} guards the optional connection: when the env/role isn't wired the
 * service still exists but reports unavailable, so callers can degrade gracefully.
 */
final class ClaimReader
{
    public function __construct(
        private readonly ?Connection $connection = null,
    ) {
    }

    /** True when the read-only mediary connection is wired (MEDIARY_RO_DATABASE_URL set). */
    public function isAvailable(): bool
    {
        return $this->connection !== null;
    }

    /**
     * All claims for the given subjects (media ids), newest first. Subjects are mediary
     * media ids (deterministic base64-of-url); folio joins them via page.media_id.
     *
     * @param list<string> $subjectIds
     * @return list<array<string,mixed>>
     */
    public function forSubjects(array $subjectIds, ?string $scope = null): array
    {
        if ($subjectIds === []) {
            return [];
        }
        $conn = $this->require();

        $where = ['subject_id IN (:ids)'];
        $params = ['ids' => array_values($subjectIds)];
        $types = ['ids' => \Doctrine\DBAL\ArrayParameterType::STRING];
        if ($scope !== null && $scope !== '') {
            $where[] = 'scope = :scope';
            $params['scope'] = $scope;
        }

        return $conn->executeQuery(
            'SELECT scope, subject_id, predicate, source, value, confidence, basis, run_id, created_at
             FROM claim WHERE ' . implode(' AND ', $where) . ' ORDER BY subject_id, created_at DESC',
            $params,
            $types,
        )->fetchAllAssociative();
    }

    /**
     * The winning (manifested) claim per (subject, predicate): the most recent value
     * across sources. This is what enrich folds inline into Item/Page records.
     *
     * @param list<string> $subjectIds
     * @return list<array<string,mixed>>
     */
    public function manifestedForSubjects(array $subjectIds, ?string $scope = null): array
    {
        $seen = [];
        $out = [];
        foreach ($this->forSubjects($subjectIds, $scope) as $row) {
            // forSubjects() is ordered created_at DESC, so the first per (subject,predicate) wins.
            $key = $row['subject_id'] . '|' . $row['predicate'];
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[] = $row;
        }

        return $out;
    }

    /** Number of claims for a single subject (media id). */
    public function countForSubject(string $subjectId, ?string $scope = null): int
    {
        $conn = $this->require();
        $where = ['subject_id = :id'];
        $params = ['id' => $subjectId];
        if ($scope !== null && $scope !== '') {
            $where[] = 'scope = :scope';
            $params['scope'] = $scope;
        }

        return (int) $conn->executeQuery(
            'SELECT COUNT(*) FROM claim WHERE ' . implode(' AND ', $where),
            $params,
        )->fetchOne();
    }

    private function require(): Connection
    {
        if ($this->connection === null) {
            throw new \RuntimeException(
                'ClaimReader has no connection. Set MEDIARY_RO_DATABASE_URL so media-bundle '
                . 'registers the read-only `mediary_ro` connection.',
            );
        }

        return $this->connection;
    }
}
