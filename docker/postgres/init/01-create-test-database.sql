-- Separate database for the in-process feature tests (they run migrate:fresh).
CREATE DATABASE wallet_test OWNER wallet;
