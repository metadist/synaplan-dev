<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * MessageFile Entity Migration
 * 
 * Creates BMESSAGEFILES table for multiple file attachments per message
 */
final class Version20251022150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create BMESSAGEFILES table for multiple file attachments';
    }

    public function up(Schema $schema): void
    {
        // Create BMESSAGEFILES table
        $this->addSql('CREATE TABLE BMESSAGEFILES (
            BID BIGINT AUTO_INCREMENT NOT NULL,
            BMESSAGEID BIGINT NOT NULL,
            BFILEPATH VARCHAR(255) NOT NULL DEFAULT \'\',
            BFILETYPE VARCHAR(16) NOT NULL DEFAULT \'\',
            BFILENAME VARCHAR(255) NOT NULL DEFAULT \'\',
            BFILESIZE INT NOT NULL DEFAULT 0,
            BFILEMIME VARCHAR(128) NOT NULL DEFAULT \'\',
            BFILETEXT LONGTEXT NOT NULL,
            BSTATUS VARCHAR(32) NOT NULL DEFAULT \'uploaded\',
            BCREATEDAT BIGINT NOT NULL,
            INDEX idx_messagefile_message (BMESSAGEID),
            INDEX idx_messagefile_type (BFILETYPE),
            PRIMARY KEY(BID)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        // Add foreign key constraint
        $this->addSql('ALTER TABLE BMESSAGEFILES 
            ADD CONSTRAINT FK_MESSAGEFILE_MESSAGE 
            FOREIGN KEY (BMESSAGEID) REFERENCES BMESSAGES (BID) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        // Drop foreign key first
        $this->addSql('ALTER TABLE BMESSAGEFILES DROP FOREIGN KEY FK_MESSAGEFILE_MESSAGE');
        
        // Drop table
        $this->addSql('DROP TABLE BMESSAGEFILES');
    }
}

