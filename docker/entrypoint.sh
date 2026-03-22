#!/bin/bash

# Wait for PostgreSQL to be ready
until pg_isready -h postgres -U db_user > /dev/null 2>&1; do
  echo "Waiting for PostgreSQL..."
  sleep 2
done

echo "PostgreSQL is ready!"

# Install Composer dependencies
if [ ! -d "vendor" ]; then
  echo "Installing Composer dependencies..."
  composer install --no-interaction --optimize-autoloader
fi

# Create var directory
mkdir -p var/cache var/log

# Set proper permissions for Symfony
chmod -R 775 var/
chown -R www-data:www-data var/

# Create database if it doesn't exist
php bin/console doctrine:database:create --if-not-exists --no-interaction

# Execute migrations
php bin/console doctrine:migrations:migrate --no-interaction

echo "Container is ready!"

# Execute the main command
exec "$@"
