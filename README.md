# Pokémon TCG App

Projet migré d’Express vers **Symfony 8.1**, avec Twig et JavaScript natif. Il faut PHP **8.4.1 ou plus récent** avec les extensions XML, SimpleXML, iconv et zlib, ainsi que Composer 2.1 ou plus récent. Le projet ne demande ni Node.js ni base de données SQL.

## Démarrage sous Windows

Décompressez le ZIP et double-cliquez sur `LANCER.bat`. Le script vérifie la version de PHP et installe les dépendances verrouillées. Ouvrez ensuite [http://localhost:3000](http://localhost:3000).

Vous pouvez aussi lancer ces commandes dans PowerShell, dans le dossier qui contient `composer.json` :

```powershell
composer install
php bin/console cache:clear
php bin/console app:roms:password
php -d upload_max_filesize=16G -d post_max_size=17G -d max_input_time=300 -S localhost:3000 -t public public/router.php
```

Après une mise à jour, continuez à utiliser la même adresse pour retrouver vos cartes, vos decks et vos sauvegardes du lecteur DS dans le navigateur. Pour vérifier les versions actives, lancez `php -v` puis `php bin/console --version`.

Pour un hébergement, configurez `public/` comme dossier public et redirigez les routes applicatives vers `public/index.php`. Le fichier `public/router.php` sert au démarrage local. Le répertoire `var/` doit être accessible en écriture par PHP.

## Catalogue et données

- Le catalogue récupère ses données de [TCGdex](https://tcgdex.dev/fr/rest/cards). Le ZIP inclut les index du 19 novembre 2026, avec 22 342 entrées en français et 23 912 en anglais. La recherche par nom ou identifiant, les filtres et la pagination s’appliquent à toutes les entrées de la langue choisie. Chaque langue a sa propre couverture. Certaines entrées de TCGdex sont dépourvues de visuel.
- Les fiches de cartes chargent les données de TCGdex et les mettent en cache pour 24 heures. Une fiche simplifiée reste disponible depuis l’index si la source ne répond pas. Les images sont servies par la source. Les boosters virtuels tirent cinq cartes illustrées distinctes parmi les résultats filtrés. Le quota est de 10 tirages aléatoires ou 3 tirages par type et par jour, dans le navigateur.
- Le Pokédex inclut 1 025 espèces et 326 formes alternatives, avec les données de [PokéAPI](https://pokeapi.co/docs/v2). Symfony récupère les cris modernes et classiques via PokéAPI et les sert sous `/api/pokemon/{id}/cry/{latest|legacy}`. Il conserve une copie audio en cache après la première lecture. Les fiches de cartes donnent aussi accès aux cris quand TCGdex fournit un numéro de Pokédex. Une connexion est nécessaire pour charger les images et récupérer chaque cri la première fois.
- Avec un compte, **Ma collection** conserve les cartes, les quantités et les favoris dans PostgreSQL. Les boosters ouverts connecté alimentent la collection. Sur l’adresse d’origine, connectez-vous puis utilisez **Importer mes cartes de ce navigateur** pour récupérer vos anciennes cartes. Vous pouvez aussi exporter le JSON depuis l’ancien site et l’importer sur Render. Les decks des joueurs connectés sont synchronisés dans PostgreSQL. Dans **Decks**, le bouton **Importer mes decks de ce navigateur** récupère les decks locaux de l’ancienne version.

Pour récupérer les nouvelles cartes et séries de TCGdex, lancez la commande ci-dessous. Ajoutez `fr` ou `en` en argument pour actualiser une seule langue. La mise à jour remplace l’index de la langue après réception des cartes, des séries et des types.

```powershell
php bin/console app:catalogue:actualiser
```

## Albums et decks

Les **Albums** affichent la progression par série et par langue. Ouvrez une série pour consulter toutes ses cartes, uniquement celles qui vous manquent ou seulement celles que vous possédez. Les doubles comptent une seule fois dans la progression.

Dans **Decks**, créez et renommez plusieurs listes de 60 cartes maximum à partir de votre collection. Le nombre d’exemplaires ajoutables dépend de la quantité possédée. Si une carte disparaît de la collection, le deck garde sa référence et signale les exemplaires manquants. Chaque deck s’exporte en fichier texte.

## Installer l’application

Depuis le site HTTPS, utilisez **Installer l’appli**. Chrome et Edge peuvent afficher une confirmation native. Sur iPhone ou iPad, utilisez **Partager → Sur l’écran d’accueil** dans Safari. Le mode installé s’ouvre dans sa propre fenêtre et utilise le même compte que le site. Sur les systèmes compatibles, l’icône de l’application propose aussi des raccourcis vers **Ma collection** et **Jeux Nintendo DS**.

Les nouvelles adresses s’inscrivent directement depuis **Créer un compte**. Pour récupérer une adresse déjà utilisée, activez **Mot de passe oublié ?** en ajoutant dans l’environnement Render :

- `RESEND_API_KEY` avec une clé d’envoi Resend.
- `PASSWORD_RESET_FROM` avec une adresse de votre domaine d’envoi vérifié dans Resend.

Le démarrage de l’image Docker initialise la table sur la base Neon configurée. Hors Docker, lancez `php bin/console app:neon:setup --schema-only`. L’API HTTPS Resend envoie un code utilisable une seule fois pendant 15 minutes. La récupération conserve le compte et ses données. Si l’envoi mail n’est pas configuré, un administrateur peut renouveler le mot de passe d’un compte existant avec `php bin/console app:roms:account --rotate adresse@example.com`.

## Fonctions du compte

L’application s’ouvre sur la connexion et l’inscription. Une connexion est nécessaire pour consulter les rubriques, ouvrir un jeu ou accéder à un lien de classeur.

- Dans **Albums**, passez sur les séries proches d’être complétées et ajoutez des cartes manquantes à vos recherches. La page **Objectifs** rassemble ces cartes et suit les défis des dix cartes Eau distinctes et des 50 % d’une série choisie.
- Dans **Decks**, sélectionnez deux decks et une carte clé dans chacun. La comparaison affiche une main de sept cartes par deck, la fréquence sur 1 000 tirages et la probabilité théorique, sans règles de combat ni mulligan.
- Dans **Classeur**, sélectionnez jusqu’à neuf cartes puis activez le partage. Le lien affiche uniquement les cartes choisies aux joueurs connectés, sans les quantités ni les autres données du compte.

## Pokédex 3D et boosters

Les onglets **Méga-Évolutions** et **Gigamax** regroupent les formes correspondantes. Sur une fiche, faites glisser le modèle pour tourner et pincez ou utilisez la molette pour zoomer. Sélectionnez **Chromatique** ou **Animation** quand le modèle le permet. Les favoris du Pokédex sont enregistrés dans le navigateur. Les modèles chargent à la demande depuis le [catalogue Pokémon 3D API](https://github.com/Pokemon-3D-api/assets). Les fiches affichent une illustration ou un sprite quand aucun modèle compatible ne se charge.

Le **Laboratoire 3D** propose le catalogue [PokéAPI](https://github.com/PokeAPI/pokeapi/tree/master/data/v2/csv), filtrable par nom, catégorie et type, les [33 capacités Gigamax](https://www.pokepedia.fr/Gigamax) et des scènes de transformation. Toutes les entrées sont jouables dans une arène avec deux modèles 3D au choix. La mise en scène calcule les durées, la cible, l’approche des attaques de contact, les coups multiples et les réactions. La caméra dynamique et les sons synthétisés sont réglables. Les séquences utilisent une chorégraphie procédurale et des effets WebGL originaux communs à plusieurs capacités. Les modèles locaux de l’arène sont chargés à la demande. Le modèle Méga-Rayquaza est crédité à LunaEagle ([CC BY 4.0](https://creativecommons.org/licenses/by/4.0/)).

Les cartes d’un booster ouvert sont ajoutées à **Ma collection**. Dans l’album du booster, sélectionnez une carte face cachée, révélez la suivante ou révélez-les toutes. Une carte révélée ouvre sa fiche détaillée.

## ROMs et sauvegardes personnelles

La page **Mes ROMs** affiche un onglet par jeu après connexion. Les sauvegardes en ligne nécessitent une connexion. Chaque onglet donne accès au lecteur, au téléchargement et aux sauvegardes du jeu. La page ne permet plus l’ajout de jeux. Les fichiers existants restent disponibles dans le dossier privé `roms/` ou dans le bucket privé configuré.

Les jeux Nintendo DS peuvent être fournis en `.nds` ou dans un ZIP classique contenant un seul `.nds` non chiffré, de 512 Mio maximum une fois décompressé. Le lecteur télécharge la ROM puis la met en cache lorsque le navigateur le permet. La déconnexion de la bibliothèque efface ce cache. Les moteurs sont chargés via [EmulatorJS](https://emulatorjs.org/docs/systems/). Les fichiers `.3ds` et `.nsp` restent téléchargeables.

Cliquez dans l’écran du jeu si le clavier commande la page. Dans l’émulateur DS, modifiez vos options d’affichage ou vos commandes de clavier et de manette puis utilisez **Synchroniser les réglages**. L’application les charge à la prochaine ouverture du même jeu et du même moteur avec le même compte.

Dans chaque onglet DS, le **Carnet d’aventure** permet de noter badges, équipe, objectif et notes. Lorsque le jeu a démarré, **Sauvegarder en ligne** remplace le dernier point de reprise de ce jeu pour votre compte. Retrouvez-le via **Reprendre ma partie** sur un autre appareil, avec le moteur enregistré. Les points de reprise sont limités à 16 Mio. Dans **Sauvegardes**, choisissez **Supprimer** pour effacer un fichier ou un point de reprise de votre compte. Pour utiliser une sauvegarde externe, importez un fichier puis utilisez **Charger dans le jeu**. Avec une sauvegarde Nintendo DS (.sav, .dsv, .srm ou .save), choisissez ensuite **Continuer** dans le jeu. Un fichier .state doit provenir du même jeu et du même moteur DeSmuME. Les autres formats stockés restent disponibles au téléchargement. Le nom interne du jeu est désormais séparé par moteur et par compte. Pour conserver une ancienne sauvegarde restée uniquement dans le lecteur du navigateur, exportez-la depuis l’ancienne version avant de la charger dans le lecteur mis à jour.

### Stocker les ROMs dans PostgreSQL (Neon)

Récupérez l’URL PostgreSQL dans **Connect** sur Neon. Activez l’extension PHP `pdo_pgsql`. À la racine du projet, créez `.env.local` avec vos propres valeurs.

```dotenv
DATABASE_URL="postgresql://..."
ROM_POSTGRES=1
ROM_USER_ACCOUNTS=1
```

En production, renseignez ces variables dans les paramètres d’environnement de l’hébergeur. Avec `ROM_POSTGRES=1`, les ROMs de PostgreSQL et celles du dossier privé `roms/` apparaissent ensemble dans **Mes ROMs**. Un jeu importé en base sous le même nom remplace son entrée locale à l’affichage. Le bucket S3 concerne l’autre mode, décrit plus bas.

Depuis PowerShell, dans le dossier du projet :

```powershell
composer install
php bin/console cache:clear
php bin/console app:neon:setup
php bin/console app:roms:account ton-adresse@example.com
$romFiles = @(Get-ChildItem "C:\Mes-Roms" -File | Where-Object { $_.Extension -in '.zip', '.nds' } | ForEach-Object { $_.FullName })
if ($romFiles.Count) { php bin/console app:roms:import @romFiles }
.\LANCER.bat
```

Adaptez `C:\Mes-Roms` au dossier de vos six jeux. Mettez un seul fichier `.nds` par ZIP. L’import est réservé à la console. Il accepte jusqu’à 512 Mio par fichier et dépend du quota total de la base. Vous pouvez relancer un import déjà réussi sans créer de doublon. Les entrées importées en base sont conservées avec leur nom de fichier.

### Connecter le projet à Neon (option avec bucket S3)

Au besoin, installez PHP 8.4.1 ou plus en 64 bits, activez `pdo_pgsql` dans le PHP du terminal et dans le PHP de l’hébergement, puis exécutez les commandes à la racine du projet.

```powershell
composer install
npm ci
npm install -g neon@latest
neon login
neon config plan
neon deploy
```

Terminez la connexion dans le navigateur sur la même machine. Le projet est déjà lié à sa branche `production` via `.neon` et le bucket privé `roms-ds` est déclaré dans `neon.ts`. Si vous avez perdu le lien local, recréez-le avec `neon link` et sélectionnez cette branche. Vérifiez le plan avant de déployer, surtout si le bucket existe déjà. Si l’installation globale ne fonctionne pas, utilisez `npx neon@latest` à la place de `neon`.

Pour un essai local, conservez les variables produites par Neon dans `.env.local` et complétez-les avec celles de l’application. Si la CLI les a écrites dans `.env`, ce fichier est également chargé. Sur l’hébergement, utilisez les variables d’environnement du service. Gardez les URL et les clés hors de Git, des captures et du contexte Docker.

| Variable | Valeur |
|---|---|
| `DATABASE_URL` | Chaîne PostgreSQL copiée depuis **Connect** sur la branche `production`, ou produite par Neon. Conservez les paramètres SSL. |
| `ROM_S3_BUCKET` | `roms-ds` |
| `AWS_ENDPOINT_URL_S3`, `AWS_REGION` | Endpoint S3 Neon de la branche et région renvoyée par Neon. |
| `AWS_ACCESS_KEY_ID`, `AWS_SECRET_ACCESS_KEY` | Clés de stockage Neon pour la branche `production`. |
| `ROM_USER_ACCOUNTS` | `1` pour permettre l’inscription depuis **Créer un compte** à l’accueil et enregistrer les collections et sauvegardes par compte. |
| `APP_SECRET` | Valeur secrète stable et propre à l’application, à définir sur l’hébergement. |

Les variables historiques `ROM_S3_ENDPOINT`, `ROM_S3_REGION`, `ROM_S3_ACCESS_KEY` et `ROM_S3_SECRET_KEY` fonctionnent encore si elles sont déjà configurées et sont prioritaires sur leurs équivalents `AWS_*`.

Dans le bucket, déposez les ROMs que vous possédez directement à la racine, par exemple `Jeu DS.zip`. Les objets déjà présents sous `pokemon/roms/<id>/<nom>` sont également reconnus. Pour les gros fichiers, utilisez un client S3 avec envoi multipartie. Gardez le bucket privé. Pour les requêtes directes du navigateur, autorisez son origine avec la commande suivante et les clés S3 ayant les droits de configurer CORS. Pour le test avec `LANCER.bat`, vous pouvez utiliser `http://localhost:3000`.

```powershell
npm run build
php bin/console app:neon:setup --origin=https://pokemon.example.com
php bin/console app:roms:account joueur@example.com
php bin/console app:roms:account deuxieme.joueur@example.com
```

Remplacez l’origine par l’adresse exacte de votre site, sans barre oblique finale. La commande prépare les tables et vérifie l’accès au bucket, puis crée ou met à jour la règle CORS de cette origine. Les deux dernières commandes génèrent un mot de passe pour chaque compte. Utilisez `--rotate` pour renouveler un mot de passe existant. En production, servez uniquement le dossier `public/` et configurez HTTPS. Pour les sauvegardes vers PostgreSQL, le plafond de l’application est de 16 Mio par fichier. Les limites PHP et celles de l’hébergeur peuvent être inférieures.

Chaque ouverture enregistre les cinq cartes obtenues dans `booster_openings` lorsque `DATABASE_URL` est configurée. Les limites quotidiennes sont calculées par compte dans PostgreSQL pour les joueurs connectés et dans IndexedDB pour les visiteurs. Les sauvegardes PostgreSQL sont séparées par compte en mode individuel. Les sauvegardes existantes sur disque ou dans un ancien bucket peuvent être récupérées depuis leur stockage d’origine et réimportées sous le bon compte.

### Image Docker

Le `Dockerfile` installe les dépendances PHP à partir de `composer.lock` pendant la construction. `vendor/` reste ignoré par Git. Il active `pdo_pgsql`, vide le cache Symfony pendant la construction et expose uniquement `public/`. Fournissez `DATABASE_URL`, `ROM_POSTGRES=1`, `ROM_USER_ACCOUNTS=1` et un `APP_SECRET` stable au démarrage du conteneur. À chaque démarrage, le conteneur applique les ajouts de schéma PostgreSQL avant de lancer Apache. La commande est idempotente et conserve les données. Le rôle de `DATABASE_URL` doit avoir les droits de création et de modification des tables. Les sessions sont aussi stockées dans PostgreSQL afin de survivre au redémarrage de Render. Après passage des anciennes sessions locales à PostgreSQL, reconnectez-vous une fois. Pendant une partie, le lecteur permet de se reconnecter dans un autre onglet ou de télécharger le point de reprise si l’envoi échoue. Le message « PostgreSQL accessible » confirme la mise à jour dans les logs de démarrage. Pour appliquer le schéma depuis une installation locale connectée à la même base, lancez `php bin/console app:neon:setup --schema-only`. Les joueurs peuvent ensuite s’inscrire depuis **Créer un compte** à l’accueil. Les comptes existants gardent leurs identifiants. L’image écoute sur le port 80.

## Vérification du catalogue

```powershell
php tests/catalogue.php
```

Ce script vérifie le parcours de toutes les pages en français et en anglais, les recherches, les albums, le contenu des boosters et les liens de cris du Pokédex. Les index de départ permettent de l’exécuter sans dépendre d’un appel externe.
