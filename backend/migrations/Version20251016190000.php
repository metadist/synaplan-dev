<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Add ANONYMOUS subscription plan for non-verified users
 */
final class Version20251016190000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add ANONYMOUS subscription plan for non-phone-verified users';
    }

    public function up(Schema $schema): void
    {
        // Insert ANONYMOUS subscription plan (ID=0 for anonymous users)
        $this->addSql("
            INSERT INTO BSUBSCRIPTIONS (BID, BNAME, BLEVEL, BPRICE_MONTHLY, BPRICE_YEARLY, BDESCRIPTION, BACTIVE, BSTRIPE_MONTHLY_ID, BSTRIPE_YEARLY_ID)
            VALUES (0, 'Anonymous Plan', 'ANONYMOUS', 0.00, 0.00, 'Very limited access for non-verified users', 1, NULL, NULL)
            ON DUPLICATE KEY UPDATE 
                BNAME = VALUES(BNAME),
                BLEVEL = VALUES(BLEVEL),
                BDESCRIPTION = VALUES(BDESCRIPTION)
        ");
    }

    public function down(Schema $schema): void
    {
        $this->addSql("DELETE FROM BSUBSCRIPTIONS WHERE BID = 0");
    }
}
