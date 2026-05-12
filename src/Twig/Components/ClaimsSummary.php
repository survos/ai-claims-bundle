<?php

declare(strict_types=1);

namespace Survos\ClaimsBundle\Twig\Components;

use Survos\ClaimsBundle\Service\ClaimAggregator;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

#[AsTwigComponent('ClaimsSummary', template: '@SurvosClaims/components/ClaimsSummary.html.twig')]
final class ClaimsSummary
{
    public ?object $subject = null;

    public ?string $subjectType = null;

    public ?string $subjectId = null;

    public ?string $scope = null;

    public function __construct(private readonly ClaimAggregator $aggregator) {}

    /** @return array<string, array<string, mixed>> */
    public function summary(): array
    {
        [$type, $id] = $this->resolve();
        if ($type === null || $id === null) {
            return [];
        }

        return $this->aggregator->aggregate($type, $id, $this->scope);
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
