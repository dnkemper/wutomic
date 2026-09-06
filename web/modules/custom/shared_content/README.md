# Shared content

Cross site content syndication for the Arts & Sciences network of Drupal sites. Source sites publish each shared node as an XML feed; downstream sites consume the feed via Drupal core's Aggregator module, materialize each item as a full node, and keep it in sync with the source over time.

The module sits on top of `aggregator` and adds three things core doesn't provide:

1. A bulk action that turns aggregator items into typed nodes with mapped fields.
2. A field mapping service that translates the XML payload into Drupal field values per bundle.
3. A refresh service that re fetches XML on cron, detects changes by hash, and updates or deletes nodes accordingly.

## Content flow

Each source site exposes one XML URL per shared node at `https://{source}/xml/{type}/{nid}/rss.xml`. The lifecycle on a downstream site looks like this:

1. Drupal core aggregator pulls items from each source feed on a schedule (minimum hourly; the form alter in `shared_content.module` enforces that floor).
2. An editor visits the aggregator items list, selects one or more rows, and runs the **Import selected feed items as content** bulk action. Items already imported are disabled in the checkbox column by `shared_content_form_views_form_aggregator_item_page_1_alter()`.
3. `ImportAggregatorFeedItemAction::createNodeFromXml()` parses the XML, creates a node of the matching bundle, and populates the bundle specific fields inline plus delegates image and CV handling to the helper methods at the bottom of the action.
4. On cron, `SharedContentFeedRefresherForce::runChunked()` walks 50 nodes per run, sends conditional `If-None-Match` and `If-Modified-Since` requests, and either:
   - skips the node (304 from source),
   - updates it (200 with a different content hash, calls `SharedContentFieldMapper::mapAllFields()`),
   - deletes it (404, 410, or empty XML after seven days; immediate delete if it never successfully synced).
5. `hook_node_presave()` in `shared_content.module` recomputes `field_content_hash` whenever `field_shared_content` changes, so the next refresh's hash comparison stays apples to apples.
6. `hook_entity_predelete()` records who deleted what via `NodeDeletionAuditSubscriber`, queryable with `drush sc:audit`.

## Tracking fields

Four bundles participate in shared content: `person`, `event`, `article`, `book`. All four carry the same set of tracking fields, attached during the migration and config.

| Field | Type | Purpose |
| --- | --- | --- |
| `field_shared_content_xml` | string | Source feed URL. The dedup key for "is this already imported?" |
| `field_shared_content` | string_long | base64 encoded XML payload as last fetched. The source of truth for `field_content_hash`. |
| `field_content_hash` | string | sha256 of `field_shared_content`. Used for change detection in the refresher. |
| `field_last_fetch` | timestamp | Last successful fetch time. Used by the refresher's age filter and by `processDeletedNodes()`. |
| `field_is_shared` | boolean | TRUE when this node was created from shared content. Used by `shared_content_form_alter()` to lock most fields against non admin editing. |

## XML element to field mapping

The canonical mapping lives in two places: `ImportAggregatorFeedItemAction::createNodeFromXml()` (for initial import) and `SharedContentFieldMapper::mapAllFields()` (for refresh). They must stay in sync — when you change one, change the other.

### Person (person bundle on D11, was `faculty_staff` on D10 sources)

