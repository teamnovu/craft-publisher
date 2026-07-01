# Publisher X

Publisher X enables you to publish saved Drafts on a future date.
The cron job handles the publication, and cache invalidation is managed
through Craft CMS's native element save events.

It also handles entries that are set to expire or go live in the future,
and will trigger cache invalidation through Craft's standard element
lifecycle events.

![Screenshot](resources/img/example1.png)

## Requirements

- Craft CMS 5.0+
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
* * * * * [PATH_TO_CRAFT]/craft publisher-x/publish
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
