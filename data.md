## Create database dump

pg_dump -h 109.123.243.54 -p 5432 -U postgres -d goldberries -Fc -f backup.dump

OR without user data:

pg_dump -h 109.123.243.54 -p 5432 -U postgres -d goldberries -Fc \
   --exclude-table-data=public.session \
   --exclude-table-data=public.account \
   --exclude-table-data=public.showcase \
   --exclude-table-data=public.logging \
   --exclude-table-data=public.traffic \
   --exclude-table-data=public.traffic_agg \
   -f backup-safe.dump

## Load dump

Terminate all connections to the database, then drop + recreate + load the dump
```
psql -h localhost -p 5432 -U postgres goldberries

SELECT pg_terminate_backend(pid)
FROM pg_stat_activity
WHERE datname = current_database()
  AND pid <> pg_backend_pid();

dropdb -h localhost -p 5432 -U postgres goldberries
createdb -h localhost -p 5432 -U postgres goldberries
pg_restore -h localhost -p 5432 -U postgres -d goldberries backup.dump

```

## Dev State

In this repository a `backup-safe.dump` is included, which contains all data from goldberries dated on the 04.07.2026, excluding any user data (accounts, logs, traffic and all tables relying on these tables).
