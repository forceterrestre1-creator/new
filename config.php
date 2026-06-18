# 718Digital - Protection des fichiers sensibles
# Bloque l'accès direct par navigateur au .env et au dossier storage

<FilesMatch "^\.env">
    Order allow,deny
    Deny from all
</FilesMatch>

<IfModule mod_rewrite.c>
    RewriteEngine On
    RewriteRule ^storage/ - [F,L]
</IfModule>

Options -Indexes
