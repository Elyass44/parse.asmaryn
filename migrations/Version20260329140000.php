<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260329140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'MVP-080: Drop content_hash column from parse_job (remove deduplication)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE parse_job DROP content_hash');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE parse_job ADD content_hash VARCHAR(64) DEFAULT NULL');
    }
}
