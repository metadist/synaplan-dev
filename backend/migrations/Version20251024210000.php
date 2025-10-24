<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Migration to create BSEARCHRESULTS table for storing web search results
 */
final class Version20251024210000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create BSEARCHRESULTS table for Brave Search API results';
    }

    public function up(Schema $schema): void
    {
        // Create search results table
        $this->addSql('CREATE TABLE BSEARCHRESULTS (
            BID INT AUTO_INCREMENT NOT NULL,
            BMESSAGEID BIGINT NOT NULL,
            BQUERY VARCHAR(500) NOT NULL,
            BTITLE VARCHAR(500) NOT NULL,
            BURL LONGTEXT NOT NULL,
            BDESCRIPTION LONGTEXT DEFAULT NULL,
            BPUBLISHED VARCHAR(100) DEFAULT NULL,
            BSOURCE VARCHAR(255) DEFAULT NULL,
            BTHUMBNAIL LONGTEXT DEFAULT NULL,
            BPOSITION INT NOT NULL,
            BEXTRASNIPPETS JSON DEFAULT NULL,
            BCREATEDAT DATETIME NOT NULL,
            INDEX idx_message (BMESSAGEID),
            INDEX idx_query (BQUERY),
            PRIMARY KEY(BID)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        // Add foreign key constraint
        $this->addSql('ALTER TABLE BSEARCHRESULTS ADD CONSTRAINT FK_E7266274EA4931D2 FOREIGN KEY (BMESSAGEID) REFERENCES BMESSAGES (BID) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        // Drop the table
        $this->addSql('ALTER TABLE BSEARCHRESULTS DROP FOREIGN KEY FK_E7266274EA4931D2');
        $this->addSql('DROP TABLE BSEARCHRESULTS');
    }
}

