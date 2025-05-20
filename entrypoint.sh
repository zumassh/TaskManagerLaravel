#!/bin/bash

cd /var/www

# Установим зависимости, если нет vendor
if [ ! -d "vendor" ]; then
  echo "📦 Устанавливаю зависимости Laravel..."
  composer install
fi

echo "🔑 Генерация APP_KEY..."
php artisan key:generate --force

echo "🌐 Запуск Laravel-сервера..."
php artisan serve --host=0.0.0.0 --port=8000

echo "🚀 Запуск команды обновления задач..."
php artisan tasks:run-updater
