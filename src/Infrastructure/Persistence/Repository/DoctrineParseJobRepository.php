<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Repository;

use App\Domain\Parsing\Model\ParseJob;
use App\Domain\Parsing\Repository\ParseJobRepositoryInterface;
use Doctrine\ORM\EntityManagerInterface;

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
}
