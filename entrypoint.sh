#!/bin/bash
set -e

# Initialize MariaDB directory if not already done
if [ ! -d "/var/lib/mysql/mysql" ]; then
    mysql_install_db --user=mysql --datadir=/var/lib/mysql
fi

# Start MariaDB in the background
mysqld_safe --user=mysql --datadir=/var/lib/mysql &

# Wait for MariaDB to start up
for i in {30..0}; do
    if mysqladmin ping --silent; then
        break
    fi
    echo 'Waiting for MariaDB to start...'
    sleep 1
done

# Configure database and import schema
mysql -e "CREATE DATABASE IF NOT EXISTS englai;"
mysql -e "CREATE USER IF NOT EXISTS 'root'@'localhost' IDENTIFIED BY '';"
mysql -e "GRANT ALL PRIVILEGES ON englai.* TO 'root'@'localhost';"
mysql -e "FLUSH PRIVILEGES;"

if [ -f "/var/www/html/englai.sql" ]; then
    echo "Importing database schema..."
    mysql englai < /var/www/html/englai.sql
fi

# Start Apache in the foreground
apache2-foreground