| XML element | Drupal field | Notes |
| --- | --- | --- |
| `<firstName>`, `<lastName>` | `field_person_first_name`, `field_person_last_name`, and node title | Title is built from `"{first} {last}"`. Falls back to `<title>` when both names are empty. |
| `<position>`, `<additionalTitles>` | `field_person_position` (multivalue) | `<additionalTitles>` is split on `<br>`. |
| `<emailAddress>`, `<phoneNumber>`, `<faxNumber>` | `field_person_email`, `field_person_phone`, `field_person_fax` | Direct passthrough. |
| `<biography>` + `<introText>` | `body` (text_with_summary) | introText becomes the body summary; tags stripped. |
| `<description>` | `field_person_description` | full_html. |
| `<pronouns>` | `field_person_credential` | Yes, this is a legacy mismatch in the field name. |
| `<interests>` | `field_person_interests` | `<br>` separated list rendered as `<ul><li>`. |
| `<education>` | `field_person_education` (multivalue) | `<br>` separated. |
| `<department>` | `field_person_department` (taxonomy ref) | `getOrCreateTerm()` against the `department` vocab. |
| `<links>` | `field_person_website` (link multivalue) | HTML anchor parsing. |
| `<mailingAddress>` | `field_person_contact_information` (artsci_contact paragraph) | Address parsed into structured `country_code` / `locality` / `administrative_area` / `postal_code` / `address_line1` / `address_line2`. |
| `<headshot>` | `field_image` (media:image) | Downloaded into `public://shared_content/headshots/`. |
| `<cv>` | `field_person_file_upload` (media:file) | Downloaded into `public://shared_content/cvs/`. `extractUrl()` pulls the href out of the HTML wrapped fragment. |
| `<externalURL>` | `field_external_link` | Raw URL, no extraction. |
| `<officeDirectionsURL>` | `field_external_url` | Raw URL. |
| `<buildingNameRoomNumber>`, `<officeHours>` | `field_building_name_and_room_num`, `field_office_hours` | full_html. |

### Event

| XML element | Drupal field | Notes |
| --- | --- | --- |
| `<title>` | node title | |
| `<description>` | `body` | Relative `/sites/{host}/files/` URLs rewritten to absolute. |
| `<eventDateStart>`, `<eventDateEnd>` (fallback `<eventDate>` split on ` to `) | `field_event_when` (Smart Date) | Stored as value/end_value with a computed duration in minutes. Timezone left empty so the site default applies. |
| `<introText>` | `field_teaser` (string_long) | The source introduction excerpt. |
| `<location>`, `<additionalLocationInformation>` | `field_event_location` (address) | Venue to `address_line1`, additional info to `organization`, country US. Mirrors the migration's `parse_mailing_address`. |
| `<eventContact>` | `field_event_contact` (email) | First valid email token only; invalid values are dropped. |
| `<thumbnail>` | `field_image` (media:image) | Downloaded into `public://shared_content/thumbnails/`. |
| `<buttonText>`, `<buttonURL>` | `field_event_virtual` (link) | Set only when `buttonURL` is non empty. |
| `<rsvpLink>` | `field_rsvp_form` (webform reference) | Webform machine name parsed from the `/form/{name}` path; set only when that webform exists locally. |
| `<externalURL>` | `field_event_series_link` (link) | Only when non empty. |

Not mapped, matching the `artsci_d10_event` migration: `<eventTBD>` (`field_event_date_tbd` does not exist on D11), `<eventGeolocation>` (D11 `field_event_geolocation` is a geofield, not carried from the source string), and `field_event_category` (the feed emits no category element).

### Article

| XML element | Drupal field | Notes |
| --- | --- | --- |
| `<title>` | node title | |
| `<description>` | `body` | Relative URL rewrite. |
| `<postDate>` | node created time | Parsed from `n.j.y` format. Unparseable dates reset to `now` (matches the action). |
| `<externalURL>` | `field_event_series_link` | Only when non empty. |

### Book

| XML element | Drupal field | Notes |
| --- | --- | --- |
| `<title>` | node title | |
| `<cover>` | `field_image` (media:image) | Downloaded into `public://shared_content/covers/`. Pulled through `extractUrl()` so HTML wrapped variants also work. |
| `<boptions>` | `field_byline_options` (list_string) | Direct passthrough. |
| `<bnames>` falling back to `<author>` | `field_byline_names` (string) | Plain string, not text_long, so set as a scalar — no value/format array. |
| `<authornid>` | `field_book_author` (entity_ref to person+author) | Resolved via `findLocalPersonByRemoteId()` which constructs `{base}/xml/faculty_staff/{authornid}/rss.xml` and looks up the matching local person. Falls back to text storage in `field_book_author_name` if the lookup fails. |
| `<links>` | `field_book_links` (link multivalue) | HTML anchor parsing — D10 stored these as paragraphs; D11 collapses them into a plain link field. |
| `<description>` | `body` and `field_description` | Same value written to both fields with full_html, relative URLs rewritten. |

