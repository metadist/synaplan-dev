<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Add default model configurations for media generation (images, videos, audio)
 */
final class Version20251029_AddMediaConfigDefaults extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add TEXT2PIC, TEXT2VID, TEXT2SOUND, PIC2TEXT, and SOUND2TEXT default model configurations';
    }

    public function up(Schema $schema): void
    {
        // Insert new default model configurations
        $this->addSql("
            INSERT INTO BCONFIG (BOWNERID, BGROUP, BSETTING, BVALUE) 
            VALUES 
                (0, 'DEFAULTMODEL', 'TEXT2PIC', '29'),
                (0, 'DEFAULTMODEL', 'TEXT2VID', '45'),
                (0, 'DEFAULTMODEL', 'TEXT2SOUND', '41'),
                (0, 'DEFAULTMODEL', 'PIC2TEXT', '17'),
                (0, 'DEFAULTMODEL', 'SOUND2TEXT', '21')
            ON DUPLICATE KEY UPDATE BVALUE=VALUES(BVALUE)
        ");

        // Update mediamaker prompt description to be more explicit
        $this->addSql("
            UPDATE BPROMPTS 
            SET BSHORTDESC = 'The user asks for generation of an image, video or audio. Examples: \"generiere ein bild\", \"create an image\", \"make a video\", \"generate a picture\", \"erstelle ein foto\". User wants to CREATE visual or audio media, not analyze it. This handles the connection to media generation AIs like DALL-E, Stable Diffusion, etc.'
            WHERE BTOPIC = 'mediamaker' AND BOWNERID = 0
        ");
    }

    public function down(Schema $schema): void
    {
        // Remove the added configurations
        $this->addSql("
            DELETE FROM BCONFIG 
            WHERE BOWNERID = 0 
            AND BGROUP = 'DEFAULTMODEL' 
            AND BSETTING IN ('TEXT2PIC', 'TEXT2VID', 'TEXT2SOUND', 'PIC2TEXT', 'SOUND2TEXT')
        ");

        // Restore old description
        $this->addSql("
            UPDATE BPROMPTS 
            SET BSHORTDESC = 'The user asks for generation of image(s), video(s) or sounds. Not for any other file types. The user wants an image, video or an audio file. Direct the request here. This handles the connection to media generation AIs.'
            WHERE BTOPIC = 'mediamaker' AND BOWNERID = 0
        ");
    }
}

