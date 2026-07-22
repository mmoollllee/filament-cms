# Konzept: Mediathek — Integration von Filament Media Library Pro

**Stand:** 2026-07-22 · **Status:** Rev. 3 — **Kern umgesetzt** (P0–P3), an nest.kuckuck.cam ausgerichtet · **Paket:** `ralphjsmit/laravel-filament-media-library` **4.1.2**

## Umsetzungsstand (Rev. 3)

Umgesetzt und getestet (275 Tests grün), mit einer gegenüber Rev. 2 **geänderten
Grundsatzentscheidung**: Das Plugin ist jetzt eine **optionale Dependency**
(`require-dev` + `suggest` statt `require`) — filament-cms funktioniert mit und ohne;
die Integration aktiviert sich selbst, sobald der Client das Plugin installiert
(`class_exists`-Gate wie beim Consent-Control-Muster). Opt-out trotz Installation:
`Cms::disableMediaLibrary()`.

| Baustein | Status |
|---|---|
| Capability-Gate (`MediaLibrary::enabled()`), `Cms::`-Registry (`useMediaDriver/useMediaItemModel/useMediaDisk/useMediaFolderNames`) | ✅ |
| `CmsMediaLibraryDriver` (auto-`hasTenancy()`, Policy-Re-Bridge, Disk via Registry, Conversions inkl. `og` 1200×630) | ✅ |
| `MediaItemPolicy`/`MediaFolderPolicy` (Superadmin-`before()`, Root-Ordner-Regel) | ✅ |
| **`MediaField`** — dual-mode Factory (MediaPicker ⟷ FileUpload, gleiche Daten-Keys); alle Felder umgestellt (MediaBlock, Sektions-BG, Hero, Branding **+ neues Favicon-Feld**, `SeoFields.og_image` neu) | ✅ |
| `MediaUrlResolver` (+ `AssetUrlResolver`-Façade, Batch-`preload()`, `srcset('responsive')` mit Disk-URL-Guard), `<x-site.image>`, Views ID-fähig, `InheritsBranding` absolut-URL-treu | ✅ |
| `MediaPickerPreviewAction` 1:1-Port (+ PDF-View + lang de/en), global via `configureUsing` | ✅ |
| Default-Ordner **Branding / Seiten / Dokumente** (flach, kontextbasiert, lazy `firstOrCreate` pro Tenant, Namen per Registry) | ✅ |
| `cms:media:import` — **wert-basierter** Scan (siehe unten), idempotent, `--dry-run/--tenant/--all/--sync`, Draft-Rewrite, Originale bleiben | ✅ |
| Workbench/Testbench-Wiring (Provider, Migrationskopien), Doku (README, FEATURES §Media, CUSTOMIZATION §12) | ✅ |
| Offen: Video-Pipeline-Umzug an den Upload (P4), RichEditor-`MediaPlugin` (P5), Galerie-/Downloads-Block, Tags, Private-Disk-Modul (P6) | ⏳ |

**Erkenntnisse aus dem Consumer-Audit (pernes-hebesysteme.de, muench-tiefbau.de),
die das Design geändert haben:**

1. **Import ist wert-basiert, nicht key-basiert:** pernes referenziert ~250 Medien über
   WordPress-Ära-Strukturen (`payload.galerie` als Array, `payload.masszeichnung`,
   `hero.thumbnail`, Pfade `2020/01/…` ohne `tenants/`-Prefix) — ein `media_path`-Scan
   hätte dort **null** Treffer. Der Import prüft jeden JSON-String gegen die Disk
   (`Storage::exists`), Tenant-Zuordnung über die referenzierende Row, nicht den Pfad.
2. Beide Apps haben Vite-Themes (Plugin-CSS-Import sofort möglich), aber **keine
   satis-Credentials** (composer.json + auth.json + Server-Provisioning nötig, auth.json
   ist gitignored). pernes hat vermutlich **keinen Queue-Worker** → `--sync`-Flag.
3. Ein `MediaItemObserver` für Cache-Invalidierung ist **unnötig** (Rev.-2-Irrtum):
   die Seiten-Caches speichern Model-Payloads, kein HTML — Media-URLs werden bei jedem
   Render über den Resolver aufgelöst, ein Datei-Ersetzen greift sofort.
4. Inline-`<img>` in RichText (1 Vorkommen in pernes) und `meta.og_image_url` bleiben
   bewusst Legacy-URLs (Resolver rendert sie weiter; kein riskantes HTML-Rewriting).

Hinweis: Filament läuft inzwischen auf **5.7.1** — die vendored Builder-Views wurden
gegen die neue embedded-view-Architektur re-vendored (Reaktivierung des klassischen
View-Pfads via `Builder::configureUsing(->view(…))`, Drift-Guard hasht jetzt die
`toEmbeddedHtml()`/`generateBlockPickerHtml()`-Methodenquelle; Baseline v5.7.1).

Ziel ist die vollständige, tiefe Integration als **WordPress-ähnlicher Media-Picker** für alle
Bilder, Videos und Downloads — pro Tenant eine zentrale Mediathek statt verstreuter
`FileUpload`-Felder mit Pfad-Strings.

**Referenzarchitektur:** `~/Herd/nest.kuckuck.cam` betreibt dasselbe Plugin (4.1.2) bereits
produktiv (custom Driver, Policies, Referenz-Tracking, private Disk, Preview-Action). Dieses
Konzept übernimmt dessen bewährte Muster (§2.3) und hält die Tür offen, dass nest später
selbst filament-cms als Engine einsetzen kann — mit **einer** gemeinsamen Mediathek (§7).

---

## 1. Ziel & Leitbild

