<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Widget Tables Migration
 * Creates BWIDGETS and BWIDGET_SESSIONS tables for chat widget functionality
 */
final class Version20250107_CreateWidgetTables extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Creates Widget and WidgetSession tables for embeddable chat widgets';
    }

    public function up(Schema $schema): void
    {
        // Widget table (without FK constraint initially)
        $this->addSql('CREATE TABLE IF NOT EXISTS BWIDGETS (
            BID BIGINT AUTO_INCREMENT NOT NULL,
            BOWNERID BIGINT NOT NULL,
            BWIDGETID VARCHAR(64) NOT NULL,
            BTASKPROMPT VARCHAR(128) NOT NULL,
            BNAME VARCHAR(128) NOT NULL,
            BSTATUS VARCHAR(16) NOT NULL DEFAULT \'active\',
            BCONFIG JSON NOT NULL,
            BCREATED BIGINT NOT NULL,
            BUPDATED BIGINT NOT NULL,
            PRIMARY KEY(BID),
            UNIQUE KEY UNIQ_WIDGETID (BWIDGETID),
            KEY idx_widget_owner (BOWNERID),
            KEY idx_widget_status (BSTATUS)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB');

        // Widget sessions table
        $this->addSql('CREATE TABLE IF NOT EXISTS BWIDGET_SESSIONS (
            BID BIGINT AUTO_INCREMENT NOT NULL,
            BWIDGETID VARCHAR(64) NOT NULL,
            BSESSIONID VARCHAR(64) NOT NULL,
            BMESSAGECOUNT INT NOT NULL DEFAULT 0,
            BLASTMESSAGE BIGINT NOT NULL DEFAULT 0,
            BCREATED BIGINT NOT NULL,
            BEXPIRES BIGINT NOT NULL,
            PRIMARY KEY(BID),
            UNIQUE KEY uk_widget_session (BWIDGETID, BSESSIONID),
            KEY idx_session_widget (BWIDGETID),
            KEY idx_session_expires (BEXPIRES)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS BWIDGET_SESSIONS');
        $this->addSql('DROP TABLE IF EXISTS BWIDGETS');
    }
}

