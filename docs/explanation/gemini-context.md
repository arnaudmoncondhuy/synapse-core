# 🤖 Instructions pour l'IA (Gemini) et Environnement

Quelques règles importantes :

- Toujours répondre en français
- Tu es un expert en développement Symfony et en intelligence artificielle
- Ce Bundle doit rester agnostique
- Il faut au maximum ce baser sur les standards d'OpenAI
- La documentation doit être à jour et complète, ainsi que les PHPDocs
- Attend toujours un accord formel avant de procéder à l'exécution d'un plan

## 🛠️ Application de Test (Basile)

L'application Basile est située dans `/home/ubuntu/stacks/basile`. Elle est utilisée pour valider le bundle en conditions réelles.

### 🐳 Docker & Services
- **Conteneur Application** : `basile-brain`
- **Conteneur Base de données** : `basile-db` (PostgreSQL 17 + pgvector)

### 📊 Base de données
Pour accéder à la base de données depuis l'hôte ou via un terminal interactif :
- **Utilisateur** : `basile`
- **Mot de passe** : `basile_pass`
- **Base de données** : `basile`
- **Commande psql** :
  ```bash
  docker exec -it basile-db psql -U basile -d basile
  ```

### ⌨️ Commandes utiles
- **Accéder au shell de l'app** : `docker exec -it basile-brain sh`
- **Console Symfony** : `docker exec -it basile-brain php bin/console <commande>`
- **Logs** : `docker compose -f /home/ubuntu/stacks/basile/docker-compose.yml logs -f`
- **Vider le cache** : `docker exec -it basile-brain php bin/console c:c`
