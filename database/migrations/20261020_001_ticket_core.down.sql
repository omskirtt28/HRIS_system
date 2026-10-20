-- Maintenance rollback only: take a fresh backup first; removes only Slice-15 tables.
DROP TRIGGER IF EXISTS ticket_steps_frozen;
DROP TRIGGER IF EXISTS ticket_steps_no_delete;
DROP TRIGGER IF EXISTS tickets_immutable_number;
DROP TRIGGER IF EXISTS ticket_events_no_delete;
DROP TRIGGER IF EXISTS ticket_events_no_update;
DROP TABLE IF EXISTS ticket_effects;
DROP TABLE IF EXISTS ticket_attachments;
DROP TABLE IF EXISTS ticket_comments;
DROP TABLE IF EXISTS ticket_events;
DROP TABLE IF EXISTS ticket_steps;
DROP TABLE IF EXISTS tickets;
DROP TABLE IF EXISTS ticket_types;
DROP TABLE IF EXISTS ticket_routing_profiles;
