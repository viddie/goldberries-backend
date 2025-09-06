# goldberries.net

[goldberries.net](https://goldberries.net) is an archive of the community's efforts of playing modded Celeste maps without dying. In contrast to the old Google spreadsheet only listing all challenge completions, goldberries.net provides a proper database, verification queue, web-frontend, API and much more.

## Quick Links

- [Issue Tracker](https://github.com/yoshiyoshyosh/goldberries/issues)
- [Frontend Repository](https://github.com/viddie/goldberries-frontend)
- [Backend Repository](https://github.com/viddie/goldberries-backend) [YOU ARE HERE]
- [Celeste Modded Done Deathless Discord Server](https://discord.gg/GeJvmMycaC)

## Frontend Repository

This repository contains the backend. The backend provides an API written in PHP, ontop of a postgres database.

### Setup

#### Database

- PostgreSQL
- Run the build script from `/documentation/models/database-build-scripts/goldberries.sql`

#### Backend

##### Webserver

Some webserver that allows PHP (like nginx or apache2)

##### Virtual Host file (Apache2)

Setup an SSL certificate with LetsEncrypt, then you can use the following virtual host file for Apache2. The important part is the custom logging format to log all requests to a file, which will be handled later to extract the traffic to the database.

```
<IfModule mod_ssl.c>
<VirtualHost *:443>
        ServerAdmin webmaster@goldberries.net
        ServerName goldberries.net
        ServerAlias www.goldberries.net
        DocumentRoot /var/www/goldberries.net
        ErrorLog ${APACHE_LOG_DIR}/goldberries.net_error.log
        CustomLog ${APACHE_LOG_DIR}/goldberries.net_access.log combined

        LogFormat "%t   %m      %U      %>s     %q      %{Referer}i     %{User-agent}i  %{ms}T" traffic
        CustomLog ${APACHE_LOG_DIR}/goldberries.net_traffic.log traffic

        Include /etc/letsencrypt/options-ssl-apache.conf
        SSLCertificateFile /etc/letsencrypt/live/goldberries.net/fullchain.pem
        SSLCertificateKeyFile /etc/letsencrypt/live/goldberries.net/privkey.pem
</VirtualHost>
</IfModule>
```

##### Setup `php.ini`

- Extensions:
  - `curl`
  - `gd`
  - `mbstring`
  - `pgsql`
- `memory_limit = 256M`
- `post_max_size = 8M`
- `file_uploads = On`
- `upload_max_filesize = 8M`

##### Environment variables

```
export DEBUG=false

export GB_DBPORT=5432
export GB_DBNAME=goldberries
export GB_DBUSER=<user>
export GB_DBPASS=<password>

export DISCORD_CLIENT_ID=<client-id>
export DISCORD_CLIENT_SECRET=<secret>

export SUGGESTION_BOX_WEBHOOK_URL=<url>
export CHANGELOG_WEBHOOK_URL=<url>
export NOTIFICATIONS_WEBHOOK_URL=<url>
export POST_CHANGELOG_WEBHOOK_URL=<url>
export POST_NEWS_WEBHOOK_URL=<url>
export REPORT_WEBHOOK_URL=<url>
export MOD_REPORT_WEBHOOK_URL=<url>

export PYTHON_COMMAND=python3
export TRAFFIC_FILE=/var/log/apache2/goldberries.net_traffic.log
```

##### Traffic Script

Edit the shell script under `/api/traffic/log.sh` to add your DB user and password:

```sh
cd /var/www/goldberries.net/api/traffic
export GB_DBPORT=5432 && export GB_DBNAME=goldberries && export GB_DBUSER=<user> && export GB_DBPASS=<password> && export TRAFFIC_FILE=/var/log/apache2/goldberries.net_traffic.log && php log_traffic.php
```

Add a cron job that runs it every 15 minutes:  
`*/15 * * * * /var/www/goldberries.net/api/traffic/log.sh`

### Run

To run the backend:

- Start the database server
- Start the backend server

## How to Contribute

Depending on the type of resource you want to help with:

- UI/UX designs or improvements: @viddie in `#gb-chat` on the Discord server
- Code: PR request to the appropriate repository
- Translations: Check [this message](https://discord.com/channels/790156040653897749/1269306272776458321/1369597085090844803) from the Discord server, then @viddie in `#gb-chat`

## Report Issues

Any data issues (incorrect map name, missing submission videos, ...) should be reported in the `#gb-report` channel in the Discord server linked above.

For application issues, open a new issue in the Issue Tracker linked above.
