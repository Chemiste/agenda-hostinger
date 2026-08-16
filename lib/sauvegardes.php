<?php
/**
 * Ce qui est sauvegarde, et comment le remettre en place.
 *
 * DEUX BESOINS DISTINCTS, deux outils :
 *   - recuperer UNE ligne effacee par erreur -> admin/sauvegardes.php,
 *     qui reinjecte des rendez-vous choisis un par un sans toucher au
 *     reste, et recree au passage l'evenement Google Calendar
 *   - reconstruire la base apres un sinistre -> admin/restaurer_tout.php,
 *     qui remplace des tables entieres
 *
 * Les confondre serait dangereux dans les deux sens : un outil qui efface
 * tout n'a rien a faire dans un geste courant, et un outil qui ajoute ligne
 * a ligne ne reconstruit jamais un etat coherent.
 *
 * POURQUOI CE FICHIER EXISTE. La liste des tables vivait dans
 * cron/backup.php, et la restauration ne connaissait qu'appointments. Cinq
 * tables n'etaient sauvegardees nulle part - dont medecins, le carnet
 * d'adresses saisi a la main pendant des mois - et les cinq qui l'etaient
 * n'avaient aucun moyen d'etre remises en place. La sauvegarde inspirait
 * donc plus de confiance qu'elle n'en meritait.
 */

/**
 * Toutes les tables de donnees, dans l'ordre ou il faut les restaurer.
 *
 * persons EN PREMIER : tout le reste s'y rattache par person_id. Aucune
 * cle etrangere ne l'impose au niveau de MySQL, mais restaurer les
 * medicaments avant les personnes laisserait, le temps de la manoeuvre,
 * un plan de prise rattache a personne.
 *
 * Deux tables sont volontairement absentes :
 *   - schema_migrations, que outils/migrate.php reconstruit tout seul et
 *     qu'il ne faut surtout pas figer dans une sauvegarde
 *   - medicaments_v1, vestige de la restructuration 0020, qui disparaitra
 *
 * @return array prefixe de fichier => nom de la table
 */
function tablesSauvegardees() {
    return [
        'persons'            => 'persons',
        'appointments'       => 'appointments',
        'medecins'           => 'medecins',
        'taches'             => 'taches',
        'medicaments'        => 'medicaments',
        'medicament_moments' => 'medicament_moments',
        'medicament_prises'  => 'medicament_prises',
        'pathologies'        => 'pathologies',
        'address_aliases'    => 'address_aliases',
        'settings'           => 'settings',
        'activity_log'       => 'activity_log',
    ];
}

/** Les colonnes que cette table possede REELLEMENT aujourd'hui. */
function colonnesTable($db, $table) {
    $colonnes = [];
    foreach ($db->query('SHOW COLUMNS FROM ' . $table) as $ligne) {
        $colonnes[] = $ligne['Field'];
    }
    return $colonnes;
}

/**
 * Compare une sauvegarde au schema actuel, sans rien modifier.
 *
 * Une sauvegarde de deux mois peut avoir ete ecrite avant une migration :
 * il lui manque des colonnes ajoutees depuis, et elle en contient parfois
 * qui ont disparu. Plutot que d'echouer ou d'ecrire n'importe quoi, on
 * restaure l'intersection - et on dit ce qui a ete laisse de cote.
 *
 * @return array{communes:string[], absentes_du_fichier:string[], disparues:string[]}
 */
function comparerSauvegardeAuSchema($db, $table, $lignes) {
    $colonnesBase = colonnesTable($db, $table);
    $colonnesFichier = empty($lignes) ? [] : array_keys((array) $lignes[0]);

    return [
        'communes' => array_values(array_intersect($colonnesBase, $colonnesFichier)),
        // Presentes en base, absentes du fichier : elles prendront la
        // valeur par defaut de la colonne.
        'absentes_du_fichier' => array_values(array_diff($colonnesBase, $colonnesFichier)),
        // Presentes dans le fichier, disparues de la base : ignorees.
        'disparues' => array_values(array_diff($colonnesFichier, $colonnesBase)),
    ];
}

/**
 * REMPLACE le contenu d'une table par celui d'une sauvegarde.
 *
 * Destructif : tout ce que la table contient est efface. C'est le geste
 * attendu d'une reconstruction - remettre l'etat d'une date donnee, pas
 * fusionner deux etats. L'appelant DOIT avoir pris une sauvegarde de
 * securite avant (voir ecrireSauvegarde).
 *
 * DELETE et non TRUNCATE : TRUNCATE declenche un commit implicite en
 * MySQL et ne peut pas etre annule, ce qui viderait la table meme si les
 * insertions echouaient ensuite. Avec DELETE, la transaction protege
 * l'ensemble.
 *
 * @return int le nombre de lignes ecrites
 */
function restaurerTableDepuisJson($db, $table, $lignes) {
    $comparaison = comparerSauvegardeAuSchema($db, $table, $lignes);
    $colonnes = $comparaison['communes'];
    if (empty($lignes)) {
        $colonnes = [];
    }
    if (empty($colonnes) && !empty($lignes)) {
        throw new Exception("Sauvegarde de « $table » inexploitable : aucune de ses colonnes n'existe encore.");
    }

    $db->beginTransaction();
    try {
        $db->exec('DELETE FROM ' . $table);

        if (!empty($colonnes)) {
            $marqueurs = implode(', ', array_fill(0, count($colonnes), '?'));
            $stmt = $db->prepare(
                'INSERT INTO ' . $table . ' (`' . implode('`, `', $colonnes) . '`) VALUES (' . $marqueurs . ')'
            );
            foreach ($lignes as $ligne) {
                $valeurs = [];
                foreach ($colonnes as $c) {
                    $valeurs[] = isset($ligne[$c]) ? $ligne[$c] : null;
                }
                $stmt->execute($valeurs);
            }
        }

        $db->commit();
        return count($lignes);
    } catch (Exception $e) {
        $db->rollBack();
        throw new Exception('Restauration de « ' . $table . " » abandonnée, la table est intacte : " . $e->getMessage());
    }
}

/**
 * Ecrit une sauvegarde complete et retourne l'horodatage utilise.
 *
 * Partage par le cron quotidien et par la sauvegarde de securite que
 * admin/restaurer_tout.php prend juste avant d'ecraser quoi que ce soit -
 * c'est ce qui rend une restauration reversible, y compris quand on s'est
 * trompe de date.
 *
 * @param string $suffixe Ajoute au nom des fichiers, pour distinguer une
 *        sauvegarde automatique d'une sauvegarde de securite.
 */
function ecrireSauvegarde($db, $dossier, $suffixe = '') {
    $horodatage = date('Y-m-d-Hi') . $suffixe;
    $resume = [];
    $avertissements = [];

    foreach (tablesSauvegardees() as $prefixe => $table) {
        try {
            // Pas de ORDER BY id : la table settings n'a pas de colonne
            // "id" (cle/valeur), la requete echouerait pour elle seule.
            $lignes = $db->query('SELECT * FROM ' . $table)->fetchAll();
        } catch (Exception $e) {
            // Table absente sur cet environnement (migration pas encore
            // appliquee) : les autres sont quand meme ecrites.
            $avertissements[] = $table . ' non sauvegardée';
            continue;
        }
        $fichier = $dossier . '/' . $prefixe . '-' . $horodatage . '.json';
        file_put_contents($fichier, json_encode($lignes, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        $resume[$prefixe] = count($lignes);
    }

    return ['horodatage' => $horodatage, 'resume' => $resume, 'avertissements' => $avertissements];
}
