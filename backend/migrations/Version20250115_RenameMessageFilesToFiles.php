<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Rename BMESSAGEFILES to BFILES
 * 
 * Reason: Files are standalone entities, not tied to messages
 * Better naming: BFILES (like BUSERS, BMESSAGES, BPROMPTS)
 */
final class Version20250115_RenameMessageFilesToFiles extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Rename BMESSAGEFILES table to BFILES for better semantics';
    }

    public function up(Schema $schema): void
    {
        // Rename table (IF EXISTS)
        $this->addSql('
            SET @table_exists = (
                SELECT COUNT(*) 
                FROM information_schema.TABLES 
                WHERE TABLE_SCHEMA = DATABASE() 
                AND TABLE_NAME = "BMESSAGEFILES"
            );
            SET @sql = IF(@table_exists > 0, 
                "RENAME TABLE BMESSAGEFILES TO BFILES", 
                "SELECT 1"
            );
            PREPARE stmt FROM @sql;
            EXECUTE stmt;
            DEALLOCATE PREPARE stmt;
        ');

        // Update FK constraint in junction table to reference BFILES
        $this->addSql('
            SET @fk_exists = (
                SELECT COUNT(*) 
                FROM information_schema.TABLE_CONSTRAINTS 
                WHERE CONSTRAINT_SCHEMA = DATABASE() 
                AND TABLE_NAME = "BMESSAGE_FILE_ATTACHMENTS" 
                AND CONSTRAINT_NAME = "FK_ATTACH_FILE"
            );
            SET @sql = IF(@fk_exists > 0, 
                "ALTER TABLE BMESSAGE_FILE_ATTACHMENTS DROP FOREIGN KEY FK_ATTACH_FILE", 
                "SELECT 1"
            );
            PREPARE stmt FROM @sql;
            EXECUTE stmt;
            DEALLOCATE PREPARE stmt;
        ');
        
        $this->addSql('
            SET @fk_missing = (
                SELECT COUNT(*) = 0
                FROM information_schema.TABLE_CONSTRAINTS 
                WHERE CONSTRAINT_SCHEMA = DATABASE() 
                AND TABLE_NAME = "BMESSAGE_FILE_ATTACHMENTS" 
                AND CONSTRAINT_NAME = "FK_ATTACH_FILE"
            );
            SET @sql = IF(@fk_missing, 
                "ALTER TABLE BMESSAGE_FILE_ATTACHMENTS 
                 ADD CONSTRAINT FK_ATTACH_FILE 
                 FOREIGN KEY (BFILEID) 
                 REFERENCES BFILES (BID) 
                 ON DELETE CASCADE", 
                "SELECT 1"
            );
            PREPARE stmt FROM @sql;
            EXECUTE stmt;
            DEALLOCATE PREPARE stmt;
        ');
    }

    public function down(Schema $schema): void
    {
        // Revert FK constraint
        $this->addSql('
            ALTER TABLE BMESSAGE_FILE_ATTACHMENTS 
            DROP FOREIGN KEY FK_ATTACH_FILE
        ');
        
        $this->addSql('
            ALTER TABLE BMESSAGE_FILE_ATTACHMENTS 
            ADD CONSTRAINT FK_ATTACH_FILE 
            FOREIGN KEY (BFILEID) 
            REFERENCES BMESSAGEFILES (BID) 
            ON DELETE CASCADE
        ');

        // Rename table back
        $this->addSql('RENAME TABLE BFILES TO BMESSAGEFILES');
    }
}

