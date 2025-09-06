cd /var/www/goldberries.net/api/traffic
export GB_DBPORT=5432 && export GB_DBNAME=goldberries && export GB_DBUSER=<user> && export GB_DBPASS=<password> && export TRAFFIC_FILE=/var/log/apache2/goldberries.net_traffic.log && php log_traffic.php
