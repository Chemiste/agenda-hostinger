<?php
/**
 * Alias d'affichage pour les adresses (table "address_aliases", voir
 * migrations/0010_add_address_aliases.sql).
 *
 * Permet de definir, par exemple, que "Avenue Hippocrate, 10, 1200
 * Bruxelles" doit s'afficher comme "Hopital St Luc" dans l'agenda et les
 * impressions - UNIQUEMENT a l'affichage : le champ "location" reste
 * l'adresse reelle en base de donnees et sur Google Calendar, pour que
 * Waze/Maps puissent naviguer correctement depuis un evenement du
 * calendrier partage.
 */

function listerAliasAdresses($db) {
    return $db->query('SELECT id, motif, remplacement FROM address_aliases ORDER BY motif')->fetchAll();
}

function ajouterAliasAdresse($db, $motif, $remplacement) {
    $motif = trim($motif);
    $remplacement = trim($remplacement);
    if ($motif === '' || $remplacement === '') {
        throw new Exception('Le texte recherché et son remplacement sont obligatoires.');
    }
    $stmt = $db->prepare('INSERT INTO address_aliases (motif, remplacement) VALUES (?, ?)');
    $stmt->execute([$motif, $remplacement]);
}

function supprimerAliasAdresse($db, $id) {
    $stmt = $db->prepare('DELETE FROM address_aliases WHERE id = ?');
    $stmt->execute([(int) $id]);
}

/**
 * Applique tous les alias connus a un texte d'adresse, pour l'affichage
 * uniquement. Remplacement insensible a la casse, sur simple
 * correspondance de sous-chaine (comme l'outil "Texte libre" de
 * admin/corriger.php) : pas besoin que l'adresse stockee corresponde au
 * caractere pres.
 */
function appliquerAliasAdresse($texte, $aliases) {
    if ($texte === '' || $texte === null) return $texte;
    foreach ($aliases as $alias) {
        if ($alias['motif'] === '') continue;
        if (stripos($texte, $alias['motif']) !== false) {
            $texte = str_ireplace($alias['motif'], $alias['remplacement'], $texte);
        }
    }
    return $texte;
}
