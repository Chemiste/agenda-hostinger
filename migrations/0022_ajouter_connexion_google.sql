-- Migration 0022 : connexion par compte Google, et un vrai drapeau
-- "administrateur" sur les personnes.
--
-- POURQUOI. Jusqu'ici le site demandait UN mot de passe familial partage,
-- puis affichait "Qui etes-vous ?" ou chacun cliquait son nom. Ce clic
-- n'etait verifie par rien. L'identite servait pourtant de droit
-- d'acces : pathologies.php autorisait l'ajout, la modification et la
-- SUPPRESSION a quiconque s'etait declare Laurent. N'importe quel membre
-- de la famille pouvait donc effacer les pathologies de Michel ou de
-- Christiane, sans mauvaise intention et sans laisser de trace utile dans
-- le journal, puisque le journal enregistrait le nom declare.
--
-- CE QUI CHANGE. Google atteste l'identite, le site n'a plus a la croire
-- sur parole. Chaque personne qui se connecte est reliee a un compte
-- Google, et le droit de modifier devient un drapeau porte par la
-- personne, plus une comparaison de prenom eparpillee dans le code.
--
-- POURQUOI DEUX COLONNES GOOGLE. google_email sert a l'ENROLEMENT : c'est
-- ce que tu saisis dans /admin/personnes.php avant que la personne se soit
-- jamais connectee. google_sub est l'identifiant stable du compte, memorise
-- a la premiere connexion reussie et utilise ensuite en priorite. Google
-- insiste sur ce point dans sa documentation : une adresse peut changer de
-- proprietaire ou etre remplacee, "sub" ne l'est jamais. Chercher par
-- adresse ad vitam reviendrait a donner l'agenda medical de tes parents a
-- qui recupererait un jour une adresse abandonnee.
--
-- L'index UNIQUE sur google_sub empeche deux personnes de partager un
-- compte. MySQL tolere plusieurs valeurs NULL dans un index unique, donc
-- les personnes pas encore enrolees ne se genent pas entre elles.
--
-- LE MOT DE PASSE D'ADMINISTRATION RESTE, volontairement, et reste
-- independant de Google : c'est l'issue de secours si le projet Google
-- venait a disparaitre - ce qui est deja arrive une fois sur ce site et
-- avait casse la synchronisation Calendar. Sans lui, une panne cote
-- Google enfermerait tout le monde dehors sans moyen de reparer.

ALTER TABLE persons ADD COLUMN google_email VARCHAR(190) NULL AFTER nom;
ALTER TABLE persons ADD COLUMN google_sub VARCHAR(64) NULL AFTER google_email;
ALTER TABLE persons ADD COLUMN est_admin TINYINT(1) NOT NULL DEFAULT 0 AFTER peut_se_connecter;

ALTER TABLE persons ADD UNIQUE KEY idx_persons_google_sub (google_sub);
ALTER TABLE persons ADD KEY idx_persons_google_email (google_email);

-- Laurent administre le site. S'il a ete renomme, cette ligne ne trouve
-- rien et le drapeau se met a la main depuis /admin/personnes.php, page
-- qui reste joignable avec le seul mot de passe d'administration.
UPDATE persons SET est_admin = 1 WHERE nom = 'Laurent';
