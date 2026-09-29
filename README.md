# Dateien senden · webkapp

Besucher können hier Dateien übertragen, die für E-Mail zu groß sind. Jede Datei wird in Stücken gesendet, der Fortschritt ist dabei sichtbar. Fertige Dateien liegen unter `storage/app/uploads`, jeweils mit einer JSON-Datei zur Referenz, zum Absender und zur Nachricht.

## Start

```powershell
composer install
npm install
php artisan migrate
php artisan dev
```

Die Seite läuft danach auf http://localhost:8000. `php artisan dev` startet den Server mit angehobenen PHP-Limits (64 MB pro Stück) und Vite.

## Zugangscodes

Das Upload-Formular wird erst nach Eingabe eines gültigen Codes freigeschaltet. Codes werden im Terminal verwaltet:

```powershell
php artisan upload:code "Kunde Müller"
php artisan upload:code "Projektordner" --expires=2026-12-31
php artisan upload:codes
php artisan upload:revoke K4NP-7QWM
```

Ein Code gilt, bis er widerrufen wird oder abläuft.

## Grenzen

In der `.env`:

- `UPLOAD_MAX_BYTES` – maximale Größe einer Datei, Standard 5 GB
- `UPLOAD_CHUNK_BYTES` – gewünschte Stückgröße, Standard 8 MB

Liegt das PHP-Limit darunter, werden die Stücke automatisch verkleinert. Für Apache oder nginx+php-fpm gilt zusätzlich `public/.user.ini`. Nginx braucht außerdem ein ausreichend großes `client_max_body_size`.

Ausführbare Dateien wie `.exe` oder `.php` werden abgelehnt. Der Upload-Ordner ist nicht öffentlich erreichbar.
