#!/bin/bash
#
# Wrapper autour de cron/backup.php, pensé pour les tâches Cron "Personnalisé"
# de Hostinger : certains hébergeurs n'aiment pas les guillemets/options
# tapés directement dans le champ "Commande à exécuter" du panneau, ce qui
# peut faire échouer silencieusement une commande wget collée telle quelle.
# En pointant la tâche Cron vers ce script à la place, toute la commande
# reste dans un fichier normal, sans souci d'échappement.
#
# Ne contient jamais le jeton secret en clair : il est lu directement dans
# config.php au moment de l'exécution (source unique de vérité), pas
# dupliqué ici ni dans la commande Hostinger.
#
# Configuration de la tâche Cron dans hPanel (Avancé > Tâches Cron),
# type "Personnalisé" :
#   bash /chemin/complet/vers/le/site/cron/backup.sh
#
# (remplacez /chemin/complet/vers/le/site par le chemin réel sur votre
# hébergement, visible par exemple dans le Gestionnaire de fichiers de
# hPanel, ou dans le champ pré-rempli d'une tâche Cron de type "PHP").

DOSSIER="$(cd "$(dirname "$0")/.." && pwd)"
TOKEN=$(php -r "\$c = require '$DOSSIER/config.php'; echo \$c['backup_token'] ?? '';")

if [ -z "$TOKEN" ]; then
  echo "Jeton backup_token introuvable ou vide dans config.php" >&2
  exit 1
fi

wget -q -O /dev/null --user-agent="Mozilla/5.0 (compatible; AgendaMedicalCron/1.0)" \
  "https://agenda.hellau.be/cron/backup.php?token=${TOKEN}"
