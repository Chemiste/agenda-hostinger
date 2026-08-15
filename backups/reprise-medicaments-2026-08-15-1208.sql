-- Reprise du plan de médicaments dans le nouveau format
-- Généré le 15/08/2026 à 12:08 depuis la table medicaments
--
-- À exécuter APRÈS la migration 0020, sur des tables vides.
-- Les identifiants sont explicites pour que les liens entre
-- médicaments, moments et prises restent cohérents.

INSERT INTO medicament_moments (id, person, libelle, ordre) VALUES (1, 'Christiane', 'Matin', 0);
INSERT INTO medicament_moments (id, person, libelle, ordre) VALUES (2, 'Christiane', '15h00', 1);
INSERT INTO medicament_moments (id, person, libelle, ordre) VALUES (3, 'Christiane', 'Soir', 2);
INSERT INTO medicament_moments (id, person, libelle, ordre) VALUES (4, 'Christiane', 'Au coucher', 3);

INSERT INTO medicaments (id, person, nom, detail, image, alternative_de) VALUES (1, 'Christiane', 'ASA EG', '100 mg — anti-coagulant', 'asa-eg.jpg', 0);
INSERT INTO medicaments (id, person, nom, detail, image, alternative_de) VALUES (2, 'Christiane', 'Atorstatine EG', '20 mg — cholestérol', 'atorstatine-eg.jpg', 0);
INSERT INTO medicaments (id, person, nom, detail, image, alternative_de) VALUES (3, 'Christiane', 'Escitalopram', '5 mg x2 = 10 mg — antidépresseur', 'escitalopram.jpg', 0);
INSERT INTO medicaments (id, person, nom, detail, image, alternative_de) VALUES (4, 'Christiane', 'Nebivolol EG', '5 mg — tension', 'nebivolol-eg.jpg', 0);
INSERT INTO medicaments (id, person, nom, detail, image, alternative_de) VALUES (5, 'Christiane', 'Pantoprazole EG', '40 mg — estomac', 'pantoprazole-eg.jpg', 0);
INSERT INTO medicaments (id, person, nom, detail, image, alternative_de) VALUES (6, 'Christiane', 'Keppra', '1000 mg — antiépileptique', 'keppra.jpg', 0);
INSERT INTO medicaments (id, person, nom, detail, image, alternative_de) VALUES (7, 'Christiane', 'Lyrica', '25 mg — douleurs neuropathiques', 'lyrica.jpg', 0);
INSERT INTO medicaments (id, person, nom, detail, image, alternative_de) VALUES (8, 'Christiane', 'Hylo Dual Intense', 'collyre — yeux secs', 'hylo-dual-intense.jpg', 0);
INSERT INTO medicaments (id, person, nom, detail, image, alternative_de) VALUES (9, 'Christiane', 'Paracetamol EG Forte', '1000 mg — contre la douleur, max 3x/jour espacé de 8h', 'daeb637d22e60149.jpg', 0);
INSERT INTO medicaments (id, person, nom, detail, image, alternative_de) VALUES (10, 'Christiane', 'Omnibionta3 Energy 50+ Comprimés', '', 'b0ee56966883aedd.jpg', 0);
INSERT INTO medicaments (id, person, nom, detail, image, alternative_de) VALUES (11, 'Christiane', 'Sipralexa', '10 mg - antidépresseur', 'sipralexa-10mg.jpg', 3);
INSERT INTO medicaments (id, person, nom, detail, image, alternative_de) VALUES (12, 'Christiane', 'Calcium EG Forte', 'à croquer — calcium', 'calcium-eg-forte.jpg', 0);
INSERT INTO medicaments (id, person, nom, detail, image, alternative_de) VALUES (13, 'Christiane', 'Lormetazepam EG', '2 mg x2 = 4 mg — somnifère', 'lormetazepam-eg.jpg', 0);

INSERT INTO medicament_prises (medicament_id, moment_id, quantite) VALUES (1, 1, '1 comprimé');
INSERT INTO medicament_prises (medicament_id, moment_id, quantite) VALUES (2, 1, '1 comprimé');
INSERT INTO medicament_prises (medicament_id, moment_id, quantite) VALUES (3, 1, '2 comprimés');
INSERT INTO medicament_prises (medicament_id, moment_id, quantite) VALUES (4, 1, '1 comprimé');
INSERT INTO medicament_prises (medicament_id, moment_id, quantite) VALUES (5, 1, '1 comprimé');
INSERT INTO medicament_prises (medicament_id, moment_id, quantite) VALUES (6, 1, '1 comprimé');
INSERT INTO medicament_prises (medicament_id, moment_id, quantite) VALUES (6, 3, '1 comprimé');
INSERT INTO medicament_prises (medicament_id, moment_id, quantite) VALUES (7, 1, '1 gélule');
INSERT INTO medicament_prises (medicament_id, moment_id, quantite) VALUES (8, 1, '1 goutte / œil');
INSERT INTO medicament_prises (medicament_id, moment_id, quantite) VALUES (8, 3, '1 goutte / œil');
INSERT INTO medicament_prises (medicament_id, moment_id, quantite) VALUES (9, 1, '1 comprimé');
INSERT INTO medicament_prises (medicament_id, moment_id, quantite) VALUES (9, 2, '1 comprimé');
INSERT INTO medicament_prises (medicament_id, moment_id, quantite) VALUES (9, 4, '1 comprimé');
INSERT INTO medicament_prises (medicament_id, moment_id, quantite) VALUES (10, 1, '1 comprimé');
INSERT INTO medicament_prises (medicament_id, moment_id, quantite) VALUES (11, 1, '1 comprimé');
INSERT INTO medicament_prises (medicament_id, moment_id, quantite) VALUES (12, 3, '1 comprimé');
INSERT INTO medicament_prises (medicament_id, moment_id, quantite) VALUES (13, 4, '2 comprimés');
