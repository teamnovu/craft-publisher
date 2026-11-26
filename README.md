# Publisher X

Publisher X enables you to publish saved Drafts on a future date.
The cron job handles the publication, and cache invalidation is managed
through Craft CMS's native element save events.

It also handles entries that are set to expire or go live in the future,
and will trigger cache invalidation through Craft's standard element
lifecycle events.

![Screenshot](resources/img/example1.png)

## Requirements

- Craft CMS 5.0+, or Craft CMS 6 with `craftcms/yii2-adapter`
- PHP 8.2+

## Installation

```shell
composer require teamnovu/craft-publisher
```

Then install the plugin in the Craft control panel under **Settings → Plugins**.

## Permissions

To publish a draft, the user needs the **Save entries** permission on the entry's section.

## Setup

Create a cron job that runs **every minute**. You can invoke Publisher X via CLI or HTTP:

**CLI (recommended):**

```shell
# Craft 5
* * * * * [PATH_TO_CRAFT]/craft publisher-x/publish

# Craft 6 (the slash form still works but is deprecated)
* * * * * php [PATH_TO_CRAFT]/artisan craft:publisher-x:publish
```

**Web:**

```shell
* * * * * /usr/bin/curl --silent --compressed {siteUrl}/actions/publisher-x/api/publish
```

### Usage with cache plugins

If you use a full-page cache plugin such as Blitz, make sure you also run the queue and refresh expired caches:

```shell
* * * * * [PATH_TO_CRAFT]/craft blitz/cache/refresh-expired
* * * * * [PATH_TO_CRAFT]/craft queue/run
```

## MCP (Craft 5.12+ and Craft 6)

On Craft 5.12 and later, and on Craft 6, Publisher X adds tools to Craft's built-in MCP server, so an AI client can answer "What's scheduled to publish this week?" or "Move that draft to next Monday 08:00". Enable the server under **Settings → MCP**; nothing changes on earlier Craft versions.

| Tool | Scope | What it does |
| --- | --- | --- |
| `publisher_x_schedules_list` | read | Lists the schedules (draft, post date, expiry), filtered by date range, entry, site or type. |
| `publisher_x_entry_drafts` | read | Lists an entry's saved drafts on a site and their schedules. |
| `publisher_x_schedule_draft` | write | Schedules a draft, or moves a draft's schedule to a new date. |
| `publisher_x_schedule_cancel` | write | Cancels a draft's schedule and, on request, deletes the draft. |

All tools require control panel access and the same permissions as Craft's own draft tools: to schedule or cancel a draft, the user must be allowed to save the draft and its entry on that site. Dates without an offset are read in the system time zone. Post date and expiry schedules follow the entry's own dates, so the tools list them but do not change them; change the entry's dates instead (e.g. with `elements_update`).

The draft is applied by the cron job (see [Setup](#setup)), so it must keep running every minute.

## Events

### `EVENT_AFTER_PUBLISH_ENTRY`

Fired by the `Entries` service after each successful scheduled publication. Use it to trigger server-side cache purges or any other side-effect tied to publication.

```php

Event::on(
    Entries::class,
    Entries::EVENT_AFTER_PUBLISH_ENTRY,
    function (EntryPublishedEvent $event) {
        $entry = $event->entry;        // canonical entry that was published
        $draft = $event->draft;        // draft that was applied, or null for non-draft schedules
        $schedule = $event->entryPublish; // the EntryPublish schedule record

        // trigger your cache purge here
    }
);
```

The event is only fired when publication succeeds. If the scheduled entry or draft cannot be found (e.g. the entry was deleted), the orphaned record is cleaned up and the event is not fired. 
