<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Add BCHATID column to BWIDGET_SESSIONS to link sessions with owner chats.
 */
final class Version20251110_AddChatIdToWidgetSessions extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Adds BCHATID column and index to BWIDGET_SESSIONS for chat linkage';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE BWIDGET_SESSIONS ADD BCHATID BIGINT DEFAULT NULL');
        $this->addSql('CREATE INDEX idx_session_chat ON BWIDGET_SESSIONS (BCHATID)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE BWIDGET_SESSIONS DROP INDEX idx_session_chat');
        $this->addSql('ALTER TABLE BWIDGET_SESSIONS DROP BCHATID');
    }
}


