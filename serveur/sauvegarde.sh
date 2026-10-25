#!/usr/bin/env bash
# =====================================================================
# Sauvegarde de la base de données, une fois par jour (lancé par cron).
# Garde les 14 dernières dans /home/deploy/sauvegardes.
#   crontab -e   puis ajoute la ligne :
#   30 4 * * * bash /var/www/pokesio/serveur/sauvegarde.sh
# =====================================================================
set -e
DIR=/home/deploy/sauvegardes
mkdir -p "$DIR"
# Les identifiants sont lus dans /home/deploy/.my.cnf (voir le LISEZMOI, étape 11)
mysqldump --single-transaction --no-tablespaces pokesio | gzip > "$DIR/pokesio_$(date +%F).sql.gz"
ls -1t "$DIR"/pokesio_*.sql.gz | tail -n +15 | xargs -r rm --
