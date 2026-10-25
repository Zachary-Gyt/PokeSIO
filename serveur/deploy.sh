#!/usr/bin/env bash
# =====================================================================
# Met le jeu en ligne à jour avec la dernière version poussée sur GitHub.
#   cd /var/www/pokesio && bash serveur/deploy.sh
# =====================================================================
set -e
cd /var/www/pokesio

echo "==> Maintenance (les joueurs voient « de retour dans un instant »)"
php artisan down --retry=15 || true

echo "==> Récupération du code"
git pull --ff-only

echo "==> Dépendances PHP"
composer install --no-dev --optimize-autoloader --no-interaction

echo "==> Base de données"
php artisan migrate --force

echo "==> Caches (config, routes, vues)"
php artisan optimize:clear
php artisan optimize

echo "==> Vérification des scénarios"
php artisan game:scenario || true

echo "==> Redémarrage de Reverb (multijoueur)"
sudo supervisorctl restart pokesio-reverb

php artisan up
echo "==> Terminé !"
