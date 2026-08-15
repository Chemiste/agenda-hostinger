<?php
/**
 * OUTIL PONCTUEL - reprise du plan de médicaments dans le nouveau format
 * (voir migrations/0020_restructurer_medicaments.sql).
 *
 * Lit l'ancienne table (une ligne = un médicament POUR UN moment) et en
 * déduit les trois nouvelles notions :
 *   - medicament_moments : les moments distincts, par personne
 *   - medicaments        : un par médicament (regroupés par nom)
 *   - medicament_prises  : le croisement médicament x moment + quantité
 *
 * CE SCRIPT N'ÉCRIT JAMAIS EN BASE. Deux modes :
 *
 *   Aperçu (par défaut)  : affiche ce qui serait créé, et surtout les cas
 *                          ambigus (même nom avec deux photos ou deux
 *                          détails différents). À lire avant tout le reste.
 *   Génération (?mode=generer) : écrit un fichier .sql dans backups/, que
 *                          vous pouvez relire puis exécuter vous-même.
 *
 * Il trouve tout seul la table à lire : "medicaments_v1" si la migration
 * 0020 est déjà passée, sinon l'ancienne "medicaments" - ce qui permet de
 * générer le fichier depuis la production AVANT d'y appliquer la migration.
 *
 * En ligne de commande :  php outils/reprendre_medicaments.php [generer]
 */

require_once __DIR__ . '/../lib/db.php';

const DOSSIER_SORTIE = __DIR__ . '/../backups';

/**
 * Trouve la table contenant l'ancien format et verifie qu'elle a bien la
 * forme attendue (colonne "moment"), pour ne pas lire par erreur la
 * nouvelle table "medicaments" qui porte le meme nom.
 */
function trouverTableSource($db) {
    foreach (['medicaments_v1', 'medicaments'] as $table) {
        try {
            $colonnes = $db->query('SHOW COLUMNS FROM ' . $table)->fetchAll(PDO::FETCH_COLUMN);
        } catch (Exception $e) {
            continue;
        }
        if (in_array('moment', $colonnes, true)) {
            return $table;
        }
    }
    return null;
}

/**
 * Coeur de la reprise : transforme les lignes de l'ancien format en
 * moments / medicaments / prises, et signale ce qui demande un arbitrage.
 * Ne fait que calculer - rien n'est ecrit ici.
 */
