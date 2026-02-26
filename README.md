# GF User Journey

WordPress-plugin som spårar besökares navigering och bifogar hela användarresan i Gravity Forms notifikationsmail samt visar den i entry detail.

## Hur det fungerar

1. **Spårning** — Varje sidvisit lagras i `localStorage` (inga cookies, inga externa anrop)
2. **Formulär** — Vid Gravity Forms-inlämning injiceras resan som dolda fält
3. **Lagring** — Servern parsar och sparar journey som entry meta
4. **E-post** — Journey bifogas automatiskt i notifikationsmail
5. **Admin** — En meta box på entry detail-sidan visar hela resan

## Funktioner

- Spårar sidtitel, URL och tid på varje sida
- Fångar externa referrers vid första besöket
- Första-touch UTM-attribution (source, medium, campaign, term, content)
- Deduplicerar vid sidomladdning
- Stöder dynamiskt laddade formulär (popups, AJAX)
- Automatisk rensning av localStorage efter inskickat formulär
- Server-side validering och storleksbegränsning (max 100 poster, 10 KB)
- HTML-tabell i HTML-mail, ren text i textmail
- Meta box i GF entry detail med fullständig journey
- Data sparas som entry meta — rensas automatiskt med entries

## Krav

- WordPress 6.3+
- PHP 7.4+
- [Gravity Forms](https://www.gravityforms.com/)

## Installation

1. Ladda upp mappen `gf-user-journey/` till `wp-content/plugins/`
2. Aktivera pluginet i WordPress-admin

Pluginet kräver ingen konfiguration — det börjar spåra direkt.

## Utveckling

Kräver Node.js och Composer.

```bash
cd gf-user-journey
npm install
composer install
```

### Kommandon

| Kommando | Beskrivning |
|---|---|
| `npm run lint` | Kör ESLint |
| `npm run lint:fix` | Kör ESLint med autofix |
| `npm run build` | Minifierar JS med Terser |
| `npm run dev` | Lint + build |
| `npm run phpcs` | Kör PHP CodeSniffer |
| `npm run phpcbf` | Kör PHPCBF autofix |
| `npm run package` | Lint + build + skapa zip |

### Produktion vs debug

Pluginet laddar `user-journey.min.js` som standard. Sätt `SCRIPT_DEBUG` till `true` i `wp-config.php` för att ladda den ominifierade versionen:

```php
define( 'SCRIPT_DEBUG', true );
```

## Begränsningar

- Spårar inte om `localStorage` är inaktiverat
- Max 100 poster och 10 KB per besökare
- Data äldre än 1 år rensas automatiskt

## Licens

GPL v2 or later
