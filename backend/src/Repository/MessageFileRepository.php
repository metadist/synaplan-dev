<?php

namespace App\Repository;

use App\Entity\MessageFile;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<MessageFile>
 */
class MessageFileRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MessageFile::class);
    }

    /**
     * Find all files for a message
     * 
     * @return MessageFile[]
     */
    public function findByMessage(int $messageId): array
    {
        return $this->createQueryBuilder('mf')
            ->where('mf.messageId = :messageId')
            ->setParameter('messageId', $messageId)
            ->orderBy('mf.createdAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Count files for a message
     */
    public function countByMessage(int $messageId): int
    {
        return (int) $this->createQueryBuilder('mf')
            ->select('COUNT(mf.id)')
            ->where('mf.messageId = :messageId')
            ->setParameter('messageId', $messageId)
            ->getQuery()
            ->getSingleScalarResult();
    }
}

