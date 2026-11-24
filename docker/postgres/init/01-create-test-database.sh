#!/bin/sh
# Runs once, when the Postgres data volume is created for the first time.
#
# The automated tests get their own database. Tests wipe and rebuild their
# tables constantly; pointing them at the development database would delete
# the demo data you are clicking around in.
set -e

psql -v ON_ERROR_STOP=1 --username "$POSTGRES_USER" --dbname "$POSTGRES_DB" <<-SQL
    CREATE DATABASE lebanon_tourism_test OWNER "$POSTGRES_USER";
SQL
