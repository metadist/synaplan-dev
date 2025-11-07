<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Create Many-to-Many Junction Table for Message-File attachments
 * 
 * This allows:
 * - One file to be attached to multiple messages
 * - One message to have multiple files
 * - Files are reusable and not deleted when messages are deleted
 */
final class Version20250115_CreateFileAttachmentsTable extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create BMESSAGE_FILE_ATTACHMENTS junction table for Many-to-Many file attachments';
    }

    public function up(Schema $schema): void
    {
        // Create junction table
        $this->addSql('
            CREATE TABLE BMESSAGE_FILE_ATTACHMENTS (
                BID BIGINT AUTO_INCREMENT NOT NULL,
                BMESSAGEID BIGINT NOT NULL,
                BFILEID BIGINT NOT NULL,
                BATTACHED BIGINT NOT NULL,
                PRIMARY KEY(BID),
                INDEX idx_attachment_message (BMESSAGEID),
                INDEX idx_attachment_file (BFILEID),
                UNIQUE KEY unique_message_file (BMESSAGEID, BFILEID),
                CONSTRAINT FK_ATTACH_MESSAGE FOREIGN KEY (BMESSAGEID) REFERENCES BMESSAGES (BID) ON DELETE CASCADE,
                CONSTRAINT FK_ATTACH_FILE FOREIGN KEY (BFILEID) REFERENCES BFILES (BID) ON DELETE CASCADE
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB
        ');

        // Remove old BMESSAGEID column from BFILES (no longer needed)
        // Files are now linked via junction table
        $this->addSql('
            SET @col_exists = (
                SELECT COUNT(*) 
                FROM information_schema.COLUMNS 
                WHERE TABLE_SCHEMA = DATABASE() 
                AND TABLE_NAME = "BFILES" 
                AND COLUMN_NAME = "BMESSAGEID"
            );
            SET @sql = IF(@col_exists > 0, 
                "ALTER TABLE BFILES DROP COLUMN BMESSAGEID", 
                "SELECT 1"
            );
            PREPARE stmt FROM @sql;
            EXECUTE stmt;
            DEALLOCATE PREPARE stmt;
        ');

        // Remove old index if it exists
        $this->addSql('
            SET @idx_exists = (
                SELECT COUNT(*) 
                FROM information_schema.STATISTICS 
                WHERE TABLE_SCHEMA = DATABASE() 
                AND TABLE_NAME = "BFILES" 
                AND INDEX_NAME = "idx_messagefile_message"
            );
            SET @sql = IF(@idx_exists > 0, 
                "DROP INDEX idx_messagefile_message ON BFILES", 
                "SELECT 1"
            );
            PREPARE stmt FROM @sql;
            EXECUTE stmt;
            DEALLOCATE PREPARE stmt;
        ');
    }

    public function down(Schema $schema): void
    {
        // Drop junction table
        $this->addSql('DROP TABLE IF EXISTS BMESSAGE_FILE_ATTACHMENTS');

        // Re-add BMESSAGEID column to BFILES
        $this->addSql('
            ALTER TABLE BFILES 
            ADD COLUMN BMESSAGEID BIGINT NULL AFTER BID,
            ADD INDEX idx_file_message (BMESSAGEID)
        ');
    }
}

