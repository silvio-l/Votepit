-- 0067_add_circuit_breakers — OWASP/BSI audit remediation issue 13
-- (external-dependency resilience). One row per outbound-dependency
-- circuit ("smtp", "translation", "paddle"): tracks consecutive failures
-- and, once the configured threshold is reached, the moment the circuit
-- opened. CircuitBreaker::isOpen() treats it as open (fail fast, no
-- outbound call attempted) until the cooldown elapses, then allows a single
-- half-open trial — same fixed-window-ish DB-backed pattern as rate_limits
-- (migrations/0002), just for consecutive failures instead of a request
-- count.
CREATE TABLE IF NOT EXISTS circuit_breakers (
    service              VARCHAR(64) NOT NULL,
    consecutive_failures INT         NOT NULL DEFAULT 0,
    opened_at            DATETIME    NULL,
    updated_at           DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (service)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
