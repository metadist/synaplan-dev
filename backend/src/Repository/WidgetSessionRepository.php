<?php

namespace App\Repository;

use App\Entity\WidgetSession;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<WidgetSession>
 */
class WidgetSessionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, WidgetSession::class);
    }

    public function save(WidgetSession $session, bool $flush = false): void
    {
        $this->getEntityManager()->persist($session);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remove(WidgetSession $session, bool $flush = false): void
    {
        $this->getEntityManager()->remove($session);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    /**
     * Find session by widget ID and session ID
     */
    public function findByWidgetAndSession(string $widgetId, string $sessionId): ?WidgetSession
    {
        return $this->findOneBy([
            'widgetId' => $widgetId,
            'sessionId' => $sessionId
        ]);
    }

    /**
     * Delete expired sessions
     */
    public function deleteExpiredSessions(): int
    {
        return $this->createQueryBuilder('ws')
            ->delete()
            ->where('ws.expires < :now')
            ->setParameter('now', time())
            ->getQuery()
            ->execute();
    }

    /**
     * Count active sessions for a widget
     */
    public function countActiveSessionsByWidget(string $widgetId): int
    {
        return $this->createQueryBuilder('ws')
            ->select('COUNT(ws.id)')
            ->where('ws.widgetId = :widgetId')
            ->andWhere('ws.expires > :now')
            ->setParameter('widgetId', $widgetId)
            ->setParameter('now', time())
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Get total message count for a widget
     */
    public function getTotalMessageCountByWidget(string $widgetId): int
    {
        return $this->createQueryBuilder('ws')
            ->select('SUM(ws.messageCount)')
            ->where('ws.widgetId = :widgetId')
            ->setParameter('widgetId', $widgetId)
            ->getQuery()
            ->getSingleScalarResult() ?? 0;
    }
}

