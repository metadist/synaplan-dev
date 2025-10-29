<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Expand BFILEPATH column from VARCHAR(255) to TEXT to support Base64-encoded videos
 */
final class Version20251029182500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Expand BFILEPATH column from VARCHAR(255) to TEXT to support Base64-encoded videos';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE BMESSAGES MODIFY COLUMN BFILEPATH TEXT NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE BMESSAGES MODIFY COLUMN BFILEPATH VARCHAR(255) NOT NULL');
    }
}