function analyserReprise($lignes) {
    $moments = [];        // "personne|libelle" => [id, person, libelle, ordre]
    $medicaments = [];    // "personne|nom minuscule" => [id, person, nom, detail, image, ...]
    $prises = [];
    $avertissements = [];
    $ligneVersMedicament = [];  // ancien id de ligne => nouvel id de medicament

    $prochainMoment = 1;
    $prochainMedicament = 1;

    // --- 1. Les moments, dans leur ordre d'affichage actuel ---
    foreach ($lignes as $l) {
        $cle = $l['person'] . '|' . $l['moment'];
        if (!isset($moments[$cle])) {
            $moments[$cle] = [
                'id' => $prochainMoment++,
                'person' => $l['person'],
                'libelle' => $l['moment'],
                'ordre' => (int) $l['ordre_moment'],
            ];
        } else {
            // Meme moment vu avec un ordre different : on garde le plus
            // petit, c'est celui qui decidait deja de l'affichage.
            $moments[$cle]['ordre'] = min($moments[$cle]['ordre'], (int) $l['ordre_moment']);
        }
    }

    // --- 2. Les medicaments, regroupes par nom (insensible a la casse) ---
    foreach ($lignes as $l) {
        $nom = trim($l['nom']);
        $cle = $l['person'] . '|' . mb_strtolower($nom);

        if (!isset($medicaments[$cle])) {
            $medicaments[$cle] = [
                'id' => $prochainMedicament++,
                'person' => $l['person'],
                'nom' => $nom,
                'detail' => trim($l['detail']),
                'image' => $l['image'],
                'alternative_de_ligne' => (int) $l['alternative_de'],
                'lignes' => [],
            ];
        } else {
            $m = &$medicaments[$cle];
            // Valeur absente jusqu'ici : on la prend. Valeur differente :
            // on garde la premiere et on signale, c'est a vous de trancher.
            if ($m['detail'] === '' && trim($l['detail']) !== '') {
                $m['detail'] = trim($l['detail']);
            } elseif (trim($l['detail']) !== '' && $m['detail'] !== trim($l['detail'])) {
                $avertissements[] = 'Détail différent pour « ' . $nom . ' » : « ' . $m['detail']
                    . ' » retenu, « ' . trim($l['detail']) . ' » ignoré.';
            }
            if ($m['image'] === '' && $l['image'] !== '') {
                $m['image'] = $l['image'];
            } elseif ($l['image'] !== '' && $m['image'] !== $l['image']) {
                $avertissements[] = 'Photo différente pour « ' . $nom . ' » : ' . $m['image']
                    . ' retenue, ' . $l['image'] . ' ignorée.';
            }
            if ($m['alternative_de_ligne'] === 0 && (int) $l['alternative_de'] > 0) {
                $m['alternative_de_ligne'] = (int) $l['alternative_de'];
            }
            unset($m);
        }

        $medicaments[$cle]['lignes'][] = $l;
        $ligneVersMedicament[(int) $l['id']] = $medicaments[$cle]['id'];
    }

    // --- 3. Les prises : une par ligne d'origine ---
    $vues = [];
    foreach ($medicaments as $m) {
        foreach ($m['lignes'] as $l) {
            $cleMoment = $l['person'] . '|' . $l['moment'];
            $cleprise = $m['id'] . '|' . $moments[$cleMoment]['id'];
            if (isset($vues[$cleprise])) {
                $avertissements[] = '« ' . $m['nom'] .' » apparaît deux fois au moment « '
                    . $l['moment'] . ' » : une seule prise créée (quantité « '
                    . $vues[$cleprise] . ' » retenue).';
                continue;
            }
            $vues[$cleprise] = trim($l['quantite']);
            $prises[] = [
                'medicament_id' => $m['id'],
                'moment_id' => $moments[$cleMoment]['id'],
                'quantite' => trim($l['quantite']),
            ];
        }
    }

    // --- 4. Les alternatives : elles pointaient vers une LIGNE, elles
    //        pointent desormais vers un MEDICAMENT ---
    $alternatives = 0;
    foreach ($medicaments as $cle => $m) {
        $ancienParent = $m['alternative_de_ligne'];
        $medicaments[$cle]['alternative_de'] = 0;
        if ($ancienParent > 0) {
            if (isset($ligneVersMedicament[$ancienParent])) {
                $nouveauParent = $ligneVersMedicament[$ancienParent];
                if ($nouveauParent === $m['id']) {
                    $avertissements[] = '« ' . $m['nom'] . ' » se retrouverait sa propre alternative : lien ignoré.';
                } else {
                    $medicaments[$cle]['alternative_de'] = $nouveauParent;
                    $alternatives++;
                }
            } else {
                $avertissements[] = 'Alternative « ' . $m['nom'] . ' » : le médicament principal (ligne '
                    . $ancienParent . ") n'existe plus, lien ignoré.";
            }
        }
    }

    return [
        'moments' => array_values($moments),
        'medicaments' => array_values($medicaments),
        'prises' => $prises,
        'alternatives' => $alternatives,
        'avertissements' => $avertissements,
    ];
}

/**
 * Fabrique le contenu du fichier .sql a partir de l'analyse. Les
 * identifiants sont ecrits explicitement pour que les liens (prises,
 * alternatives) restent coherents quel que soit l'ordre d'insertion.
 */
function construireSql($db, $analyse, $tableSource) {
    $sql = "-- Reprise du plan de médicaments dans le nouveau format\n";
    $sql .= '-- Généré le ' . date('d/m/Y à H:i') . ' depuis la table ' . $tableSource . "\n";
    $sql .= "--\n";
    $sql .= "-- À exécuter APRÈS la migration 0020, sur des tables vides.\n";
    $sql .= "-- Les identifiants sont explicites pour que les liens entre\n";
    $sql .= "-- médicaments, moments et prises restent cohérents.\n\n";

    foreach ($analyse['moments'] as $m) {
        $sql .= 'INSERT INTO medicament_moments (id, person, libelle, ordre) VALUES ('
            . (int) $m['id'] . ', '
            . $db->quote($m['person']) . ', '
            . $db->quote($m['libelle']) . ', '
            . (int) $m['ordre'] . ");\n";
    }
    $sql .= "\n";

    foreach ($analyse['medicaments'] as $m) {
        $sql .= 'INSERT INTO medicaments (id, person, nom, detail, image, alternative_de) VALUES ('
            . (int) $m['id'] . ', '
            . $db->quote($m['person']) . ', '
            . $db->quote($m['nom']) . ', '
            . $db->quote($m['detail']) . ', '
            . $db->quote($m['image']) . ', '
            . (int) $m['alternative_de'] . ");\n";
    }
    $sql .= "\n";

    foreach ($analyse['prises'] as $p) {
        $sql .= 'INSERT INTO medicament_prises (medicament_id, moment_id, quantite) VALUES ('
            . (int) $p['medicament_id'] . ', '
            . (int) $p['moment_id'] . ', '
            . $db->quote($p['quantite']) . ");\n";
    }

    return $sql;
}

