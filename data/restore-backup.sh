#!/bin/sh
set -e

pg_restore \
  --no-owner \
  --dbname="$POSTGRES_DB" \
  /tmp/goldberries-data/backup-safe.dump