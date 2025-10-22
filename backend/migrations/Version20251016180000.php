<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Add Subscription system (BSUBSCRIPTIONS table)
 * Add BEMAILVERIFIED to BUSER if missing
 */
final class Version20251016180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add subscription management system';
    }

    public function up(Schema $schema): void
    {
        // Create BSUBSCRIPTIONS table
        $this->addSql("
            CREATE TABLE IF NOT EXISTS BSUBSCRIPTIONS (
                BID BIGINT AUTO_INCREMENT NOT NULL,
                BNAME VARCHAR(64) NOT NULL,
                BLEVEL VARCHAR(32) NOT NULL COMMENT 'Rate limiting level: NEW, PRO, TEAM, BUSINESS',
                BPRICE_MONTHLY DECIMAL(10,2) NOT NULL COMMENT 'Monthly price in EUR',
                BPRICE_YEARLY DECIMAL(10,2) NOT NULL COMMENT 'Yearly price in EUR',
                BDESCRIPTION TEXT NOT NULL,
                BACTIVE TINYINT(1) NOT NULL DEFAULT 1,
                BSTRIPE_MONTHLY_ID VARCHAR(128) DEFAULT NULL COMMENT 'Stripe price ID for monthly',
                BSTRIPE_YEARLY_ID VARCHAR(128) DEFAULT NULL COMMENT 'Stripe price ID for yearly',
                PRIMARY KEY (BID),
                INDEX BLEVEL (BLEVEL),
                INDEX BACTIVE (BACTIVE)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        // Add BEMAILVERIFIED to BUSER if it doesn't exist
        $this->addSql("
            ALTER TABLE BUSER 
            ADD COLUMN IF NOT EXISTS BEMAILVERIFIED TINYINT(1) NOT NULL DEFAULT 0
            AFTER BUSERLEVEL
        ");

        // Insert subscription plans
        $this->addSql("
            INSERT IGNORE INTO BSUBSCRIPTIONS (BID, BNAME, BLEVEL, BPRICE_MONTHLY, BPRICE_YEARLY, BDESCRIPTION, BACTIVE, BSTRIPE_MONTHLY_ID, BSTRIPE_YEARLY_ID) VALUES
            (1, 'Free Plan', 'NEW', 0.00, 0.00, 'Basic free tier with limited usage', 1, NULL, NULL),
            (2, 'Pro Plan', 'PRO', 19.95, 199.50, 'Professional plan with increased limits', 1, 'price_stripe_pro_monthly', 'price_stripe_pro_yearly'),
            (3, 'Team Plan', 'TEAM', 49.95, 499.50, 'Team collaboration with higher limits', 1, 'price_stripe_team_monthly', 'price_stripe_team_yearly'),
            (4, 'Business Plan', 'BUSINESS', 99.95, 999.50, 'Enterprise-grade with maximum limits', 1, 'price_stripe_business_monthly', 'price_stripe_business_yearly')
        ");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS BSUBSCRIPTIONS');
        // Don't drop BEMAILVERIFIED in down() as it might be used
    }
}

