#!/bin/bash
cd /var/www/goldberries.net/api/maintenance

export GB_DBPORT=
export GB_DBNAME=
export GB_DBUSER=
export GB_DBPASS=

php run.php "$1"
