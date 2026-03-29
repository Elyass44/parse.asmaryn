<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Repository;

use App\Domain\Parsing\Model\ParseResult;
use App\Domain\Parsing\Repository\ParseResultRepositoryInterface;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

#[AsAlias(ParseResultRepositoryInterface::class)]
class DoctrineParseResultRepository implements ParseResultRepositoryInterface
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    public function save(ParseResult $result): void
    {
        $this->em->persist($result);
        $this->em->flush();
    }

    public function findByJobId(string $jobId): ?ParseResult
    {
        return $this->em->getRepository(ParseResult::class)->findOneBy(['jobId' => $jobId]);
    }

    public function deleteByJobId(string $jobId): void
    {
        $this->em->createQuery(
            'DELETE FROM '.ParseResult::class.' r WHERE r.jobId = :jobId'
        )
            ->setParameter('jobId', $jobId)
            ->execute();
    }

    public function wipePayloadsOlderThan(\DateTimeImmutable $threshold): int
    {
        return (int) $this->em->getConnection()->executeStatement(
            'UPDATE parse_result SET payload = NULL, payload_deleted_at = :now WHERE created_at < :threshold AND payload IS NOT NULL',
            ['now' => new \DateTimeImmutable(), 'threshold' => $threshold],
            ['now' => Types::DATETIMETZ_IMMUTABLE, 'threshold' => Types::DATETIME_IMMUTABLE],
        );
    }

    public function countPayloadsOlderThan(\DateTimeImmutable $threshold): int
    {
        return (int) $this->em->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM parse_result WHERE created_at < :threshold AND payload IS NOT NULL',
            ['threshold' => $threshold],
            ['threshold' => Types::DATETIME_IMMUTABLE],
        );
    }
}
