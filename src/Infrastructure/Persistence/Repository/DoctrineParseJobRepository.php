<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Repository;

use App\Domain\Parsing\Model\JobStatus;
use App\Domain\Parsing\Model\ParseJob;
use App\Domain\Parsing\Repository\ParseJobRepositoryInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

#[AsAlias(ParseJobRepositoryInterface::class)]
class DoctrineParseJobRepository implements ParseJobRepositoryInterface
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    public function save(ParseJob $job): void
    {
        $this->em->persist($job);
        $this->em->flush();
    }

    public function findById(string $id): ?ParseJob
    {
        return $this->em->find(ParseJob::class, $id);
    }

    public function findDoneByContentHash(string $hash, string $excludeJobId): ?ParseJob
    {
        return $this->em->createQuery(
            'SELECT j FROM '.ParseJob::class.' j WHERE j.contentHash = :hash AND j.status = :status AND j.id != :excludeId'
        )
            ->setParameter('hash', $hash)
            ->setParameter('status', JobStatus::Done)
            ->setParameter('excludeId', $excludeJobId)
            ->setMaxResults(1)
            ->getOneOrNullResult();
    }

    public function findOlderThan(\DateTimeImmutable $threshold): array
    {
        return $this->em->createQuery(
            'SELECT j FROM '.ParseJob::class.' j WHERE j.createdAt < :threshold'
        )
            ->setParameter('threshold', $threshold)
            ->getResult();
    }

    public function delete(ParseJob $job): void
    {
        $this->em->remove($job);
        $this->em->flush();
    }
}