// ---------------------------------------------------------------
// Execution
// ---------------------------------------------------------------

function executerReprise($genererFichier) {
    $db = getDb();
    $tableSource = trouverTableSource($db);
    if ($tableSource === null) {
        throw new Exception(
            "Aucune table à l'ancien format trouvée (ni « medicaments_v1 », ni une table "
            . "« medicaments » avec une colonne « moment »). La reprise a peut-être déjà été faite."
        );
    }

    $lignes = $db->query('SELECT * FROM ' . $tableSource . ' ORDER BY ordre_moment, ordre, id')->fetchAll();
    if (empty($lignes)) {
        throw new Exception('La table ' . $tableSource . ' est vide : rien à reprendre.');
    }

    $analyse = analyserReprise($lignes);
    $analyse['table_source'] = $tableSource;
    $analyse['lignes_lues'] = count($lignes);
    $analyse['fichier'] = null;

    if ($genererFichier) {
        if (!is_dir(DOSSIER_SORTIE)) {
            mkdir(DOSSIER_SORTIE, 0755, true);
        }
        $chemin = DOSSIER_SORTIE . '/reprise-medicaments-' . date('Y-m-d-Hi') . '.sql';
        file_put_contents($chemin, construireSql($db, $analyse, $tableSource));
        $analyse['fichier'] = $chemin;
    }

    return $analyse;
}

// --- Mode ligne de commande ---
if (php_sapi_name() === 'cli') {
    try {
        $generer = in_array('generer', array_slice($argv, 1), true);
        $a = executerReprise($generer);

        echo "Table lue : " . $a['table_source'] . ' (' . $a['lignes_lues'] . " lignes)\n\n";
        echo "Moments (" . count($a['moments']) . ") :\n";
        foreach ($a['moments'] as $m) {
            echo '  [' . $m['ordre'] . '] ' . $m['person'] . ' - ' . $m['libelle'] . "\n";
        }
        // Nom du medicament principal a partir de son id, pour que la
        // ligne "ALTERNATIVE" dise de quoi il s'agit - c'est justement ce
        // qu'on veut relire avant de generer.
        $nomsParId = [];
        foreach ($a['medicaments'] as $m) {
            $nomsParId[$m['id']] = $m['nom'];
        }

        echo "\nMédicaments (" . count($a['medicaments']) . ") :\n";
        foreach ($a['medicaments'] as $m) {
            $nb = 0;
            $quantites = [];
            foreach ($a['prises'] as $p) {
                if ($p['medicament_id'] === $m['id']) {
                    $nb++;
                    $quantites[] = $p['quantite'] !== '' ? $p['quantite'] : '(sans quantité)';
                }
            }
            $alt = '';
            if ($m['alternative_de'] > 0) {
                $alt = ' — ALTERNATIVE à ' . (isset($nomsParId[$m['alternative_de']])
                    ? $nomsParId[$m['alternative_de']] : '?');
            }
            echo '  ' . $m['nom'] . ' — ' . $nb . ' prise(s) [' . implode(' | ', $quantites) . ']'
                . ($m['image'] !== '' ? ' — photo ' . $m['image'] : ' — SANS PHOTO')
                . $alt . "\n";
        }
        echo "\nPrises : " . count($a['prises']) . "\n";
        echo "Alternatives : " . $a['alternatives'] . "\n";

        if (!empty($a['avertissements'])) {
            echo "\nÀ VÉRIFIER (" . count($a['avertissements']) . ") :\n";
            foreach ($a['avertissements'] as $av) {
                echo '  - ' . $av . "\n";
            }
        } else {
            echo "\nAucun cas ambigu détecté.\n";
        }

        if ($a['fichier'] !== null) {
            echo "\nFichier écrit : " . $a['fichier'] . "\n";
        } else {
            echo "\n(Aperçu seulement — relancez avec « generer » pour écrire le fichier .sql)\n";
        }
        exit(0);
    } catch (Exception $e) {
        fwrite(STDERR, 'Erreur : ' . $e->getMessage() . "\n");
        exit(1);
    }
}

