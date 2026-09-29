-- LibreForum tables. Safe to run again (php bin/install.php does).
-- Keep comments free of semicolons: the installer splits this file on them.

-- An account is a group of people who belong together (a business, a team). A stand-alone forum has one.
CREATE TABLE IF NOT EXISTS accounts (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name          VARCHAR(100) NOT NULL,
  member_limit  SMALLINT UNSIGNED NULL,               -- most people this account may have, NULL for no limit
  host_ref      VARCHAR(100) NULL,                    -- the host app's own id for this account, if any
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_accounts_host (host_ref)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS members (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  account_id     INT UNSIGNED NOT NULL,
  username       VARCHAR(20) NULL,                     -- what everyone sees, NULL until they pick it
  username_key   VARCHAR(20) NULL,                     -- lower case without underscores, unique
  password_hash  VARCHAR(255) NULL,                    -- NULL for people a host app signs in for them
  host_ref       VARCHAR(100) NULL,                    -- the host app's own id for this person, if any
  role           ENUM('member','moderator','owner') NOT NULL DEFAULT 'member',
  status         ENUM('active','muted','removed') NOT NULL DEFAULT 'active',
  created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_seen_at   DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_members_username (username_key),
  UNIQUE KEY uq_members_host (host_ref),
  KEY idx_members_account (account_id),
  CONSTRAINT fk_members_account FOREIGN KEY (account_id) REFERENCES accounts (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS categories (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  slug         VARCHAR(40) NOT NULL,
  name         VARCHAR(60) NOT NULL,
  description  VARCHAR(200) NOT NULL DEFAULT '',
  position     SMALLINT NOT NULL DEFAULT 0,
  staff_only   TINYINT(1) NOT NULL DEFAULT 0,          -- only the owner and moderators may start threads here
  created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_categories_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS threads (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  category_id   INT UNSIGNED NOT NULL,
  member_id     INT UNSIGNED NOT NULL,
  title         VARCHAR(150) NOT NULL,
  pinned        TINYINT(1) NOT NULL DEFAULT 0,
  locked        TINYINT(1) NOT NULL DEFAULT 0,
  post_count    INT UNSIGNED NOT NULL DEFAULT 0,       -- the first post plus the replies
  last_post_id  INT UNSIGNED NULL,
  last_post_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  deleted_at    DATETIME NULL,
  PRIMARY KEY (id),
  KEY idx_threads_list (deleted_at, pinned, last_post_at),
  KEY idx_threads_category (category_id, deleted_at, last_post_at),
  KEY idx_threads_member (member_id, created_at),
  CONSTRAINT fk_threads_category FOREIGN KEY (category_id) REFERENCES categories (id),
  CONSTRAINT fk_threads_member FOREIGN KEY (member_id) REFERENCES members (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS posts (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  thread_id   INT UNSIGNED NOT NULL,
  member_id   INT UNSIGNED NOT NULL,
  body        TEXT NOT NULL,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  deleted_at  DATETIME NULL,
  PRIMARY KEY (id),
  KEY idx_posts_thread (thread_id, deleted_at, id),
  KEY idx_posts_member (member_id, created_at),
  CONSTRAINT fk_posts_thread FOREIGN KEY (thread_id) REFERENCES threads (id) ON DELETE CASCADE,
  CONSTRAINT fk_posts_member FOREIGN KEY (member_id) REFERENCES members (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- How far each member has read each thread, which drives the new-since-your-last-visit markers.
CREATE TABLE IF NOT EXISTS thread_reads (
  member_id     INT UNSIGNED NOT NULL,
  thread_id     INT UNSIGNED NOT NULL,
  last_post_id  INT UNSIGNED NOT NULL,
  PRIMARY KEY (member_id, thread_id),
  CONSTRAINT fk_thread_reads_member FOREIGN KEY (member_id) REFERENCES members (id) ON DELETE CASCADE,
  CONSTRAINT fk_thread_reads_thread FOREIGN KEY (thread_id) REFERENCES threads (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS reports (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  post_id     INT UNSIGNED NOT NULL,
  member_id   INT UNSIGNED NOT NULL,                   -- who reported it
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  handled_at  DATETIME NULL,
  handled_by  INT UNSIGNED NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_reports_once (post_id, member_id),
  KEY idx_reports_open (handled_at),
  CONSTRAINT fk_reports_post FOREIGN KEY (post_id) REFERENCES posts (id) ON DELETE CASCADE,
  CONSTRAINT fk_reports_member FOREIGN KEY (member_id) REFERENCES members (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- One-time links the owner hands out. Only a hash of the link is kept.
CREATE TABLE IF NOT EXISTS invites (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  account_id  INT UNSIGNED NOT NULL,
  token_hash  BINARY(32) NOT NULL,
  role        ENUM('member','moderator') NOT NULL DEFAULT 'member',
  created_by  INT UNSIGNED NOT NULL,                   -- the member who made it, or 0 when a host app did
  note        VARCHAR(100) NOT NULL DEFAULT '',        -- who it is for, so a list of open invitations makes sense
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  expires_at  DATETIME NOT NULL,
  used_at     DATETIME NULL,
  used_by     INT UNSIGNED NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_invites_token (token_hash),
  CONSTRAINT fk_invites_account FOREIGN KEY (account_id) REFERENCES accounts (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Logins. The cookie holds a random token and only its hash is kept here.
CREATE TABLE IF NOT EXISTS sessions (
  token_hash    BINARY(32) NOT NULL,
  member_id     INT UNSIGNED NOT NULL,
  csrf          CHAR(32) NOT NULL,
  via_host      TINYINT(1) NOT NULL DEFAULT 0,         -- 1 when the login came through a host app, which sets shorter limits
  flash         VARCHAR(400) NULL,                     -- a message to show once on the next page
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_used_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (token_hash),
  KEY idx_sessions_member (member_id),
  KEY idx_sessions_used (last_used_at),
  CONSTRAINT fk_sessions_member FOREIGN KEY (member_id) REFERENCES members (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Entry links from a host app can be used once. Their ids are kept until they would have expired anyway.
CREATE TABLE IF NOT EXISTS host_tokens (
  jti         CHAR(32) NOT NULL,
  expires_at  DATETIME NOT NULL,
  PRIMARY KEY (jti),
  KEY idx_host_tokens_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS settings (
  name   VARCHAR(50) NOT NULL,
  value  TEXT NOT NULL,
  PRIMARY KEY (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- The daily secret that turns an address into an anonymous code for the login limit. Old ones are deleted.
CREATE TABLE IF NOT EXISTS salts (
  day   DATE NOT NULL,
  salt  BINARY(32) NOT NULL,
  PRIMARY KEY (day)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS login_failures (
  id   BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  who  BINARY(8) NOT NULL,
  at   DATETIME NOT NULL,
  PRIMARY KEY (id),
  KEY idx_login_failures (who, at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
