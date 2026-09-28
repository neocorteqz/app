-- ApexNode Panel v7 - pinned Minecraft versions and unguessable public join links
USE apexnode;

ALTER TABLE servers ADD COLUMN IF NOT EXISTS minecraft_version VARCHAR(16) NOT NULL DEFAULT '1.20.4';
ALTER TABLE servers ADD COLUMN IF NOT EXISTS share_token CHAR(48) DEFAULT NULL;
CREATE UNIQUE INDEX IF NOT EXISTS uniq_servers_share_token ON servers (share_token);