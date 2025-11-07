<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Improve prompt descriptions for better sorting accuracy
 */
final class Version20251029_ImprovePromptDescriptions extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Make "general" prompt description more specific to avoid overlap with mediamaker';
    }

    public function up(Schema $schema): void
    {
        // Make general prompt more specific - exclude media generation
        $this->addSql("
            UPDATE BPROMPTS 
            SET BSHORTDESC = 'All text-based requests go here: conversations, questions, explanations, poems, health tips, programming help, travel info, and similar TEXT ONLY tasks. DO NOT use for image/video/audio generation - use mediamaker instead.'
            WHERE BTOPIC = 'general' AND BOWNERID = 0
        ");

        // Make mediamaker even more explicit
        $this->addSql("
            UPDATE BPROMPTS 
            SET BSHORTDESC = 'ONLY for media generation requests! User wants to CREATE/GENERATE/MAKE an image, video, or audio file. Keywords: \"generiere\", \"erstelle\", \"mache\", \"create\", \"generate\", \"make\" + \"bild\", \"foto\", \"image\", \"picture\", \"video\", \"film\", \"audio\", \"sound\". This connects to AI media generators like DALL-E, Stable Diffusion, Veo.'
            WHERE BTOPIC = 'mediamaker' AND BOWNERID = 0
        ");
    }

    public function down(Schema $schema): void
    {
        // Restore old descriptions
        $this->addSql("
            UPDATE BPROMPTS 
            SET BSHORTDESC = 'All requests by users go here by default. Send the user question here for text creation, poems, health tips, programming or coding examples, travel infos and the like.'
            WHERE BTOPIC = 'general' AND BOWNERID = 0
        ");

        $this->addSql("
            UPDATE BPROMPTS 
            SET BSHORTDESC = 'The user asks for generation of an image, video or audio. Examples: \"generiere ein bild\", \"create an image\", \"make a video\", \"generate a picture\", \"erstelle ein foto\". User wants to CREATE visual or audio media, not analyze it. This handles the connection to media generation AIs like DALL-E, Stable Diffusion, etc.'
            WHERE BTOPIC = 'mediamaker' AND BOWNERID = 0
        ");
    }
}

