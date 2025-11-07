<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Remove Foreign Key constraint from BRAG.BMID
 * 
 * Problem: BRAG.BMID had FK to BMESSAGES.BID, but we also use MessageFile IDs (from BMESSAGEFILES.BID)
 * Solution: Remove FK constraint to allow BMID to reference both BMESSAGES and BMESSAGEFILES
 */
final class Version20250115_RemoveBRAGForeignKey extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Remove FK constraint from BRAG.BMID to support both Message and MessageFile references';
    }

    public function up(Schema $schema): void
    {
        // Drop the FK constraint that prevented vectorization (IF EXISTS)
        $this->addSql('
            SET @fk_exists = (
                SELECT COUNT(*) 
                FROM information_schema.TABLE_CONSTRAINTS 
                WHERE CONSTRAINT_SCHEMA = DATABASE() 
                AND TABLE_NAME = "BRAG" 
                AND CONSTRAINT_NAME = "FK_7EBB1F03381120FC"
            );
            SET @sql = IF(@fk_exists > 0, 
                "ALTER TABLE BRAG DROP FOREIGN KEY FK_7EBB1F03381120FC", 
                "SELECT 1"
            );
            PREPARE stmt FROM @sql;
            EXECUTE stmt;
            DEALLOCATE PREPARE stmt;
        ');
    }

    public function down(Schema $schema): void
    {
        // Re-add FK constraint (will fail if BMID references BMESSAGEFILES)
        $this->addSql('ALTER TABLE BRAG ADD CONSTRAINT FK_7EBB1F03381120FC FOREIGN KEY (BMID) REFERENCES BMESSAGES (BID)');
    }
}

