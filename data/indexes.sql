CREATE INDEX ON submission (player_id);
CREATE INDEX ON submission (challenge_id);
CREATE INDEX ON submission (verifier_id);
CREATE INDEX ON submission (new_challenge_id);
CREATE INDEX ON submission (suggested_difficulty_id);

CREATE INDEX ON challenge (campaign_id);
CREATE INDEX ON challenge (map_id);
CREATE INDEX ON challenge (difficulty_id);
CREATE INDEX ON challenge (objective_id);

CREATE INDEX ON map (campaign_id);
CREATE INDEX ON map (counts_for_id);

CREATE INDEX ON account (player_id);
CREATE INDEX ON account (claimed_player_id);
CREATE INDEX ON session (account_id);

CREATE INDEX ON suggestion (challenge_id);
CREATE INDEX ON suggestion (author_id);
CREATE INDEX ON suggestion_vote (suggestion_id);
CREATE INDEX ON suggestion_vote (player_id);

CREATE INDEX ON change (challenge_id);
CREATE INDEX ON change (map_id);
CREATE INDEX ON change (campaign_id);
CREATE INDEX ON change (player_id);
CREATE INDEX ON change (author_id);

CREATE INDEX ON "like" (challenge_id);
CREATE INDEX ON "like" (player_id);

CREATE INDEX ON showcase (account_id);
CREATE INDEX ON showcase (submission_id);
CREATE INDEX ON badge_player (player_id);
CREATE INDEX ON badge_player (badge_id);
CREATE INDEX ON stamp_submission (submission_id);
CREATE INDEX ON stamp_submission (player_id);
CREATE INDEX ON verification_notice (submission_id);
CREATE INDEX ON verification_notice (verifier_id);
CREATE INDEX ON post (author_id);

CREATE INDEX ON campaign USING gin (name gin_trgm_ops);
CREATE INDEX ON campaign USING gin (author_gb_name gin_trgm_ops);
CREATE INDEX ON map USING gin (name gin_trgm_ops);
CREATE INDEX ON map USING gin (author_gb_name gin_trgm_ops);
CREATE INDEX ON player USING gin (name gin_trgm_ops);

ANALYZE;
