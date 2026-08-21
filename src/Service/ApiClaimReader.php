<?php

declare(strict_types=1);

namespace Survos\ClaimsBundle\Service;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * {@see ClaimReaderInterface} over mediary's HTTP API instead of a direct Postgres connection.
 *
 * Why this exists: a reader app (zm, a public site) displays claims it never writes, yet the DBAL
 * reader made it hold a Postgres DSN — meaning network reachability to the database, a readonly
 * role to provision, and a credential to rotate, in every app. Over HTTP the app holds a URL and a
 * token, and mediary remains the only process that touches the claims database.
 *
 * Returns the SAME row shape as {@see ClaimReader} (the `claim` table's columns, `value` decoded),
 * so swapping transports is a config change and callers cannot tell the difference.
 *
 * Failure policy: a read failure returns empty rather than throwing. Claims are supplementary
 * display data — a mediary hiccup should degrade a page, not 500 it. Failures are logged at
 * warning so they stay visible instead of silently looking like "no claims". The one exception is
 * {@see countForSubject()}, which returns 0, matching the DBAL reader's behaviour on an empty set.
 */
final class ApiClaimReader implements ClaimReaderInterface
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly ?string $baseUri = null,
        private readonly ?string $token = null,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public function isAvailable(): bool
    {
        return null !== $this->baseUri && '' !== $this->baseUri;
    }

    public function forSubjects(array $subjectIds, ?string $scope = null): array
    {
        if ([] === $subjectIds) {
            return [];
        }

        return $this->rows('/api/claim-store/subjects', [
            'ids' => array_values($subjectIds),
            'scope' => $scope,
        ]);
    }

    public function manifestedForSubjects(array $subjectIds, ?string $scope = null): array
    {
        if ([] === $subjectIds) {
            return [];
        }

        // Manifesting is "first row per (subject,predicate) from a created_at DESC ordering", which
        // the DBAL reader does in PHP over forSubjects(). Doing it server-side matters here: it is
        // the difference between transferring every historical claim and transferring only the
        // winners, and revision history is exactly the kind of data that grows without bound.
        return $this->rows('/api/claim-store/subjects', [
            'ids' => array_values($subjectIds),
            'scope' => $scope,
            'manifested' => 1,
        ]);
    }

    public function forScope(string $scope): array
    {
        return $this->rows('/api/claim-store/scope', ['scope' => $scope]);
    }

    public function runsForScope(string $scope): array
    {
        return $this->rows('/api/claim-store/runs', ['scope' => $scope]);
    }

    public function countForSubject(string $subjectId, ?string $scope = null): int
    {
        $payload = $this->request('/api/claim-store/count', [
            'id' => $subjectId,
            'scope' => $scope,
        ]);

        return (int) ($payload['count'] ?? 0);
    }

    /**
     * @param array<string,mixed> $query
     * @return list<array<string,mixed>>
     */
    private function rows(string $path, array $query): array
    {
        $payload = $this->request($path, $query);
        $rows = $payload['rows'] ?? [];

        return is_array($rows) ? array_values($rows) : [];
    }

    /**
     * @param array<string,mixed> $query
     * @return array<string,mixed>
     */
    private function request(string $path, array $query): array
    {
        if (!$this->isAvailable()) {
            return [];
        }

        // Drop nulls so an unset $scope means "no filter" rather than "scope must be null" —
        // matching the DBAL reader, which omits the WHERE clause entirely.
        $query = array_filter($query, static fn ($v) => null !== $v && '' !== $v);

        $headers = ['Accept' => 'application/json'];
        if (null !== $this->token && '' !== $this->token) {
            $headers['Authorization'] = 'Bearer ' . $this->token;
        }

        try {
            $response = $this->httpClient->request('GET', rtrim($this->baseUri, '/') . $path, [
                'query' => $query,
                'headers' => $headers,
            ]);

            $status = $response->getStatusCode();
            if (200 !== $status) {
                $this->logger->warning('claims API returned HTTP {status} for {path}', [
                    'status' => $status,
                    'path' => $path,
                ]);

                return [];
            }

            return $response->toArray(false);
        } catch (HttpExceptionInterface|\JsonException $e) {
            $this->logger->warning('claims API request failed for {path}: {message}', [
                'path' => $path,
                'message' => $e->getMessage(),
            ]);

            return [];
        }
    }
}
