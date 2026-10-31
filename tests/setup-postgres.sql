DO $body$
BEGIN
    IF NOT EXISTS (SELECT FROM pg_roles WHERE rolname = 'noria_approvals_test') THEN
        CREATE ROLE noria_approvals_test LOGIN PASSWORD 'approvals_test_only' NOSUPERUSER NOBYPASSRLS;
    END IF;
END;
$body$;

SELECT 'CREATE DATABASE noria_approvals_test OWNER noria_approvals_test'
WHERE NOT EXISTS (SELECT FROM pg_database WHERE datname = 'noria_approvals_test')\gexec