## Where to make changes

### Adding a new field to a shared bundle

Four places get touched in the same change:

1. **Field config** — add the field via `drush field:create` or by editing config under `config/default/field.field.node.{bundle}.{field}.yml`. Export with `drush cex` and commit.
2. **Migration YAML** (if the field also has a D10 source) — add a mapping in `web/modules/custom/artsci_migration/migrations/artsci_d10_{bundle}.yml`. Without this, the one time migration won't backfill the new field on existing content.
3. **Initial import** — add the new field to the bundle's branch in `ImportAggregatorFeedItemAction::createNodeFromXml()` (or, for books, in `mapBookFields()`). This populates the field when new content is selected from the aggregator items list.
4. **Refresh import** — add the matching write in `SharedContentFieldMapper::mapAllFields()`'s per bundle method (`mapBookFields`, `mapFacultyFields`, `mapEventFields`, `mapArticleFields`). Without this, the field stays empty on refresh even if the XML carries a value.

After all four are in place, run `drush sc:force-update --type={bundle}` to backfill existing nodes. Or, for a code only mapper change with no XML change, use `$refresher->remapAll(['book'])` from a quick drush php-eval, which sets `force_remap=TRUE` and skips the hash short circuit.

### Adding a new XML element to an existing mapping

Same as above minus step 1: just edit the action and the field mapper. The source feed needs to emit the element first; check the source side's RSS template before assuming the element will be present.

### Changing how an existing element maps

Edit both `createNodeFromXml()` and the matching `map{Bundle}Fields()` in the field mapper. Then `drush sc:force-update` or `remapAll()` to push the new logic onto existing content.

### Adding a new content type to shared content

1. Add the bundle to the content type → XML slug map in `getContentType()` (action) and `shared_content_get_content_type()` (`shared_content.module`).
2. Attach the five tracking fields listed above to the new bundle.
3. Add a branch to `createNodeFromXml()` for initial import.
4. Add a `case '{bundle}':` and `map{Bundle}Fields()` method to `SharedContentFieldMapper::mapAllFields()`.
5. Add the bundle to the `condition('type', […], 'IN')` arrays in `SharedContentFeedRefresherForce::getEligibleNodeIds()` and `processDeletedNodes()`.
6. Update `shared_content_form_alter()`'s bundle whitelist if the new bundle should get the locked field behavior for non admins.
7. Update the source side to emit `<bundle>` in feed titles so the import action can route items correctly.

### Fixing image or media handling

The image pipeline has parallel implementations in three places — choose the right one for the symptom:

- **Initial import of a new image** → `ImportAggregatorFeedItemAction::mapHeadshot()` / `mapBookCover()` / `mapCv()` and their helpers (`createMediaImage`, `createMediaImageWithType`, `createFileEntity`, etc.).
- **Refresh of an existing image** → `SharedContentFieldMapper::mapHeadshot()` / `mapBookCover()` / `mapCv()` and helpers. This is what runs on cron via `processNode()`.
- **Belt and suspenders refresh** (called from `SharedContentFeedRefresherForce::processNode()` after `mapAllFields()`) → `SharedContentFeedRefresherForce::mapCover()` / `mapHeadshot()` and their local helpers, which write to `covers/` and `headshots/` subdirectories with the local naming scheme.

Dedup conventions to be aware of:

- Field mapper uses **media name suffix dedup**: media name format is `"{title} {type} #{url_hash}"`, checked by `mediaMatchesUrlHash()`.
- Refresher service's local methods use **filename substring dedup**: the url_hash is embedded in the file's filename and checked with `strpos()`.
- Action's `mapHeadshot` uses filename dedup; `mapBookCover` was updated to use name suffix dedup so it agrees with the field mapper.

If you see a media entity getting re downloaded on every refresh, mismatched dedup conventions between two paths is the usual cause.

