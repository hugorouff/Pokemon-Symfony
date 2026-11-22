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
- La collection utilise IndexedDB. Elle lit les cartes de l’ancienne application si vous reprenez **le même navigateur et la même adresse**, port inclus. Vous pouvez exporter la collection en JSON, puis l’importer depuis une autre adresse ou un autre navigateur. Les doublons, quantités et favoris sont enregistrés localement.

Pour récupérer les nouvelles cartes et séries de TCGdex, lancez la commande ci-dessous. Ajoutez `fr` ou `en` en argument pour actualiser une seule langue. La mise à jour remplace l’index de la langue après réception des cartes, des séries et des types.

```powershell
php bin/console app:catalogue:actualiser
```

## Albums et decks

Les **Albums** affichent la progression par série et par langue. Ouvrez une série pour consulter toutes ses cartes, uniquement celles qui vous manquent ou seulement celles que vous possédez. Les doubles comptent une seule fois dans la progression.

Dans **Decks**, créez et renommez plusieurs listes de 60 cartes maximum à partir de votre collection. Le nombre d’exemplaires ajoutables dépend de la quantité possédée. Si une carte disparaît de la collection, le deck garde sa référence et signale les exemplaires manquants. Chaque deck s’exporte en fichier texte.

## Bibliothèque privée de ROMs

La page **Mes ROMs** affiche un onglet par jeu disponible. Chaque onglet donne accès au lecteur, au téléchargement et aux sauvegardes du jeu. La page ne permet plus l’ajout de jeux. Les fichiers existants restent disponibles dans le dossier privé `roms/` ou dans le bucket privé configuré.

Les jeux Nintendo DS peuvent être fournis en `.nds` ou dans un ZIP classique contenant un seul `.nds` non chiffré, de 512 Mio maximum une fois décompressé. Le lecteur télécharge la ROM puis la met en cache lorsque le navigateur le permet. La déconnexion de la bibliothèque efface ce cache. Les moteurs sont chargés via [EmulatorJS](https://emulatorjs.org/docs/systems/). Les fichiers `.3ds` et `.nsp` restent téléchargeables.

Pour transférer votre partie, exportez la sauvegarde depuis le menu de l’émulateur, puis importez le fichier dans **Sauvegardes**. Sur un autre appareil, téléchargez le fichier depuis le même onglet et importez-le dans l’émulateur. Ce transfert est manuel. Deux imports du même fichier conservent deux versions.

### Pokémon Infinite Fusion dans le navigateur

L’onglet **Pokémon Infinite Fusion** donne accès en un clic à une session de jeu distante. Le jeu tourne avec Wine sur un serveur Docker Linux **x86-64** et Selkies transmet l’image, le son et les commandes au navigateur. Les parties restent dans le volume Docker `fusion-state`. La session est partagée par les personnes autorisées à y accéder.

1. Sur le serveur Docker, extrayez une version Windows complète du jeu disponible via le [Discord du projet](https://discord.gg/infinitefusion) dans le dossier `fusion/game/`. Le fichier `Game.exe` doit être directement dans ce dossier avec les fichiers du jeu.
2. Copiez `fusion/server.env.example` vers `.env.fusion` à la racine du projet. Choisissez un mot de passe unique puis lancez :

   ```sh
   docker compose --env-file .env.fusion -f compose.fusion.yaml up -d --build
   ```

3. Le service écoute sur `127.0.0.1:3081` en HTTPS. Pour jouer à distance, utilisez un **accès privé**. Vous pouvez passer par un tunnel SSH vers cette adresse pour un usage personnel, ou utiliser un reverse proxy HTTPS protégé par une authentification. Le proxy doit prendre en charge les WebSockets et pointer vers `https://127.0.0.1:3081` avec gestion du certificat local de Selkies. Ne publiez pas directement le port du conteneur sur Internet.
4. Définissez `INFINITE_FUSION_STREAM_URL=https://fusion.votre-domaine.fr` dans l’environnement de Symfony ou dans `.env.local`. Utilisez l’URL réellement accessible depuis votre navigateur. L’onglet proposera alors **Jouer en ligne**. Le service possède sa propre authentification et ne dépend pas de Neon.

Pour les ROMs DS, utilisez les étapes PostgreSQL ci-dessous. Il suffit ensuite d’ouvrir **Mes ROMs** depuis le site hébergé.

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

Adaptez `C:\Mes-Roms` au dossier de vos six jeux. Mettez un seul fichier `.nds` par ZIP. L’import est réservé à la console. Il accepte jusqu’à 512 Mio par fichier et dépend du quota total de la base. Vous pouvez relancer un import déjà réussi sans créer de doublon. Les entrées importées en base sont conservées avec leur nom de fichier. Infinite Fusion reste un jeu Windows local et ne passe pas par cette commande.

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
| `ROM_USER_ACCOUNTS` | `1` pour activer la connexion par compte individuel. Sans cette option, le mot de passe partagé de la bibliothèque reste disponible. |
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

Chaque ouverture enregistre les cinq cartes obtenues dans `booster_openings` lorsque `DATABASE_URL` est configurée. La collection du navigateur et ses limites quotidiennes continuent à fonctionner avec IndexedDB. Les sauvegardes PostgreSQL sont séparées par compte en mode individuel. Les sauvegardes existantes sur disque ou dans un ancien bucket peuvent être récupérées depuis leur stockage d’origine et réimportées sous le bon compte.

### Image Docker

Le `Dockerfile` utilise le dossier `vendor/` fourni. Il active `pdo_pgsql`, vide le cache Symfony pendant la construction et expose uniquement `public/`. Fournissez `DATABASE_URL`, `ROM_POSTGRES=1`, `ROM_USER_ACCOUNTS=1` et un `APP_SECRET` stable au démarrage du conteneur. Exécutez les commandes de création des tables et des comptes avec ces mêmes variables. L’image écoute sur le port 80.

## Vérification du catalogue

```powershell
php tests/catalogue.php
```

Ce script vérifie le parcours de toutes les pages en français et en anglais, les recherches, les albums, le contenu des boosters et les liens de cris du Pokédex. Les index de départ permettent de l’exécuter sans dépendre d’un appel externe.
"# Pokemon-Symfony" 
