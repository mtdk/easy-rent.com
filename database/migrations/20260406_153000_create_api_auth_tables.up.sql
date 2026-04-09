CREATE TABLE IF NOT EXISTS api_tokens (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  token_name VARCHAR(100) NOT NULL,
  token_prefix VARCHAR(24) NOT NULL,
  token_hash CHAR(64) NOT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  expires_at DATETIME NULL,
  last_used_at DATETIME NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  UNIQUE KEY uk_api_tokens_hash (token_hash),
  KEY idx_api_tokens_user (user_id),
  KEY idx_api_tokens_active (is_active),
  CONSTRAINT fk_api_tokens_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS api_access_logs (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NULL,
  token_id BIGINT UNSIGNED NULL,
  request_path VARCHAR(255) NOT NULL,
  request_method VARCHAR(10) NOT NULL,
  status_code SMALLINT NOT NULL,
  auth_type VARCHAR(20) NOT NULL,
  ip_address VARCHAR(45) NOT NULL,
  user_agent VARCHAR(255) NULL,
  message VARCHAR(255) NULL,
  created_at DATETIME NOT NULL,
  KEY idx_api_access_logs_user (user_id),
  KEY idx_api_access_logs_token (token_id),
  KEY idx_api_access_logs_created_at (created_at),
  CONSTRAINT fk_api_access_logs_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_api_access_logs_token FOREIGN KEY (token_id) REFERENCES api_tokens(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
