<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260329130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add ai_duration_ms column to parse_result';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE parse_result ADD ai_duration_ms INT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE parse_result DROP ai_duration_ms');
    }
}
