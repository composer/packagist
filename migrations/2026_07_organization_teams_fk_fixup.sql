-- Corrects the schema created by 2026_07_organization_teams.sql to match the Doctrine
-- entity mappings, so doctrine:schema:update reports no changes:

ALTER TABLE organization_team_member DROP FOREIGN KEY FK_organization_team_member_user;

ALTER TABLE organization_team RENAME INDEX fk_organization_team_created_by TO IDX_FDE99882D3564642;
ALTER TABLE organization_team_member RENAME INDEX fk_organization_team_member_added_by TO IDX_36A319DAE7CA843C;
