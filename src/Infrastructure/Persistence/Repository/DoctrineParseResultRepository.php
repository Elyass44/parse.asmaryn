<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Repository;

use App\Domain\Parsing\Model\ParseResult;
use App\Domain\Parsing\Repository\ParseResultRepositoryInterface;
use Doctrine\ORM\EntityManagerInterface;

class DoctrineParseResultRepository implements ParseResultRepositoryInterface
{
    public function __construct(private readonly EntityManagerInterface $em) {}

    public function save(ParseResult $result): void
    {
        $this->em->persist($result);
        $this->em->flush();
    }

    public function findByJobId(string $jobId): ?ParseResult
    {
        return $this->em->getRepository(ParseResult::class)->findOneBy(['jobId' => $jobId]);
    }
}
