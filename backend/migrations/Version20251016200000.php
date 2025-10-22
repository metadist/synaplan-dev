<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Create Email Blacklist table for spam protection
 */
final class Version20251016200000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create BEMAILBLACKLIST table for email spam protection';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("
            CREATE TABLE IF NOT EXISTS BEMAILBLACKLIST (
                BID BIGINT NOT NULL AUTO_INCREMENT,
                BEMAIL VARCHAR(255) NOT NULL,
                BREASON VARCHAR(255) DEFAULT NULL,
                BCREATED VARCHAR(20) NOT NULL,
                BBLACKLISTED_BY BIGINT DEFAULT NULL,
                PRIMARY KEY (BID),
                INDEX idx_email (BEMAIL)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
        ");
    }

    public function down(Schema $schema): void
    {
        $this->addSql("DROP TABLE IF EXISTS BEMAILBLACKLIST");
    }
}
