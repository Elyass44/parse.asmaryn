<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260329120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add stats & deduplication columns: content_hash + started_at on parse_job; tokens + ai_provider on parse_result';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE parse_job ADD content_hash VARCHAR(64) DEFAULT NULL');
        $this->addSql('ALTER TABLE parse_job ADD started_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('COMMENT ON COLUMN parse_job.started_at IS \'(DC2Type:datetime_immutable)\'');

        $this->addSql('ALTER TABLE parse_result ADD tokens_prompt INT DEFAULT NULL');
        $this->addSql('ALTER TABLE parse_result ADD tokens_completion INT DEFAULT NULL');
        $this->addSql('ALTER TABLE parse_result ADD tokens_total INT DEFAULT NULL');
        $this->addSql('ALTER TABLE parse_result ADD ai_provider VARCHAR(32) DEFAULT NULL');
        $this->addSql('ALTER TABLE parse_result ADD ai_duration_ms INT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE parse_job DROP content_hash');
        $this->addSql('ALTER TABLE parse_job DROP started_at');

        $this->addSql('ALTER TABLE parse_result DROP tokens_prompt');
        $this->addSql('ALTER TABLE parse_result DROP tokens_completion');
        $this->addSql('ALTER TABLE parse_result DROP tokens_total');
        $this->addSql('ALTER TABLE parse_result DROP ai_provider');
        $this->addSql('ALTER TABLE parse_result DROP ai_duration_ms');
    }
}