### Pausing refresh during migrations

```
drush state:set shared_content.migration_pause 1
# do migration work
drush state:delete shared_content.migration_pause
```

`hook_cron()` checks this flag and bails before calling `runChunked()`.

## Services

| Service ID | Class | Purpose |
| --- | --- | --- |
| `shared_content.feed_refresher_force` | `SharedContentFeedRefresherForce` | The cron driven refresh loop. Fetches XML conditionally, delegates field writes to the field mapper, handles 404/empty XML deletion. |
| `shared_content.field_mapper` | `SharedContentFieldMapper` | Translates a `<node>` XML element into Drupal field values. The single source of truth for refresh time mapping. |
| `shared_content.node_deletion_audit` | `NodeDeletionAuditSubscriber` | Records who deleted what shared content node, queryable with `drush sc:audit`. |
| `shared_content.custom_items_import` | `ItemsImporterOverride` | Decorates `aggregator.items.importer` to call `deleteItems()` before re fetching, which keeps the aggregator item list in sync with the remote feed. Paired with `hook_aggregator_feed_insert()` forcing `field_aggregator_purge_items` on. |

## Drush commands

All commands live under `src/Drush/Commands/`. Most have a `--dry-run` flag — use it.

```
# Refresh all shared content from sources. Type optional.
drush sc:force-update
drush sc:force-update --type=book
drush sc:force-update --type=person,event --limit=10 --dry-run

# Find and delete aggregator feeds (and the nodes they imported) by URL or title pattern.
drush sc:feed-delete --url=lasprogram.wustl.edu --dry-run
drush sc:feed-delete --title="Digital Commons"

# Bulk URL rename across all feeds and the field_shared_content_xml values on nodes.
drush sc:feed-update --find=artsci.wustl.edu --replace=artsci.washu.edu --dry-run

# Clean up old events: delete past a max age, switch "current event" alias to "past event".
drush sc:event --max-age=365 --dry-run

# Snapshot per type node counts and warn when subsequent runs detect drops.
drush sc:monitor              # compare against last snapshot
drush sc:monitor --save       # save current as new baseline
drush sc:monitor --threshold=3 --percent=5

# Investigate deletions captured by the audit subscriber.
drush sc:audit
```

## Module hooks of note

| Hook | Behavior |
| --- | --- |
| `hook_form_alter` | On node edit forms for the four bundles, locks most fields against editing when `field_is_shared` is TRUE and the user isn't a node administrator. Editable exceptions are listed in `$editable_fields`. Also adds the "Shared content settings" vertical tab on new nodes. |
| `hook_form_FORM_ID_alter(aggregator_admin_form)` | Removes the "never" option from aggregator cleanup and adds 6 month / 1 year / 5 year / 10 year retention options. |
| `hook_form_FORM_ID_alter(aggregator_feed_form)` | Strips refresh frequency options below 3600 seconds so editors don't expect sub hourly refresh. |
| `hook_toolbar` | Adds the "Shared Content" toolbar link to `/admin/shared-content`. |
| `hook_aggregator_feed_insert` | Forces `field_aggregator_purge_items` on for new feeds. Pairs with `ItemsImporterOverride`. |
| `hook_node_presave` | Recomputes `field_content_hash` when `field_shared_content` changes. Critical: without this, the refresher's hash check never matches and every node gets re saved every cycle. |
| `hook_cron` | Calls `$refresher->runChunked(50)` unless `shared_content.migration_pause` state is set. |
| `hook_entity_predelete` | Records the deletion via `NodeDeletionAuditSubscriber`. |
| `hook_views_data_alter` | Adds `shared_content_site_name`, `shared_content_site_image`, and the `Shared Content XML Source` filter and the `Imported Status` field to aggregator views. |
| `hook_form_FORM_ID_alter(views_exposed_form)` | Converts the aggregator items text filters for `feed` and `author` into select dropdowns populated from the database. |

## State keys