// --- Mode navigateur (réservé à l'administration) ---
require_once __DIR__ . '/../lib/auth.php';
requireAdminLogin();

$erreur = '';
$analyse = null;
try {
    $analyse = executerReprise(isset($_GET['mode']) && $_GET['mode'] === 'generer');
} catch (Exception $e) {
    $erreur = $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Reprise du plan de médicaments</title>
<link rel="icon" type="image/svg+xml" href="/assets/favicon.svg">
<link rel="stylesheet" href="/assets/style.css?v=<?= filemtime(__DIR__ . '/../assets/style.css') ?>">
<link rel="stylesheet" href="/assets/admin.css?v=<?= filemtime(__DIR__ . '/../assets/admin.css') ?>">
</head>
<body>
  <div class="barre-admin">
    <h1>Reprise du plan de médicaments</h1>
    <div><a href="/admin/index.php">Retour à l'administration</a></div>
  </div>

  <?php if ($erreur): ?>
    <p class="erreur"><?= htmlspecialchars($erreur) ?></p>
  <?php else: ?>
    <p class="sous-titre" style="margin-bottom:18px;">
      Lecture seule : rien n'est écrit en base. Table lue :
      <strong><?= htmlspecialchars($analyse['table_source']) ?></strong>
      (<?= (int) $analyse['lignes_lues'] ?> lignes).
    </p>

    <?php if (!empty($analyse['avertissements'])): ?>
      <div class="outil" style="margin-bottom:16px;">
        <h2 class="panneau-titre" style="font-size:15px;">À vérifier (<?= count($analyse['avertissements']) ?>)</h2>
        <ul>
          <?php foreach ($analyse['avertissements'] as $av): ?>
            <li><?= htmlspecialchars($av) ?></li>
          <?php endforeach; ?>
        </ul>
      </div>
    <?php else: ?>
      <p class="info">Aucun cas ambigu détecté.</p>
    <?php endif; ?>

    <div class="outil">
      <h2 class="panneau-titre" style="font-size:15px;">Moments (<?= count($analyse['moments']) ?>)</h2>
      <ul>
        <?php foreach ($analyse['moments'] as $m): ?>
          <li><?= htmlspecialchars($m['person']) ?> — <?= htmlspecialchars($m['libelle']) ?> (ordre <?= (int) $m['ordre'] ?>)</li>
        <?php endforeach; ?>
      </ul>
    </div>

    <div class="outil" style="margin-top:16px;">
      <h2 class="panneau-titre" style="font-size:15px;">Médicaments (<?= count($analyse['medicaments']) ?>)</h2>
      <ul>
        <?php foreach ($analyse['medicaments'] as $m): ?>
          <?php
            $nbPrises = 0;
            foreach ($analyse['prises'] as $p) {
                if ($p['medicament_id'] === $m['id']) $nbPrises++;
            }
          ?>
          <li>
            <strong><?= htmlspecialchars($m['nom']) ?></strong> — <?= $nbPrises ?> prise(s)
            <?= $m['image'] !== '' ? ' — photo ' . htmlspecialchars($m['image']) : ' — sans photo' ?>
            <?php if ($m['alternative_de'] > 0): ?>
              <?php
                $nomParent = '?';
                foreach ($analyse['medicaments'] as $autre) {
                    if ($autre['id'] === $m['alternative_de']) { $nomParent = $autre['nom']; break; }
                }
              ?>
              — <em>alternative à <?= htmlspecialchars($nomParent) ?></em>
            <?php endif; ?>
          </li>
        <?php endforeach; ?>
      </ul>
      <p class="aide"><?= count($analyse['prises']) ?> prise(s) au total, <?= (int) $analyse['alternatives'] ?> alternative(s).</p>
    </div>

    <div class="form-boutons" style="margin-top:16px;">
      <?php if ($analyse['fichier'] !== null): ?>
        <p class="info">Fichier écrit : <code><?= htmlspecialchars(basename($analyse['fichier'])) ?></code> (dans <code>backups/</code>)</p>
      <?php else: ?>
        <a class="principal" href="?mode=generer">Générer le fichier .sql</a>
      <?php endif; ?>
    </div>
  <?php endif; ?>
</body>
</html>