**Als Redakteur möchte ich jede Datei genau einmal hochladen und sie überall (Blöcke, Hero,
Branding, SEO, RichText) aus einer zentralen Mediathek auswählen, um Medien konsistent zu
verwalten und Alt-Texte/Ersetzungen an einer Stelle zu pflegen.**

Leitsätze:

1. **Eine Mediathek pro Tenant** — Ordner, Suche, Filter, Bulk-Aktionen, Bildeditor kommen vom Plugin; Scoping über die vorhandene native Filament-Tenancy.
2. **IDs statt Pfade** — Referenzen in `blocks`/`payload`/`meta`-JSON und Tenant-Spalten werden `MediaLibraryItem`-IDs. URLs werden immer zur Renderzeit aufgelöst (Ersetzen einer Datei wirkt sofort überall).
3. **Legacy-Pfade bleiben für immer gültig** — der zentrale Resolver behandelt `int` → Mediathek, `string` → bisheriger Pfad. Kein Big-Bang, kein kaputtes Bestands-Frontend.
4. **Zentrale Metadaten, lokale Overrides** — `alt_text`/`caption` leben am Item; Blöcke dürfen pro Verwendung überschreiben.
5. **Frontend wird besser, nicht nur anders** — mit dem Umbau kommen `srcset`/`sizes`, `loading="lazy"` und Video-Poster automatisch (heute: nacktes `<img src>`, kein Responsive-Markup im gesamten Paket).
6. **nest-Kompatibilität als Designregel** — jede Media-Entscheidung läuft über austauschbare Nahtstellen (Driver, Model, Disk, URL-Generator, Referenz-Contract), damit CMS-Paket und nest-Bestand später eine Mediathek teilen können.

---

## 2. Ausgangslage

### 2.1 Ist-Zustand im CMS

Alle Uploads sind rohe `FileUpload`-Felder auf der `public`-Disk, Wert = Pfad-String unter `tenants/{site_key}/…`:

| Feld | Ort | Speicherung |
|---|---|---|
| `media_path`, `poster_path` | [MediaBlock.php:40,65](../src/Support/Content/Blocks/media/MediaBlock.php) | Block-JSON, `tenants/{key}/content-blocks` |
| `background_image` (Sektion) | [BaseBuilderBlock.php:191](../src/Support/Content/Blocks/BaseBuilderBlock.php) | Block-JSON |
| `payload.hero.thumbnail\|image\|float_image` | `PageHeaderFields` | `payload`-JSON, `tenants/{key}/hero` |
| `logo_path`, `secondary_logo_path`, `mail_logo_path`, `default_og_image_path` | [EditTenantProfilePage.php](../src/Filament/Pages/Tenancy/EditTenantProfilePage.php) | Tenant-Spalten, `tenants/{key}/branding\|seo` |

Zentrale Nahtstellen: `AssetUrlResolver` (Pfad→URL, von allen Views + `Content::heroImageUrl()/thumbnailUrl()` + `InheritsBranding::resolved*Url()` genutzt), `<x-site.media-item>` (Video-Erkennung per Dateiendung), `seo-head.blade.php` (`meta.og_image_url` → Tenant-Fallback), ffmpeg-Pipeline `ConvertsUploadedVideos` → `ConvertVideoForWeb` (Content-`saved`-Hook, durchsucht Block-JSON).

Bekannte Lücken, die die Integration gleich mit schließt:

- Kein `srcset`/`lazy` irgendwo; OG-Bild pro Inhalt hat kein Formularfeld (nur `meta.og_image_url`-Daten + Tenant-Default).
- `favicon_path` existiert in Migration + `InheritsBranding`, ist aber **nicht** im Panel editierbar und fehlt im `$fillable`.
- RichEditor `attachFiles` lädt auf Filament-Defaults hoch — **nicht** tenant-gescoped, an der Mediathek vorbei.
- Videos in **Fragments** werden nie konvertiert (`ConvertsUploadedVideos` hängt nur am Content-Model); dieselbe Videodatei in zwei Inhalten wird doppelt konvertiert.
- Drafts (`HasDraft`) overlayen per `fill()` → Medienreferenzen **müssen** in `$fillable`-Spalten bzw. `blocks`/`payload`-JSON bleiben (Relationen wären draft-inkompatibel). Das ID-im-JSON-Modell erfüllt das.

### 2.2 Was das Plugin mitbringt (4.1.2)

