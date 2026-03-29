<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260329150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'MVP-081: Add payload_deleted_at to parse_result and make payload nullable';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE parse_result ALTER COLUMN payload DROP NOT NULL');
        $this->addSql('ALTER TABLE parse_result ADD payload_deleted_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE parse_result DROP payload_deleted_at');
        $this->addSql('UPDATE parse_result SET payload = \'{}\'::json WHERE payload IS NULL');
        $this->addSql('ALTER TABLE parse_result ALTER COLUMN payload SET NOT NULL');
    }
}
