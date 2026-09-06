# Artsci Migration

Imports D10 content into D11 via the source site's JSON:API endpoint.
Built for a one-time content migration; safe to re-run because migrate
tracks rows by their source UUID + drupal_internal__nid.

## Migrations included

| ID                                          | What it does                                                   |
| ------------------------------------------- | -------------------------------------------------------------- |
| `artsci_d10_taxonomy_areas_of_study`        | D10 `areas_of_study` terms imported into D11 `research_areas` vocab. |
| `artsci_d10_file_image`                     | Downloads image binaries, creates `file` entities.             |
| `artsci_d10_file_cv`                        | Downloads document binaries (PDF/Word/etc), creates `file` entities. |
| `artsci_d10_media_image`                    | Creates `media:image` entities referencing the migrated files. |
| `artsci_d10_media_cv`                       | D10 `cv` media imported into D11 `file` media bundle.           |
| `artsci_d10_paragraph_social_media_links`   | Creates `paragraph:social_media_links` entities.               |
| `artsci_d10_paragraph_artsci_contact`       | Synthesizes `paragraph:artsci_contact` from D10 mailing addresses. |
| `artsci_d10_article`                        | Creates `node:article` entities, references migrated media.    |
| `artsci_d10_person`                         | Creates `node:person` entities from D10 `faculty_staff`.       |
| `artsci_d10_author`                         | Creates `node:person` entities from D10 `author` (type=author).|

Dependencies are declared, so running `--group=artsci_d10` does them in
the right order.

## Install

1. Drop this module into `web/modules/custom/artsci_migration`.
2. `drush en artsci_migration -y`
3. `drush cr` — migrations under `migrations/` are auto-discovered.

## Run

```sh
# Inspect what's available.
drush migrate:status --group=artsci_d10

# Run the whole group in dependency order.
drush migrate:import --group=artsci_d10

# Or run individually.
drush migrate:import artsci_d10_file_image
drush migrate:import artsci_d10_media_image
drush migrate:import artsci_d10_article

# Roll back in reverse dependency order.
drush migrate:rollback artsci_d10_article
drush migrate:rollback artsci_d10_media_image
drush migrate:rollback artsci_d10_file_image

# Reset a stuck migration.
drush migrate:reset-status artsci_d10_article
```

## What's mapped on the article

| D10 source                                       | D11 destination                          | Notes                                                  |
| ------------------------------------------------ | ---------------------------------------- | ------------------------------------------------------ |
| `id` (UUID)                                      | `uuid`                                   | Preserved across migration runs.                       |
| `attributes.title`                               | `title`                                  |                                                        |
| `attributes.status` / `promote` / `sticky`       | same                                     |                                                        |
| `attributes.langcode`                            | `langcode`                               |                                                        |
| `attributes.created` / `changed`                 | `created` / `changed`                    | ISO 8601 → Unix via `strtotime`.                       |
| `attributes.moderation_state`                    | `moderation_state`                       | Defaults to `published` if missing.                    |
| `attributes.body.{value,format,summary}`         | `body/{value,format,summary}`            | Strips literal `<html>...</html>` wrapper from value.  |
| `attributes.path.alias`                          | `path/alias`                             | Pathauto disabled for migrated rows.                   |
| `attributes.field_link_excerpt.value`            | `field_teaser`                           |                                                        |
| `attributes.field_is_shared`                     | `field_is_shared`                        |                                                        |
| `attributes.field_shared_content`                | `field_shared_content`                   |                                                        |
| `attributes.field_shared_content_xml`            | `field_shared_content_xml`               |                                                        |
| `attributes.field_content_hash`                  | `field_content_hash`                     |                                                        |
| `relationships.field_category[*]`                | `field_tags`                             | Writes raw target_ids — see Caveats.                   |
| `relationships.field_header_image`               | `field_image`                            | Looked up via `artsci_d10_media_image`.                |
| `attributes.rh_*`                                | `rabbit_hole__settings/rh_*`             | All four sub-properties wired up.                      |

## What's mapped on media:image

`name`, `status`, `langcode`, `created`, `changed`, `bundle=image`, plus
`field_media_image` with `target_id` (looked up from the file migration)
and `alt` / `title` / `width` / `height` carried from the source's
relationship meta.

## What's mapped on file

`filename`, `filemime`, `filesize`, `status`, `created`, `changed`,
`uri` — the file binary is downloaded from the source site via
`file_copy` and saved to the same `public://` path on D11. Body content
referencing `/sites/.../files/...` keeps working without rewriting.

## What's intentionally NOT mapped (yet)

These need their own prerequisite migrations or a custom process plugin
before they can be wired in. Stubs are commented out in the article YAML.

- `field_article_author` ← `field_author_select` node reference (needs
  an author node migration).
- `field_meta_tags` ← `metatag` array (needs a custom process plugin to
  convert JSON:API's array-of-objects format back into metatag's
  serialized storage. Until then, metatag will regenerate from defaults.)
- `uid` / `revision_uid` ← user reference (defaults to user 1).

## What's dropped (no D11 destination)

D10 article fields with no equivalent on D11: `field_article_home_slideshow`,
`field_author`, `field_introduction_excerpt`, `field_last_fetch`,
`field_reverse_header`, `field_search`, `field_soundcloud(_media)`,
`field_allow_in_grid`, `field_content_type`, `field_external_link`,
`field_featured_description`, `field_feature_article`, `field_link_thumbnail`,
`field_video_p`, `field_footer_callout_p`, `field_faculty_and_staff`,
`field_shared_content_subscribers`.

## D11 article fields left blank

These exist on D11 articles but the D10 source has no obvious
counterpart. Verify each against your editorial team:

- `field_article_source_link` / `field_article_source_link_direct`
- `field_article_source_org`
- `field_article_subhead`
- `field_contact_reference`
- `field_featured_image_display`
- `field_gallery_images`
- `field_image_caption`
- `field_related_content`

## Caveats

**Tag IDs.** `field_tags` is currently mapped from `field_category` by
raw `target_id`. That works only if the D10 term IDs already exist as
the same IDs on D11. If they don't, build a
`artsci_d10_article_categories` taxonomy migration and swap the tags
process to use `migration_lookup`.

**Pagination.** All three migrations use migrate_plus's `urls` pager
against `links/next/href` — the selector value is used as the next URL
outright. (Do NOT change this to `cursor`; that pager type appends
`?cursor=VALUE` to the base URL, which JSON:API rejects with a 400.)
If a migration runs but only imports the first 50 rows, double-check
the pager type is `urls` and not `cursor`.

**File migration scope.** `artsci_d10_file_image` filters by mimetype
prefix `image/` on the source. If you have non-image files referenced
elsewhere later (PDFs, etc.), build a parallel `artsci_d10_file_document`
or drop the filter.

**Rate limits.** Importing thousands of files will fire many requests at
the source. If you see HTTP 429s, throttle with:

```sh
drush migrate:import artsci_d10_file_image --limit=50
```

…then re-run until the source is empty.

**Body HTML wrapper.** The `<html>...</html>` strip is a literal string
replace. If any source body uses `<html lang="...">`, that won't match.

**file_copy behavior.** Set to `use existing` so re-running the file
migration won't re-download things you already have. Switch to `replace`
in the source YAML if you want to force fresh copies.