- **Plugin:** `RalphJSmit\Filament\MediaLibrary\FilamentMediaLibrary` (extends `FilamentExplore`), Seite `MediaLibrary` (Slug `media-library`), komplette Browse-UI (Ordner, Suche, Filter, Sortierer, Bulk, File-Info-Panel, Bildeditor). JS wird automatisch via `FilamentAsset` registriert; **CSS muss in ein Filament-Custom-Theme importiert werden** (Tailwind-v4-`@source`).
- **Formular:** `MediaPicker` (Field) mit `multiple()`, `reorderable()`, `acceptedFileTypes()`, `min/maxFiles()`, `defaultFolder()`, `scopedFolder()`, `circular()`, `fileActions()`, `modifyPreviewActionUsing()`; dazu `MediaColumn` (Tables), `MediaEntry` (Infolists), RichEditor-`MediaPlugin`.
- **Datenmodell:** `MediaLibraryItem` (`filament_media_library`: `caption`, `alt_text`, Uploader-Morph, Tenant-Morph, `folder_id`) + `MediaLibraryFolder`; Dateien via **spatie/laravel-medialibrary**, Collection `library` (`singleFile`). Morph-Alias `filament_media_library_item`. Achtung: globaler `has_media`-Scope — Items ohne Spatie-Media-Row sind überall unsichtbar (Test-Fixture-Gotcha aus nest).
- **Driver-Architektur:** Standard `MediaLibraryItemDriver` (Picker-State = **Item-ID(s)** — genau richtig für Block-JSON). Driver als Klasse pro Plugin-Registrierung austauschbar (`->driver(FQN::class)`), Modell-Swap via `mediaLibraryItemModel()`, eigene File-Info-Felder via `fileInfoEditComponents(merge: true)`, eigene Aktionen via `pushFileActions()` etc.
- **Tenancy:** Driver-Trait `HasTenancy` — Tenant-Morph-Spalten auf Items **und** Ordnern, Stempeln beim Upload, Scoping jeder Query; Tenant-Quelle default `Filament::getTenant()` — passt exakt zu unserem `->tenant(Cms::tenantModel(), slugAttribute: 'primary_domain')` in `BasePanelProvider`.
- **Bildableitungen:** wahlweise Spatie-**Conversions** (`responsive`, `800`, `400`, `thumb` — konfigurierbar via `conversions(true)`, `conversionMedium(width:…)`, `registerConversions()`) **oder** **Glide** on-the-fly (Standard, wenn GD/Imagick da ist).
- **Berechtigungen:** Gate-Policies auf Item/Folder werden via `LegacyPolicyAuthorization` auf die `FileAbility`-Abilities gemappt (Policies für Vendor-Models müssen manuell registriert werden).
- **Übersetzungen:** `de` liegt bei. **Video/PDF:** v4 hat **keine** aktive Video-Thumbnail/ffmpeg-Logik (auskommentiert) — unsere eigene ffmpeg-Pipeline bleibt relevant (§4.6).

**Harte Voraussetzung, aktuell fehlend:** `spatie/laravel-medialibrary` (`^11.0`) ist **nicht installiert** — der Standard-Driver wirft ohne es eine `LogicException`. Optional: `spatie/laravel-tags` (`^4.11`).

### 2.3 Referenz-Integration nest.kuckuck.cam — was wir übernehmen

nest kapselt das Plugin in eine eigene Schicht (`app/Support/Media/*`, Policies, Serve-Controller). Die Bausteine und ihr Schicksal im CMS:

| nest-Baustein | Verhalten dort | CMS |
|---|---|---|
| `TenantScopedMediaLibraryDriver extends MediaLibraryItemDriver` | `hasTenancy()` auto-erkennt `Filament::getTenant()` (kein `->tenancy()`-Aufruf nötig); ersetzt die strikte `TenantModification` durch User-Scope (eigener Tenant ∪ referenzierte Items ∪ eigene Uploads); re-appliziert `LegacyPolicyAuthorization` in `setUp()` (feld-injizierte Driver umgehen sonst die Policies!); erzwingt Disk via `mediaCollection()`-Callback | **Übernehmen** als `CmsMediaLibraryDriver` (E2) — mit einfacherem Scope (nur aktueller Tenant), aber gleicher Struktur |
| `MediaLibraryItemPolicy`/`MediaLibraryFolderPolicy` + `Gate::policy()` im Provider | `before()`-Bypass für Admins; Root-Ordner-Anlage nur mit Tenant-Kontext (sonst entstünde ein unsichtbarer `tenant NULL`-Ordner) | **Übernehmen** (E2), Superadmin-Bypass nur in Policies, nicht im Sichtbarkeits-Scope |
| `HasMediaReferences`-Contract + `UserMediaScope`-Registry + Parity-Test | Models deklarieren `referencedMediaItemIdsForTenants(Collection $tenants): array`; Registry aggregiert; Test erzwingt Registrierung jedes Implementierers | **Übernehmen** mit **identischer Signatur** (E8) — Basis für Verwendungs-Anzeige, Lösch-Schutz, Import und nest-Interop |
| `MediaPickerPreviewAction` (+ PDF-iframe-View + lang-Keys), global via `MediaPicker::configureUsing()` → `modifyPreviewActionUsing()` | Vorschau-Modal mit Pfeiltasten-Navigation (zirkulär durch alle Picker-Dateien, Alpine `x-on:keydown`), PDF-Inline-Preview, „In neuem Tab öffnen", Direkt-URL via Spatie-`getUrl()` | **Übernehmen** ins Paket, 1:1-Port (E9) |
| Private Disk `media-library` (ohne `url`-Key) + `MediaLibraryUrlGenerator` (Spatie-`url_generator`-Swap) + `MediaLibraryFileController` (public-Allow-List / Policy / Signed-URL, Cache-Control) + `PublicMediaReferences` | Auslieferung ausschließlich über policy-geprüfte Route `media-library.serve` | **Als Opt-in-Ausbaustufe** (E10): CMS-Default bleibt `public`-Disk (Marketing-Sites, statische Auslieferung); Architektur (URLs nur via Spatie-UrlGenerator) macht den Wechsel pro App möglich |
| Spatie-Default-PathGenerator (`{media-id}/`) | Auch die Private-Disk-Migration verschiebt `{media-id}/`-Ordner | **Übernehmen** — der ursprünglich geplante tenant-eigene PathGenerator entfällt (E4) |
| `CamHardwareMediaDriver` (Feld-Driver mit Ordner-Zwang), Duplicate-in-Root, All-Tenants-Sicht im Global-Panel | nest-Spezifika (Operator-Konsole, Cross-Tenant-Referenzen) | **Nicht übernehmen** — im CMS gibt es keine Cross-Tenant-Referenzen und nur ein tenant-gebundenes Panel; das Muster „Feld-Driver mit `->driver()`" bleibt als dokumentierte Option |
| Direktes `InteractsWithMedia` auf `Page` (Page-Builder-Bilder, public) parallel zur Library | Bewusst getrennte Systeme auf derselben `media`-Tabelle | Kein Konflikt — relevant für §7 |

