# Votepit

Selbst-gehostetes **Feature-Voting-Board** mit echten **Up- _und_ Down-Votes** — für klassisches PHP/MySQL-Shared-Hosting, ohne Docker, ohne fremde Runtime. Eine Installation bedient mehrere Boards (ein Board pro Projekt). Anmeldung passwortlos per Magic-Link.

> Status: **Frühe Initialisierung.** Die Implementierung beginnt mit dem Security-Foundation-Sprint (Skeleton, Routing, Config, Schema, Schutzschichten) — Security by Design ist Fundament, nicht Endstufe.

## Warum

Kommerzielle Tools (Canny, Featurebase, Nolt …) haben meist **keine Down-Votes** oder deckeln das Free-Tier; die gepflegten Self-hosted-OSS-Lösungen sind Docker-basiert und nicht Shared-Hosting-tauglich. Votepit schließt die Lücke: läuft dort, wo ohnehin Webspace ist (z. B. shared hosting), per FTPES deploybar, kostenlos, MIT-lizenziert.

## Features (MVP-Ziel)

- Öffentliche Boards pro Projekt, Sortierung nach **Top** (Score) und **Neueste**, Filter nach Status
- **Up-/Down-Vote**, genau eine Stimme pro Idee pro Nutzer — serverseitig per `UNIQUE`-Constraint erzwungen
- Passwortlose **Magic-Link-Auth** (E-Mail), persistente Session, Rate-Limiting
- Ideen einreichen, bearbeiten, zurückziehen; Kommentare
- **Duplikat-Erkennung beim Tippen** (As-you-type): MySQL-FULLTEXT-Recall + Jaro-Winkler-Reranking, ohne LLM/externe Dienste
- Admin-Moderation: Status setzen, anpinnen, fremde Beiträge moderieren, Nutzer sperren, Boards anlegen
- **Multi-Board** aus einer Installation (`/{board-slug}/…`)

Bewusst **nicht** im MVP: Embed-Widget, OAuth, E-Mail-Benachrichtigungen, öffentliche API, Realtime. Siehe „Out of Scope" im PRD.

## Anforderungen

- PHP **8.2+** mit `pdo`, `mbstring`, `intl`
- MySQL **5.7+** / MariaDB **10.0+** (InnoDB-FULLTEXT für die Duplikat-Suche; PHP-Fallback ohne FULLTEXT vorhanden)
- SMTP-Zugang für den Magic-Link-Versand
- Kein Docker, kein SSH-Zwang, kein `composer install` auf dem Server nötig

## Technologie

Aufbau auf einem **schlanken Stack aus geprüften Komponenten** (Security by Design): [Slim 4](https://www.slimframework.com/) (PSR-15-Middleware-Pipeline), [Twig 3](https://twig.symfony.com/) (Templates mit Autoescape), ausgewählte [Symfony-Komponenten](https://symfony.com/components) (Validator, Mailer) und [Doctrine DBAL](https://www.doctrine-project.org/projects/doctrine-dbal.html) (Prepared-Statements-only). Bewusst kein Full-Stack-Framework — Shared-Hosting-tauglich, kleine Angriffsfläche, Security-Primitive aus aktiv gepflegten, auditierten Quellen.

## Installation

1. Repo klonen, Abhängigkeiten **lokal** bauen:
   ```bash
   composer install --no-dev --optimize-autoloader
   ```
   `vendor/` wird mit dem Build-Artefakt per FTPES hochgeladen (nicht im Repo eingecheckt).
2. Konfiguration anlegen:
   ```bash
   cp config/config.example.php config/config.php
   ```
   In `config/config.php` DB-Zugang, SMTP, `app_url`, `admin_emails` und einen `app_key` eintragen
   (`php -r "echo bin2hex(random_bytes(32));"`).
3. Datenbank-Schema einspielen (idempotentes Setup-Skript — folgt im Security-Foundation-Sprint, siehe `db/`).
4. Webroot auf `public/` zeigen lassen. Ist der Webroot nicht frei wählbar, schützt die mitgelieferte `.htaccess` die Pfade außerhalb von `public/` (Details folgen in der Deploy-Doku).

## Deployment (shared hosting / Shared-Hosting)

Build inkl. `vendor/` lokal erzeugen → den fertigen Ordner per **FTPES** in den Subdomain-Docroot hochladen. Kein Docker, kein Server-seitiges Composer.

## Konfiguration mehrerer Boards

Eine Installation bedient mehrere Boards path-basiert (`/example`, `/example`, `/example` …). Boards werden im Admin angelegt; Branding (Name, Slug, Akzentfarbe, Intro) ist pro Board konfigurierbar.

## Sicherheit

Votepit wird **Security by Design** gebaut: Schutzschichten (Security-Header, Middleware-Pipeline, deny-by-default-Autorisierung) sind Fundament, nicht Endstufe. Im Einzelnen:

- Ausschließlich Prepared Statements (Doctrine DBAL über PDO) → keine SQL-Injection
- **Twig mit Autoescape (Default)** → kontextgerechtes Output-Escaping, kein XSS
- Integrität (eine Stimme pro Idee/Nutzer, Status-Übergänge, Ownership) ausschließlich serverseitig
- CSRF-Token (`slim/csrf`) auf allen mutierenden Requests, serverseitiges Rate-Limiting
- Magic-Link-Tokens werden nur gehasht gespeichert, sind einmalig und befristet
- Webroot ist `public/`; Config, Code und Logs liegen außerhalb

Eine Sicherheitslücke gefunden? Siehe [`SECURITY.md`](SECURITY.md) (verantwortungsvolle Offenlegung / Coordinated Disclosure).

## Lizenz

[MIT](LICENSE) — © 2026 Silvio Lindstedt.
