-- Runs once when the container is first created: creates the separate TEST database used by Pest.
SELECT 'CREATE DATABASE school_erp_test OWNER school_erp'
WHERE NOT EXISTS (SELECT FROM pg_database WHERE datname = 'school_erp_test')\gexec
