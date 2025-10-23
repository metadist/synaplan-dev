<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Make MessageFile independent from Message
 * - Add BUSERID to track file owner
 * - Make BMESSAGEID truly nullable (was already nullable in entity)
 * - Add index on BUSERID for queries
 */
final class Version20251023200000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Make MessageFile independent: add BUSERID, make BMESSAGEID nullable';
    }

    public function up(Schema $schema): void
    {
        // Add BUSERID column (nullable first)
        $this->addSql('ALTER TABLE BMESSAGEFILES ADD COLUMN BUSERID BIGINT NULL AFTER BID');
        
        // Update existing records: Copy user ID from related message
        $this->addSql('
            UPDATE BMESSAGEFILES mf
            INNER JOIN BMESSAGES m ON mf.BMESSAGEID = m.BID
            SET mf.BUSERID = m.BUSERID
            WHERE mf.BUSERID IS NULL AND mf.BMESSAGEID IS NOT NULL
        ');
        
        // For orphaned files (no message), set a default user (admin = 4) or delete them
        // Option 1: Assign to admin user
        $this->addSql('UPDATE BMESSAGEFILES SET BUSERID = 4 WHERE BUSERID IS NULL');
        
        // Make BUSERID NOT NULL after populating
        $this->addSql('ALTER TABLE BMESSAGEFILES MODIFY COLUMN BUSERID BIGINT NOT NULL');
        
        // Create index on BUSERID
        $this->addSql('CREATE INDEX idx_messagefile_user ON BMESSAGEFILES(BUSERID)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_messagefile_user ON BMESSAGEFILES');
        $this->addSql('ALTER TABLE BMESSAGEFILES DROP COLUMN BUSERID');
    }
}