Für uns wichtige nest-Lektionen: (a) die Policy-Re-Bridge im Driver ist load-bearing, (b) Picker-State wird beim Speichern **geleert**, wenn das referenzierte Item für den User nicht auflösbar ist (`findFiles`) — im CMS entschärft, weil Referenzen nie tenant-fremd sind, aber der Grund, warum der Bestands-Import vor redaktioneller Arbeit laufen muss (§6.2), (c) `has_media`-Fixture-Gotcha in Tests.

---

## 3. Architektur-Entscheidungen

**E1 — Driver-Basis: `MediaLibraryItemDriver`, Zugriff über einen paket-eigenen Subclass.** Der Picker-State (Item-IDs) passt verlustfrei in Builder-JSON, `payload`, `meta` und Tenant-Spalten und ist draft-kompatibel. Der alternative `SpatieMediaLibraryDriver` wird nicht benötigt.

**E2 — `CmsMediaLibraryDriver` nach nest-Muster.** `Mmoollllee\Cms\Support\Media\CmsMediaLibraryDriver extends MediaLibraryItemDriver`:

- `hasTenancy()`-Override: aktiv, sobald `Filament::getTenant()` (oder explizit gesetzter Tenant) vorhanden — keine `->tenancy()`-Registrierungspflicht.
- Sichtbarkeit: die Stock-`TenantModification` reicht (immer nur der aktuelle Tenant — WordPress-Metapher „eine Mediathek pro Site"). **Bewusste Abweichung von nest:** kein Scope-Bypass für Superadmins (die wechseln per Tenant-Switcher); Superadmin-Sonderrechte nur in den Policies.
- `setUp()`: `LegacyPolicyAuthorization`-Re-Bridge (nest-Lektion, defensiv auch ohne Feld-Driver), Disk-Erzwingung via `mediaCollection()`-Callback aus `Cms::mediaDisk()` (Default `public`), Conversions-Set (E5) und akzeptierte Typen (`acceptImage/acceptVideo/acceptPdf/acceptZip/…`).
- Registry: `Cms::useMediaDriver(FQN)` / `Cms::mediaDriver()` (Default: Paket-Driver, inkl. `flush()`-Reset) — **die** Austausch-Naht für nest (§7).
- Policies `MediaItemPolicy`/`MediaFolderPolicy` im Paket (`Gate::policy()` in `CmsServiceProvider`, Vendor-Models werden nicht auto-discovered): Tenant-Mitglied → verwalten des aktuellen Tenants, `before()` für Superadmin; Root-Ordner-Regel aus nest übernehmen.

**E3 — Wertformat: IDs in den bestehenden Keys, ein zentraler Resolver.** Die JSON-Keys (`media_path`, `background_image`, `payload.hero.image`, `logo_path`, …) bleiben erhalten; neue Werte sind numerisch (Item-ID), alte bleiben Pfad-Strings. Ein neuer `Support\Media\MediaUrlResolver` ersetzt intern `AssetUrlResolver`-Aufrufe: numerisch → Item, sonst → bisherige Pfad-Logik. **Kein Datenformat-Bruch, kein Content-Migrationszwang fürs Frontend.**

**E4 — Storage: Spatie-Default-PathGenerator (`{media-id}/`), Tenant-Isolation nur in der DB.** *(Revidiert — ursprünglich war ein `TenantAwarePathGenerator` unter `tenants/{site_key}/library/…` geplant.)* Spatie berechnet Pfade zur Laufzeit über den global konfigurierten Generator — ein CMS-eigener Generator würde in jeder App mit Bestands-Mediathek (nest!) sämtliche existierenden Dateipfade brechen und ist damit ein Adoptions-Blocker. nest fährt den Default; wir auch. Tenant-Zuordnung, DSGVO-Löschung und Export laufen über die `tenant`-Morph-Spalten (Items je Tenant abfragen → Spatie löscht die Dateien). Wer physische Trennung braucht, konfiguriert pro App eine eigene Disk(-Root) — nicht den Generator.

**E5 — Conversions AN für den Frontend-Output, Glide nur als Admin-Preview.** `conversions(true)` + `conversionResponsive()` + benannte Breiten (Default: 2560/1600/800/400, per `Cms`-Hook je App änderbar) + `thumb` (600×600, nonQueued) + **`og`** (1200×630 Crop). Konfiguration lebt im Driver-`setUp()` (E2), nicht an der Plugin-Registrierung — der Driver ist die eine Stelle, die Verhalten besitzt (nest-Muster). Conversions laufen queued (Spatie-Default) → Ops-Hinweis §11.

**E6 — URL-Erzeugung ausschließlich über die Spatie-Media-API.** Resolver und Komponenten bauen **niemals** Storage-URLs von Hand, sondern gehen über `$media->getUrl($conversion)` / `getSrcset()` — damit greift ein pro App gesetzter `media-library.url_generator` (nest: Serve-Route für die private Disk) automatisch auch für alle CMS-Views. Konsequenz für `srcset`: Responsive-Image-URLs funktionieren nur auf direkt adressierbaren Disks; der Resolver liefert `srcset` daher nur, wenn die Disk eine Basis-URL hat, sonst fällt `<x-site.image>` auf ein einfaches `<img>` mit passender Conversion-URL zurück (die Serve-Route nimmt `{conversion?}` entgegen).

**E7 — Video-Konvertierung wandert vom Content-Save an den Upload, als Observer statt Model-Vererbung.** Ein in `CmsServiceProvider` auf `Cms::mediaItemModel()` registrierter Observer (nicht hart im Model verdrahtet — überlebt Model-Swaps, §7) prüft beim Anlegen mit der bestehenden `VideoConversionHelper`-Policy (mov/avi/wmv immer, mp4 > 10 MB) und dispatcht `ConvertLibraryVideo` (ffmpeg-Parameter aus `ConvertVideoForWeb`). Ergebnis ersetzt die Originaldatei über die `singleFile`-Collection (Item-ID stabil). Poster/Thumbs von Videos liefert Spaties `Video`-ImageGenerator (php-ffmpeg ist bereits Dependency) → `thumb` funktioniert auch für Videos; `poster_path` im Block wird optionaler Override. Damit sind **Fragment-Videos** abgedeckt und Doppel-Konvertierungen weg. Qualität: Default `medium` (Re-Encode-Auswahl als Kür); Status in `video_status` (paket-eigene Ergänzungsmigration auf `filament_media_library`), sichtbar via `fileInfoInformationUsing()`. Das Item-Model selbst bleibt austauschbar: `Cms::useMediaItemModel()`, Default = Plugin-`MediaLibraryItem` (kein Zwangs-Subclass — nest nutzt das Stock-Model).

**E8 — Referenz-Tracking nach nest-Contract.** Neuer Paket-Contract mit **exakt nests Signatur**:

```php
interface HasMediaReferences
{
    /** @param Collection<int, Tenant> $tenants @return array<int> */
    public static function referencedMediaItemIdsForTenants(Collection $tenants): array;
}
```

Implementiert von Content (scannt `blocks`/`payload`/`meta` **+ `draft`**), Fragment (`blocks` + `draft`) und Tenant (`*_path`-Spalten, numerische Werte); Registry `Support\Media\MediaReferences::$sources` + **Parity-Test** (globbt Models nach dem Interface — nest-Muster). Verwendet für: (a) „Wird verwendet auf …"-Anzeige im File-Info-Panel, (b) Lösch-Schutz (Policy/Aktion warnt bzw. blockt bei referenzierten Items), (c) den Bestands-Import §6.2 (dieselbe Scan-Logik), (d) künftig nests `UserMediaScope`/`PublicMediaReferences`, die CMS-Models dann ohne Adapter aggregieren können.

**E9 — `MediaPickerPreviewAction` ins Paket portieren.** 1:1-Port von nest (`extends PreviewAction`): Modal mit Datei-Titel, **Pfeiltasten-Navigation** zirkulär durch die Dateien des Pickers (Alpine `x-on:keydown` + `replaceMountedAction`), PDF-Inline-Preview (iframe-View, `h-[75vh]`), Bilder über den ImageGenerator (2×848 px), Footer „In neuem Tab öffnen" + Zurück/Weiter, Direkt-URL via Spatie-`getUrl()` (damit private Disks die Serve-Route liefern). Global aktiviert nach nest-Muster in `CmsServiceProvider::boot()`:

```php
MediaPicker::configureUsing(fn (MediaPicker $picker) => $picker
    ->modifyPreviewActionUsing(fn (): MediaPickerPreviewAction => MediaPickerPreviewAction::make()));
```

App-Provider booten nach dem Paket → eine App (nest) kann mit eigenem `configureUsing` überstimmen. View `cms::filament.media-library.pdf-preview` + lang-Keys (`de`/`en`: Zurück/Weiter) wandern mit.

**E10 — Disk-Strategie: `public` als Default, private Mediathek als Opt-in-Modul.** CMS-Sites sind öffentliche Marketing-Auftritte — Bilder müssen statisch/CDN-fähig ausgeliefert werden, nicht durch PHP. Default also `public`-Disk mit direkten URLs. Das nest-Muster (private Disk ohne `url`-Key, `url_generator`-Swap, Serve-Controller mit public-Allow-List/Policy/Signed-URL, Cache-Control) wird als **Opt-in** vorgesehen: `Cms::useMediaDisk('media-library')` + dokumentiertes Rezept; eine spätere Paket-Ausbaustufe kann Generator + Serve-Controller mitliefern (Story: **Als Betreiber eines Members-Tenants möchte ich Medien nur eingeloggt ausliefern, um interne Dokumente zu schützen** — heute gated `TenantVisibility::Members` nur HTML, nicht Dateien). Durch E6 ist das Frontend darauf vorbereitet.

**E11 — Verdrahtung nach Hauskonvention.** `BasePanelProvider::panel()` registriert `->plugin($this->mediaLibraryPlugin())`, überschreibbar pro App (Muster Menu-Builder):

```php
protected function mediaLibraryPlugin(): FilamentMediaLibrary
{
    return FilamentMediaLibrary::make()
        ->navigationLabel('Mediathek')
        ->navigationGroup('Inhalt')
        ->navigationSort(10)
        ->slug('mediathek')
        ->driver(Cms::mediaDriver());   // Verhalten (Tenancy, Disk, Conversions, Typen) lebt im Driver
}
```

`Cms::`-Registry: `useMediaDriver()`, `useMediaItemModel()`, `useMediaDisk()` (+ Getter, `flush()`-Reset). `config/cms.php` bleibt env-only; Größenlimits liegen in Spaties `config/media-library.php` (App-seitig; `CmsServiceProvider` setzt via `config()->set` sinnvolle Defaults, solange nicht published).

---

## 4. Integrationsflächen im Detail

### 4.1 MediaBlock (Leitbeispiel)

```php
// vorher: FileUpload::make('media_path')->disk('public')->directory(...)->acceptedFileTypes([...])
MediaPicker::make('media_path')                 // Key bleibt! Wert wird Item-ID
    ->label('Bild / Video')
    ->acceptedFileTypes(['image/*', 'video/mp4', 'video/webm', 'video/quicktime'])
    ->live(),
```

- Kein `->driver()` am Feld — Felder erben den Panel-Plugin-Driver (nest setzt Feld-Driver nur für den Cam-Sonderfall; das CMS braucht keinen).
- `is_video`-Erkennung übers Item (`mime_type`), Endungs-Fallback für Legacy-Pfade bleibt.
- `video_quality`/`video_keep_audio` entfallen im Block (Konvertierung am Upload, E7); `poster_path` → MediaPicker image-only, Default = Auto-Thumb.
- `media_alt` bleibt als **Override**; leer → `alt_text` des Items → Blocktitel.

### 4.2 Sektions-Hintergrund & Hero-Felder

`sectionBackgroundImageField()` und `PageHeaderFields` (`payload.hero.thumbnail|image|float_image`) → `MediaPicker` image-only. `uploadDirectory()` wird deprecated (No-op); stattdessen optional `->defaultFolder()`. Werte bleiben in `payload` (draft-sicher); `Content::heroImageUrl()/thumbnailUrl()` laufen über den Resolver — Konsumenten merken nichts.

### 4.3 Tenant-Branding (inkl. Favicon-Gap)

`EditTenantProfilePage`: vier `FileUpload` → `MediaPicker` (Mail-Logo raster-only, Favicon-Typen wie nest: ico/svg/png), **plus neues Favicon-Feld** (`favicon_path` in `$fillable` ergänzen — Workbench + Starter + Apps). `InheritsBranding::resolved*Url()` delegiert an den Resolver; die Vererbungskaskade bleibt unverändert. Frontend-Auflösung tenant-fremder (geerbter) IDs ist unkritisch: gerendert wird per Model-Query ohne Filament-Scope.

### 4.4 SEO / OG-Bild

`SeoFields` bekommt `meta.og_image` (MediaPicker, image-only) — schließt „OG-Bild pro Inhalt". `seo-head.blade.php`: `meta.og_image` (ID → `og`-Conversion, absolute URL) → Legacy `meta.og_image_url` → `resolvedDefaultOgImageUrl()`.

### 4.5 RichEditor (Spike nötig)

Ziel: `attachFiles` durch das Plugin-`MediaPlugin` ersetzen (Einfügen aus der Mediathek, Alt-Text, Conversion-Wahl; gespeichert wird die Item-ID). Klärpunkte, weil unsere RichText-Blöcke nicht model-gebunden sind und das Frontend über den eigenen tiptap-php-`Renderer` läuft: (1) funktioniert `->plugins([MediaPlugin::make()])` feld-level ohne `InteractsWithRichContent`? (2) Node-/Attribut-Form fürs Renderer-Mapping (ID → `srcset`-`<img>`). nest nutzt keine RichEditor-Media-Integration — hier liefert die Referenz nichts; Fallback: `attachFiles` behalten, aber `fileAttachmentsDisk('public')` + tenant-gescopetes Directory.

### 4.6 Video-Pipeline

Siehe E7. Übergang: `ConvertsUploadedVideos`/`ConvertVideoForWeb` bleiben für Legacy-Pfad-Blöcke funktionsfähig, werden nach der Bestandsmigration deprecated und später entfernt.

### 4.7 Frontend-Komponenten & Resolver

- **`Support\Media\MediaUrlResolver`** — `url(mixed $ref, ?string $conversion = null): ?string`, `srcset()` (nur bei direkt adressierbarer Disk, E6), `mime()`, `alt()`; nimmt `int|string|array` (FileUpload-Array-Altlast), Request-Cache + **Batch-Preload**: `<x-site.content-blocks>` sammelt vor dem Rendern alle numerischen Refs eines Inhalts und lädt sie mit einem `whereIn` (kein N+1).
- **`<x-site.image :media="$ref" :sizes="…">`** — `<img src srcset sizes width height alt loading="lazy" decoding="async">`; Legacy-Pfad → schlichtes `<img>` wie heute.
- **`<x-site.media-item>`** — Video-Zweig nutzt Item-MIME + Auto-Poster.
- Panel-Polish (optional): `MediaColumn` als Thumbnail-Spalte in der Content-Tabelle (nest-Muster `->square()` im LogoResource).

### 4.8 Preview-Action & File-Info

`MediaPickerPreviewAction` global (E9). File-Info-Panel: „Wird verwendet auf …" via `fileInfoInformationUsing()` aus `MediaReferences` (E8); `video_status`-Anzeige (E7).

---

## 5. Neue Blöcke (Kür, eigenständige Stories)

- **Als Redakteur möchte ich einen Galerie-Block (mehrere Bilder, sortierbar, Lightbox-fähig), um Bildstrecken ohne Einzelblöcke zu pflegen.** → `MediaPicker::multiple()->reorderable()`, Grid, `srcset` je Kachel.
- **Als Redakteur möchte ich einen Downloads-Block (PDF/ZIP/Office, mehrfach, sortierbar), um Dateilisten mit Icon, Dateigröße und Typ anzubieten.** → Dokument-`acceptedFileTypes`, Frontend liest `size`/`mime_type` vom Media; PDF-Vorschau kommt via E9 gratis.
- **Als Redakteur möchte ich Medien mit Schlagwörtern filtern können, um große Mediatheken zu organisieren.** → optional `spatie/laravel-tags` + `->spatieTagsIntegration()`.

---

## 6. Migration & Rollout

### 6.1 Paket-Setup (dieses Repo) — ✅ umgesetzt (Rev. 3)

1. Plugin + `spatie/laravel-medialibrary` als **require-dev + suggest** (optionale
   Integration; Clients installieren selbst). Migrationen publishen Clients direkt von
   Plugin/Spatie; der Workbench hält lokale Kopien (testbench.yaml). Die
   `video_status`-Ergänzung kommt erst mit P4.
2. `CmsMediaLibraryDriver`, Policies, Resolver, Preview-Action, `Cms::`-Registry,
   gated Plugin-Registrierung in `BasePanelProvider::mediaLibraryPlugin()`.
3. **Theme:** Plugin-CSS braucht ein Filament-Custom-Theme (`@import
   …/laravel-filament-media-library/resources/css/index.css`) — beide Consumer-Apps
   haben eins (Audit ✅). Workbench-Theme + `cms:install`-Scaffolding: offen (P4/P6).
4. Tests + Doku: ✅ (README-Sektion, FEATURES §Media, CUSTOMIZATION §12).

### 6.2 Bestandsdaten: `cms:media:import`

Idempotenter Befehl (`--dry-run`, `--tenant=`, `--all`, Report/Mapping-Log):

1. **Sammeln:** alle Datei-Referenzen aus `contents.blocks|payload|meta`, **`contents.draft`**, `fragments.blocks`, **`fragments.draft`**, `tenants.*_path` — dieselbe Scan-Logik wie `HasMediaReferences` (E8), einmal gebaut.
2. **Importieren:** pro eindeutigem Pfad ein Item beim passenden Tenant (aus `tenants/{site_key}/…`), Ordner nach Segment („Branding", „Hero", „Inhalte", „SEO"), `alt_text` aus `media_alt` vorbefüllen. Mehrfach-Referenzen → dieselbe ID. `--all` importiert auch Unreferenziertes.
3. **Rewriten:** Pfad → ID (via `saveQuietly`).
4. Originaldateien bleiben liegen (Rollback-Sicherheit); Aufräumen später via `cms:media:prune-legacy`.

**Pflichtschritt vor redaktioneller Arbeit** nach dem Update: ein MediaPicker mit nicht auflösbarem State zeigt leer und würde beim Speichern den Altwert verwerfen (nest-Lektion `findFiles`, §2.3). Das Frontend ist durch den Resolver-Fallback unabhängig davon.

### 6.3 Consumer-Apps (Starter, muench-tiefbau.de, pernes-hebesysteme.de)

Pro App: satis-Repo + `auth.json`-Lizenz, `composer update`, `php artisan migrate`, Theme-Import (Starter fertig; sonst `make:filament-theme`), ggf. `media-library.php` publishen (`max_file_size` — Videos!), Queue-Worker für Conversions, dann `cms:media:import`. Apps mit eigenem `configureRichEditor()` ziehen den neuen Toolbar-Button nach.

### 6.4 Rückwärtskompatibilität & Rollback

Resolver-Fallback hält nicht migrierte Frontends am Leben; `AssetUrlResolver`-API bleibt (delegiert). Rollback = Paket-Downgrade + Import-Mapping-Log als Not-Ausgang (dokumentieren, nicht bauen).

---

## 7. Kompatibilität: filament-cms in nest.kuckuck.cam

Damit nest später die CMS-Engine einsetzen kann und beide **eine** Mediathek teilen, gelten diese Regeln (alle oben bereits eingearbeitet):

1. **Driver austauschbar:** nest registriert `Cms::useMediaDriver(TenantScopedMediaLibraryDriver::class)` — CMS-Felder tragen keinen Feld-Driver und erben nests Scoping (inkl. User-Scope/Referenz-Erweiterung) automatisch.
2. **Model austauschbar:** `Cms::useMediaItemModel()` — Default ist das Stock-`MediaLibraryItem`, das nest ebenfalls nutzt; Morph-Alias `filament_media_library_item` bleibt identisch. Der Video-Observer (E7) hängt am konfigurierten Model, nicht an einer Vererbungslinie.
3. **Kein eigener PathGenerator, keine handgebauten URLs:** E4 + E6 stellen sicher, dass nests private Disk, `MediaLibraryUrlGenerator` und Serve-Route für alle CMS-Views transparent funktionieren (`srcset` degradiert kontrolliert).
4. **Referenz-Contract signaturgleich:** CMS-Models (Content/Fragment/Tenant) liefern `referencedMediaItemIdsForTenants()` — nests `UserMediaScope`/`PublicMediaReferences` können sie als zusätzliche Quellen registrieren; der Parity-Test existiert in beiden Welten. CMS-Inhalte öffentlicher Tenants werden so in nests public-Allow-List integrierbar.
5. **Preview-Action kollisionsfrei:** beide registrieren via `configureUsing`; App-Provider booten nach dem Paket → nests Variante gewinnt, bzw. nest löscht seine lokale Kopie zugunsten der Paket-Klasse (gleicher Code).
6. **Wertformate kompatibel:** nest speichert IDs in JSON-Spalten/FKs (`belongsToJson`), CMS speichert IDs in Block-/Payload-JSON — dieselben Items, dieselbe Tabelle, keine Format-Übersetzung nötig.
7. **Koexistenz mit nests Page-Builder-Media** (direktes `InteractsWithMedia` auf `Page`, public Disk): unberührt — getrennte Rows derselben `media`-Tabelle; ein späterer Umzug auf CMS-Blöcke wäre ein Import wie §6.2.
8. **Migrationen idempotent:** nests Tabellen existieren bereits (inkl. Tenancy-Morphs, teils Legacy-`uploaded_by_user_id` → polymorpher Uploader via Plugin-Migration) — CMS-seitige Migrationen müssen `hasTable`/`hasColumn`-guarded sein und beide Uploader-Schemata tolerieren (Plugin-API statt Roh-Spalten nutzen).

---

## 8. Caching & Invalidierung

- ~~`MediaItemObserver`~~ **entfällt** (Rev. 3): Die Seiten-Caches speichern
  Model-Payloads, kein gerendertes HTML — Media-URLs entstehen bei jedem Render über den
  Resolver, ein Ersetzen/Löschen greift ohne Invalidierung sofort.
- Resolver: request-statischer Cache + Batch-Preload (§4.7). Referenz-Scans (`MediaReferences`) im Hot-Path per `once()` memoizen (nest-Muster).

---

## 9. Tests (Pest; `Storage::fake` + `CurrentTenant`; Fixtures brauchen eine Spatie-Media-Row in Collection `library`, sonst greift der `has_media`-Scope — nest-Gotcha)

1. Scope-Spec nach nest-Vorbild (`MediaLibraryScopeTest`): Items/Ordner des aktuellen Tenants sichtbar, fremde nicht; Upload stempelt Tenant + Uploader; Root-Ordner-Policy; Superadmin via Policy, nicht via Scope.
2. **Parity-Test:** jedes `HasMediaReferences`-Model ist in `MediaReferences::$sources` registriert (und umgekehrt).
3. Resolver: ID → URL/Conversion/srcset; srcset-Degradation auf disk ohne Basis-URL; Legacy-Pfad; Array-Altlast; unbekannte ID → `null`.
4. Branding-Kaskade mit ID-Werten inkl. Satelliten-Vererbung; Favicon-Feld.
5. `<x-site.image>`: srcset/lazy-Markup; Legacy-Fallback.
6. Import: dry-run, Idempotenz, Draft-Spalten-Rewrite, Mehrfachreferenz → eine ID; Save-Roundtrip nach Import verliert keine Referenzen (nest-Regression `findFiles`).
7. Video: Observer dispatcht nur bei Bedarf; Fragment-Upload konvertiert; `video_status`-Verlauf.
8. OG: `meta.og_image` → absolute `og`-URL; Fallback-Kette.
9. Draft-Roundtrip mit Media-IDs (speichern/anwenden/Preview).
10. Preview-Action: Navigation (zirkulär, disabled bei <2 Dateien), PDF-Zweig, lang-Keys de/en (nest testet genau das).
11. Conversions in Tests deaktivieren bzw. nonQueued.

---

## 10. Arbeitspakete

| Phase | Story | Kern-Deliverables |
|---|---|---|
| **P0 Fundament** | Als Betreiber möchte ich die Mediathek pro Tenant im Panel haben, um Medien zentral zu browsen und hochzuladen. | spatie-Require, Migrationen, `CmsMediaLibraryDriver` + Policies + Registry (`useMediaDriver/useMediaItemModel/useMediaDisk`), `mediaLibraryPlugin()`, Workbench-Theme, de-Labels |
| **P1 Resolver & Frontend** | Als Besucher möchte ich responsive, lazy geladene Bilder, um schnelle Seiten zu bekommen. | `MediaUrlResolver` (+Batch, srcset-Degradation), `<x-site.image>`, `media-item`-Umbau, Conversion-Set inkl. `og` |
| **P2 Picker-UX & Felder** | Als Redakteur möchte ich überall aus der Mediathek wählen statt hochzuladen — mit Vorschau wie in nest. | **`MediaPickerPreviewAction`-Port (+ View + lang)**, MediaBlock, Sektions-BG, `PageHeaderFields`, Tenant-Branding + Favicon, `SeoFields.og_image` |
| **P3 Referenzen & Bestandsmigration** | Als Betreiber möchte ich Bestandsdateien verlustfrei überführen und sehen, wo Medien verwendet werden. | `HasMediaReferences` + `MediaReferences` + Parity-Test, „Wird verwendet"-Info, Lösch-Schutz, `cms:media:import` (+ Log), Rollout-Doku, Starter/`cms:install` |
| **P4 Video** | Als Redakteur möchte ich, dass Videos beim Upload einmalig weboptimiert werden. | Observer + `ConvertLibraryVideo`, `video_status`, Auto-Poster, Deprecation alte Pipeline |
| **P5 RichEditor** | Als Redakteur möchte ich Mediathek-Bilder im Fließtext einfügen. | Spike (§4.5), `MediaPlugin`-Wiring, Renderer-Node-Mapping |
| **P6 Kür** | §5-Stories + E10-Story | Galerie-Block, Downloads-Block, Tags, `MediaColumn`, Re-Encode-Aktion, Private-Disk-Modul (Generator + Serve-Controller + `PublicMediaReferences`) |

Reihenfolge: P0 → P3 als zusammenhängender Kern (ein Release); P4/P5 danach; P6 nach Bedarf. Consumer-Rollout (§6.3) nach P3.

---

## 11. Offene Punkte

1. **RichEditor-Spike** (§4.5) — feld-level `MediaPlugin` + Node-Shape für den tiptap-php-Renderer.
2. **Workbench-Theme-Build** — kleinstmögliches Vite-Setup für die Explore-UI im Demo/Testbench (Hash-Guard der vendored Views beachten).
3. **Ops pro App:** `media-library.max_file_size` (Spatie-Default 10 MB — für Videos zu klein), PHP/Livewire-Upload-Limits, Queue-Worker, Glide-Cache-Verzeichnis.
4. **Lizenz in CI/Deploys** der Consumer-Apps (satis-Credentials als Secret).
5. Als Betreiber möchte ich unreferenzierte Altdateien erst nach Sichtung des Dry-Run-Reports importieren (`--all` bleibt Opt-in), um keine Karteileichen in die Mediathek zu übernehmen.
6. Als Redakteur möchte ich, dass das Löschen referenzierter Medien hart geblockt wird und mir die Verwendungsliste angezeigt wird, um niemals live verwendete Bilder zu zerstören (WordPress warnt nur — wir können es besser).
