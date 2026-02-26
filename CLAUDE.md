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
├── languages/
│   ├── gf-user-journey-sv_SE.po # Svenska översättningar (redigera denna)
│   └── gf-user-journey-sv_SE.mo # Kompilerad (generera via msgfmt)
├── includes/
│   └── plugin-update-checker/   # GitHub auto-update bibliotek (redigera ALDRIG)
├── .github/workflows/
│   └── release.yml              # GitHub Actions: bygger zip + release vid tag
├── package.json                 # npm scripts: lint, build, dev
├── composer.json                # PHPCS via Composer
├── eslint.config.mjs            # ESLint v9 flat config
├── .phpcs.xml.dist              # PHPCS konfiguration (exkluderar includes/)
├── build-zip.sh                 # Bygger distributions-zip
└── .gitignore
```

## Regler

- **Author**: Digiwise, Author URI: https://digiwise.se/
- Redigera ALDRIG `user-journey.min.js` direkt — kör `npm run build`
- Redigera ALDRIG filer i `includes/plugin-update-checker/`
- Kör `npm run lint` innan commit
- PHP följer WordPress Coding Standards (tabs, Yoda conditions, etc.)
- JS använder WordPress-stil spacing (`( param )`, `[ array ]`)
- Alla nya JS-globaler måste deklareras i `eslint.config.mjs` under `globals`
- Alla användarvänliga strängar ska vara översättningsbara via `__()` / `esc_html__()`
- Textdomän: `gf-user-journey`

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

## Notifikationsinställningar

Två toggles per GF-notifikation:
- **Include User Journey** (`gf_uj_enable`) — bifogar kundresan i mailet
- **Send only to BCC** (`gf_uj_bcc_only`) — skickar separat mail med journey till BCC-adresserna

## UTM & Google Ads-detection

- Fångar UTM-parametrar (first-touch) i localStorage
- Auto-detekterar Google Ads-klick via `gad_source=1` → sätter `source=google`, `medium=cpc`
- UTM-labels hämtas via `get_utm_labels()` (centraliserad, översättningsbar)
- UTM-data valideras mot allowlist på både capture- och render-path

## Cleanup-flöde

1. Formulär skickas → PHP sätter `_gf_uj_cleanup` cookie (`httponly: false`)
2. Nästa sidladdning → JS läser cookien, rensar localStorage, raderar cookien
3. **Viktigt**: efter cleanup avbryts tracking helt (`app.cleaned = true`) så tack-sidan inte registreras

## Build & Release

```bash
npm run dev      # lint + minifiera
npm run build    # bara minifiera
npm run phpcs    # kör PHPCS
npm run package  # lint + build + skapa zip
```

### Release-process
1. Bumpa version i `gf-user-journey.php` (header + VERSION-konstant) och `package.json`
2. Committa och pusha
3. `git tag v1.0.X && git push origin v1.0.X`
4. GitHub Actions bygger zip och skapar release automatiskt
5. WP-siter ser uppdateringen i admin (eller klicka "Sök igen" under Uppdateringar)

## PHP: Script-laddning

Pluginet laddar `.min.js` i produktion och `.js` när `SCRIPT_DEBUG` är `true`.

## Beroenden

- **Requires Plugins**: `gravityforms` (WP 6.5+ plugin dependency header)
- Repo är **publikt** — ingen GitHub-token behövs för auto-update