| State key | Purpose |
| --- | --- |
| `shared_content.migration_pause` | When TRUE, `hook_cron()` skips refresh. Use during domain migrations or bulk source side work. |
| `shared_content.cron_offset` | Cursor for `runChunked()` so chunks pick up where the last cron left off. Auto resets after a full pass. |
| `shared_content.active_nodes` | Legacy tracking, kept for external tooling. Deletion detection no longer uses it — `processDeletedNodes()` checks sources via live HTTP. |
| `shared_content.node_count_snapshot` | Baseline counts for `drush sc:monitor`. |
| `shared_content.node_count_snapshot_time` | When that baseline was taken. |

## Common troubleshooting

**Aggregator item shows up but the import action does nothing.** Look at the feed's title — `shared_content_get_content_type()` keyword matches "person", "article", "event", "book" anywhere in the title. If none match, the action's `$fid_test` is empty and the constructed shared URL is malformed, so `sharedContentGetXml()` returns nothing.

**A node keeps getting re saved every cron cycle.** `field_content_hash` is drifting from `hash('sha256', field_shared_content)`. Make sure `hook_node_presave()` is firing — check that all five tracking fields are present on the bundle and that nothing else is writing `field_shared_content` without letting presave run.

**Image styles render the original instead of the styled derivative.** Several causes; check in this order:
  1. Is the file actually saved on disk under `public://shared_content/{type}s/`? If no, the download failed.
  2. Does the file have a focal_point Crop entity? `createCoverEntity()` sets one at center on save. Without it, the focal point preview UI can refuse to render styled derivatives even though the effect code has a transient center fallback.
  3. Watch `Reports → Recent log messages` for `Focal point scale and crop failed while resizing` entries; ImageMagick v6 + webp is the usual culprit.

**Mapper changes don't reach existing nodes.** The refresher's normal path bails when `field_content_hash` matches the source hash. To force the mapper to run regardless, use `$refresher->remapAll(['book'])` or run `drush sc:force-update`, which sends `Cache-Control: no-cache` and bypasses the 304 short circuit.

**Source content was deleted but the local node still exists.** `processDeletedNodes()` only acts when the source has returned 404/410 (or empty XML) **and** the local `field_last_fetch` is more than 7 days stale. If the node was never successfully fetched (no `field_last_fetch` value), it gets deleted on the first 404 detection instead. Confirm by checking `field_last_fetch` and running `drush sc:audit` after the fact.

**An aggregator item won't disappear after import.** The bulk action sets a node row but the aggregator item stays until aggregator's own cleanup runs. The override decorator (`ItemsImporterOverride`) plus the forced `field_aggregator_purge_items` flag handle this on next aggregator refresh, but if you've disabled either, the items just accumulate.

## Local file layout

```
shared_content/
├── README.md                          (this file)
├── shared_content.info.yml
├── shared_content.module              hooks, form alters, content type slug map
├── shared_content.routing.yml         admin routes incl. force update form/controller
├── shared_content.services.yml        wires the services below
├── shared_content.links.{menu,task}.yml
├── shared_content_opml.xml
├── config/
│   ├── install/                       config exported with module
│   └── optional/
└── src/
    ├── Access/                        custom access check for force update route
    ├── Batch/                         batch callbacks
    ├── Controller/                    admin pages
    ├── Drush/Commands/                drush commands (sc:force-update, sc:monitor, …)
    ├── EventSubscriber/               route, controller, RSS filter, deletion audit
    ├── Form/                          RemoteForm, ForceUpdateForm
    ├── ItemsImporterOverride.php      aggregator.items.importer decorator
    ├── Plugin/
    │   ├── Action/
    │   │   ├── CustomEntityCreateAction.php
    │   │   └── ImportAggregatorFeedItemAction.php   ← initial import + per bundle field writes
    │   ├── Block/                     featured shared content block
    │   ├── Field/                     custom views fields
    │   └── views/                     views plugins
    └── Service/
        ├── ServiceOPMLImporter.php
        ├── SharedContentFeedRefresherForce.php       ← cron driven refresh loop
        └── SharedContentFieldMapper.php              ← refresh time XML to field mapper
```
