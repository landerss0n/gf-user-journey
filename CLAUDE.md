# GF User Journey — Claude Instructions

## Projekt

WordPress-plugin för Gravity Forms. Spårar besökares sidnavigering i `localStorage` och bifogar resan i GF-notifikationsmail samt visar den i entry detail (admin).

## Struktur

```
gf-user-journey/
├── gf-user-journey.php          # Huvudfil: singleton-klass, all PHP-logik
├── assets/js/
│   ├── user-journey.js          # Källfil (redigera denna)
│   └── user-journey.min.js      # Genererad (redigera ALDRIG)
├── assets/css/
│   └── entry-detail.css         # Admin meta box styling
├── package.json                 # npm scripts: lint, build, dev
├── composer.json                # PHPCS via Composer
├── eslint.config.mjs            # ESLint v9 flat config
├── .phpcs.xml.dist              # PHPCS konfiguration
└── .gitignore
```

## Regler

- **Author**: Digiwise, Author URI: https://digiwise.se/
- Redigera ALDRIG `user-journey.min.js` direkt — kör `npm run build`
- Kör `npm run lint` innan commit
- PHP följer WordPress Coding Standards (tabs, Yoda conditions, etc.)
- JS använder WordPress-stil spacing (`( param )`, `[ array ]`)
- Alla nya JS-globaler måste deklareras i `eslint.config.mjs` under `globals`

## Viktiga konstanter

| Konstant | Värde | Beskrivning |
|---|---|---|
| `STORAGE_NAME` | `_gf_uj` | localStorage-nyckel |
| `CLEANUP_COOKIE_NAME` | `_gf_uj_cleanup` | Cookie för att signalera rensning |
| `META_KEY_JOURNEY` | `_gf_uj_journey` | Entry meta nyckel för journey |
| `META_KEY_UTM` | `_gf_uj_utm` | Entry meta nyckel för UTM |
| `MAX_DATA_ITEMS` | 100 | Max antal poster per besökare |
| `MAX_DATA_SIZE` | 10240 | Max storlek i bytes (10 KB) |

## Datalagring

Journey sparas som entry meta via `gform_update_meta()`. Inga custom tables — data rensas automatiskt när entries tas bort.

## Build

```bash
npm run dev    # lint + minifiera
npm run build  # bara minifiera
npm run phpcs  # kör PHPCS
```

## PHP: Script-laddning

Pluginet laddar `.min.js` i produktion och `.js` när `SCRIPT_DEBUG` är `true`.
