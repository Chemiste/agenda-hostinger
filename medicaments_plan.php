<?php
/**
 * ANCIENNE fiche imprimable, fusionnée dans /medicaments.php.
 *
 * Il y avait deux pages presque identiques : celle-ci pour imprimer, et
 * medicaments.php pour consulter. Elles affichaient la même chose et il
 * fallait maintenir deux fois la même mise en page. Désormais
 * medicaments.php fait les deux (son bouton "Imprimer" masque la
 * navigation et resserre les cartes, voir son bloc @media print).
 *
 * Ce fichier ne reste que pour les favoris et les liens déjà envoyés à la
 * famille : il redirige, il n'affiche rien. Il pourra disparaître une fois
 * qu'on sera sûr que plus personne ne l'a en raccourci.
 */

header('Location: /medicaments.php', true, 301);
exit;
