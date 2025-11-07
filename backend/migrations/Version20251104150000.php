<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Add selection rules support for task prompts
 * - Adds BSELECTION_RULES to BPROMPTS for matching criteria
 * - Fixes BPROMPTMETA column name (BTOKEN -> BMETAKEY)
 */
final class Version20251104150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add selection rules for task prompts and fix PromptMeta column naming';
    }

    public function up(Schema $schema): void
    {
        // Add BSELECTION_RULES column to BPROMPTS
        $this->addSql("
            ALTER TABLE BPROMPTS 
            ADD COLUMN IF NOT EXISTS BSELECTION_RULES TEXT DEFAULT NULL
            COMMENT 'Criteria for automatic prompt selection during message sorting'
            AFTER BPROMPT
        ");

        // Fix column name in BPROMPTMETA if it exists as BTOKEN
        $this->addSql("
            ALTER TABLE BPROMPTMETA 
            CHANGE COLUMN IF EXISTS BTOKEN BMETAKEY VARCHAR(64) NOT NULL 
            COMMENT 'Meta data key'
        ");

        // Add BCREATED column if it doesn't exist
        $this->addSql("
            ALTER TABLE BPROMPTMETA 
            ADD COLUMN IF NOT EXISTS BCREATED BIGINT NOT NULL DEFAULT 0
            COMMENT 'Creation timestamp'
        ");

        // Add index for BMETAKEY if not exists
        $this->addSql("
            CREATE INDEX IF NOT EXISTS idx_promptmeta_key ON BPROMPTMETA(BMETAKEY)
        ");
    }

    public function down(Schema $schema): void
    {
        // Remove BSELECTION_RULES
        $this->addSql("ALTER TABLE BPROMPTS DROP COLUMN IF EXISTS BSELECTION_RULES");
        
        // Revert BMETAKEY to BTOKEN (if someone needs to rollback)
        $this->addSql("ALTER TABLE BPROMPTMETA CHANGE COLUMN IF EXISTS BMETAKEY BTOKEN VARCHAR(64) NOT NULL");
    }
}

