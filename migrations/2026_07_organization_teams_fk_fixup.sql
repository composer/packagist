-- Align the organization_team / organization_team_member schema created from the
-- original 2026_07_organization_teams.sql with the Doctrine entity mappings, so
-- doctrine:schema:update reports no changes on already-migrated databases (prod).

ALTER TABLE organization_team_member DROP FOREIGN KEY FK_organization_team_member_user;

ALTER TABLE organization_team RENAME INDEX fk_organization_team_created_by TO org_team_created_by_idx;
ALTER TABLE organization_team_member RENAME INDEX fk_organization_team_member_added_by TO org_team_member_added_by_idx;
