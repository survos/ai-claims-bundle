<?php

declare(strict_types=1);

namespace Survos\ClaimsBundle\Twig\Components;

use Survos\ClaimsBundle\Entity\Claim;
use Survos\ClaimsBundle\Repository\ClaimRepository;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

#[AsTwigComponent('OcrClaimsPanel', template: '@SurvosClaims/components/OcrClaimsPanel.html.twig')]
final class OcrClaimsPanel
{
    public ?object $subject = null;

    public ?string $subjectType = null;

    public ?string $subjectId = null;

    public ?string $scope = null;

    public function __construct(private readonly ClaimRepository $claims) {}

    /** @return list<Claim> */
    public function ocrClaims(): array
    {
        [$type, $id] = $this->resolve();
        if ($type === null || $id === null) {
            return [];
        }

        $wanted = [
            'ai:observationProse' => true,
            Claim::PRED_HTR_TEXT => true,
            Claim::PRED_PRINTED_TEXT => true,
            Claim::PRED_OCR_TEXT => true,
        ];

        $claims = array_values(array_filter(
            $this->claims->findForSubject($type, $id, $this->scope),
            static fn(Claim $claim): bool => isset($wanted[$claim->predicate]) && is_string($claim->value) && trim($claim->value) !== '',
        ));

        $specificBySource = [];
        foreach ($claims as $claim) {
            if ($claim->predicate !== Claim::PRED_OCR_TEXT) {
                $specificBySource[$claim->source] = true;
            }
        }

        $claims = array_values(array_filter(
            $claims,
            static fn(Claim $claim): bool => $claim->predicate !== Claim::PRED_OCR_TEXT || !isset($specificBySource[$claim->source]),
        ));

        usort($claims, static function (Claim $a, Claim $b): int {
            $rank = [
                'ai:observationProse'      => 4,
                Claim::PRED_HTR_TEXT       => 3,
                Claim::PRED_PRINTED_TEXT   => 2,
                Claim::PRED_OCR_TEXT       => 1,
            ];
            $rankCmp = ($rank[$b->predicate] ?? 0) <=> ($rank[$a->predicate] ?? 0);
            if ($rankCmp !== 0) {
                return $rankCmp;
            }
            if ($a->confidence !== $b->confidence) {
                return $b->confidence <=> $a->confidence;
            }
            return $b->createdAt <=> $a->createdAt;
        });

        return $claims;
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
