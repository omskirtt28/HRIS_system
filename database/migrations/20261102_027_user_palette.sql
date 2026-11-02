-- Select the intended database before running this additive migration (never run it blind on pmbsi_hris: take a dump first).
-- hr-appearance-palettes (SPEC-hr-appearance-palettes.md): the colour palette an HR user picked in the Appearance menu.
-- Allowed values (light, dark, beige, earth, pastel) are enforced in app/UserPalette.php. Every existing user gets 'light', so no look changes until they pick.
ALTER TABLE users
  ADD COLUMN IF NOT EXISTS ui_palette VARCHAR(16) NOT NULL DEFAULT 'light';
