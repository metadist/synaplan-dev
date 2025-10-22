<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Make BMESSAGEID nullable in BMESSAGEFILES
 * 
 * Files can be uploaded before being attached to a message
 */
final class Version20251022151000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Make BMESSAGEID nullable in BMESSAGEFILES for pre-upload scenario';
    }

    public function up(Schema $schema): void
    {
        // Modify BMESSAGEID to allow NULL
        $this->addSql('ALTER TABLE BMESSAGEFILES MODIFY BMESSAGEID BIGINT NULL');
    }

    public function down(Schema $schema): void
    {
        // Revert to NOT NULL (this will fail if there are NULL values!)
        $this->addSql('ALTER TABLE BMESSAGEFILES MODIFY BMESSAGEID BIGINT NOT NULL');
    }
}

