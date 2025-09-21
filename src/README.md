ShinedeBox — Upload et partage de fichiers

Description
- Mini application de dépôt/partage de fichiers (zip, jar, png, jpg, pdf, etc.).
- Client en HTML/CSS/JS natif. API en PHP. Aucune base de données.
- Les fichiers sont servis directement par le serveur web depuis `public/uploads/`.

Arborescence
- `public/` — Webroot (index, styles, JS, `uploads/`).
- `api/` — Endpoints PHP (hors webroot): `config.php`, `auth.php`, `upload.php`, `list.php`, `delete.php`.

Prérequis
- PHP 8.1+ avec extensions `json`, `fileinfo` activées.
- Serveur web (Nginx recommandé) et PHP-FPM.

Configuration
- Copiez `.env.example` en `.env` et ajustez:
  - `BASE_URL` — URL publique, ex: `https://box.shinederu.lol`.
  - `UPLOAD_DIR` — chemin du dossier des uploads (absolu). Par défaut: `/var/www/shinedebox/public/uploads`.
  - `MAX_FILE_MB` — taille max d’un fichier en Mo (ex: 2048).
  - `AUTH_PASSWORD` — mot de passe d’admin pour la session (obligatoire en prod).
  - `ALLOWED_EXT` — extensions autorisées (avec point).
  - `ALLOWED_MIME` — types MIME autorisés.

Lancement local (démo)
- Servez `public/` comme racine web et assurez-vous que `api/` n’est pas sous le webroot.
- Pour un test rapide avec le serveur PHP intégré (non recommandé en prod):
  - `php -S 127.0.0.1:8080 -t public` (sert le front)
  - Mappez `/api` vers `api/` via un proxy/fichier routeur, ou servez l’API séparément: `php -S 127.0.0.1:8081 -t api`
  - Dans `.env`, mettez `BASE_URL=http://127.0.0.1:8080` et ajustez `UPLOAD_DIR` si besoin (ex: `UPLOAD_DIR=public/uploads`).

Sécurité
- Aucun PHP ne doit être interprété sous `public/uploads/`.
- Vérification stricte de l’extension ET du MIME.
- Rejet des doubles extensions dangereuses (`.zip.php`), des chemins relatifs et des noms non ASCII.
- Sessions pour upload/list/delete (cookies HttpOnly, SameSite=Lax; `Secure` si HTTPS).
- Limitation de débit basique côté PHP (10 req/min pour auth/upload/delete, 30 req/min pour list).

Nginx (exemple)
server {
  server_name box.shinederu.lol;
  listen 443 ssl http2;

  client_max_body_size 2048m;
  root /var/www/shinedebox/public;
  index index.html;

  location ^~ /api/ {
    alias /var/www/shinedebox/api/;
    location ~ \.php$ {
      include fastcgi_params;
      fastcgi_param SCRIPT_FILENAME $request_filename;
      fastcgi_pass unix:/run/php/php8.2-fpm.sock;
    }
    location /api/ { return 404; }
  }

  location ^~ /uploads/ {
    autoindex off;
    default_type application/octet-stream;
    add_header X-Content-Type-Options nosniff;
    add_header Content-Disposition "attachment";
    location ~ \.php$ { return 403; }
    try_files $uri =404;
  }

  location / { try_files $uri /index.html; }
}

HAProxy
- Assurez une taille de corps au moins égale à Nginx/PHP pour les gros fichiers (pas de deny sur body_size).

Limites & timeouts
- PHP (`php.ini`):
  - `upload_max_filesize = 2048M`
  - `post_max_size = 2048M`
  - `file_uploads = On`
  - `max_file_uploads` suffisamment grand
- Nginx/PHP-FPM: ajustez les timeouts pour gros fichiers.

API (JSON)
- `GET /api/auth.php?action=status` → `{ success, authenticated }`
- `POST /api/auth.php` (form-urlencoded: `action=login&password=...`) → `{ success }`
- `GET /api/auth.php?action=logout` → `{ success }`
- `POST /api/upload.php` (multipart: `files[]`) → `{ success, results: [ { name, stored?, url?, size?, mime?, success, error? } ] }`
- `GET /api/list.php` → `{ success, files: [ { id, name, size, mtime, url } ] }`
- `POST /api/delete.php` (x-www-form-urlencoded: `id=<stored_name>`) → `{ success }`
 - `POST /api/rename.php` (x-www-form-urlencoded: `id=<stored_name>&name=<new_base_name>`) → `{ success, renamed: { old, new, url } }`

Exemples d’erreurs JSON
- `{ "success": false, "error": "Non authentifié" }`
- `{ "success": false, "error": "Extension non autorisée" }`
- `{ "success": false, "error": "Trop de requêtes, réessayez plus tard" }`

Renommage
- Le renommage ne change pas l'extension du fichier, uniquement le nom de base.
- Si un fichier du même nom existe déjà, l’API renvoie une erreur 409.
- La suppression accepte désormais les fichiers renommés (validation stricte du nom, extension autorisée).

Remarques
- Le front utilise XHR pour la barre de progression.
- Le nom stocké suit `YYYYMMDD-HHMMSS-random8hex.ext` et les liens directs sont `BASE_URL/uploads/<filename>`.
