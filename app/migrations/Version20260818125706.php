<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The whole schema in one step.
 *
 * The development history was folded into this single migration, and the
 * database file was renamed at the same time — a deployment starts from an
 * empty bring.db rather than migrating the old app.db, which is left where it
 * is. Nothing had been deployed that needed its history preserved.
 *
 * The client table is ours, so it carries the app_ prefix like the other two.
 * The bundle's token tables keep their names: they are the bundle's, mapped by
 * the bundle, and their association resolves to App\Entity\OAuthClient — which
 * is why their foreign keys follow the rename by themselves.
 */
final class Version20260818125706 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create the schema: accounts, Bring! credentials, clients and the OAuth2 tables';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE app_bring_credential (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, password_cipher CLOB NOT NULL, updated_at DATETIME NOT NULL, user_id INTEGER NOT NULL, CONSTRAINT FK_EF5916F2A76ED395 FOREIGN KEY (user_id) REFERENCES app_user (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_EF5916F2A76ED395 ON app_bring_credential (user_id)');
        $this->addSql('CREATE TABLE app_oauth_client (name VARCHAR(128) NOT NULL, secret VARCHAR(128) DEFAULT NULL, redirect_uris CLOB DEFAULT NULL, grants CLOB DEFAULT NULL, scopes CLOB DEFAULT NULL, active BOOLEAN NOT NULL, allow_plain_text_pkce BOOLEAN DEFAULT 0 NOT NULL, identifier VARCHAR(32) NOT NULL, created_at DATETIME NOT NULL, default_list_name VARCHAR(120) DEFAULT NULL, user_id INTEGER DEFAULT NULL, PRIMARY KEY (identifier), CONSTRAINT FK_6FFD3DCCA76ED395 FOREIGN KEY (user_id) REFERENCES app_user (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE INDEX IDX_6FFD3DCCA76ED395 ON app_oauth_client (user_id)');
        $this->addSql('CREATE TABLE app_user (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, email VARCHAR(180) NOT NULL, roles CLOB NOT NULL, created_at DATETIME NOT NULL, last_active_at DATETIME NOT NULL, inactivity_notice_sent_at DATETIME DEFAULT NULL)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_88BDF3E9E7927C74 ON app_user (email)');
        $this->addSql('CREATE TABLE oauth2_access_token (identifier CHAR(80) NOT NULL, expiry DATETIME NOT NULL, user_identifier VARCHAR(128) DEFAULT NULL, scopes CLOB DEFAULT NULL, revoked BOOLEAN NOT NULL, client VARCHAR(32) NOT NULL, PRIMARY KEY (identifier), CONSTRAINT FK_454D9673C7440455 FOREIGN KEY (client) REFERENCES app_oauth_client (identifier) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE INDEX IDX_454D9673C7440455 ON oauth2_access_token (client)');
        $this->addSql('CREATE TABLE oauth2_authorization_code (identifier CHAR(80) NOT NULL, expiry DATETIME NOT NULL, user_identifier VARCHAR(128) DEFAULT NULL, scopes CLOB DEFAULT NULL, revoked BOOLEAN NOT NULL, client VARCHAR(32) NOT NULL, PRIMARY KEY (identifier), CONSTRAINT FK_509FEF5FC7440455 FOREIGN KEY (client) REFERENCES app_oauth_client (identifier) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE INDEX IDX_509FEF5FC7440455 ON oauth2_authorization_code (client)');
        $this->addSql('CREATE TABLE oauth2_refresh_token (identifier CHAR(80) NOT NULL, expiry DATETIME NOT NULL, revoked BOOLEAN NOT NULL, access_token CHAR(80) DEFAULT NULL, PRIMARY KEY (identifier), CONSTRAINT FK_4DD90732B6A2DD68 FOREIGN KEY (access_token) REFERENCES oauth2_access_token (identifier) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE INDEX IDX_4DD90732B6A2DD68 ON oauth2_refresh_token (access_token)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE app_bring_credential');
        $this->addSql('DROP TABLE app_oauth_client');
        $this->addSql('DROP TABLE app_user');
        $this->addSql('DROP TABLE oauth2_access_token');
        $this->addSql('DROP TABLE oauth2_authorization_code');
        $this->addSql('DROP TABLE oauth2_refresh_token');
    }
}
