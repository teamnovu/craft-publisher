<?php

namespace neustadt\publisher\events;

use craft\elements\Entry;
use neustadt\publisher\elements\EntryPublish;
use yii\base\Event;

/**
 * Fired by the Publisher X plugin after it successfully publishes a scheduled
 * entry or applies a scheduled draft. Listen to this event to trigger cache
 * purges or any other side-effects tied to publication.
 *
 * Example usage in a module/plugin:
 *
 *     use neustadt\publisher\services\Entries;
 *     use neustadt\publisher\events\EntryPublishedEvent;
 *     use yii\base\Event;
 *
 *     Event::on(
 *         Entries::class,
 *         Entries::EVENT_AFTER_PUBLISH_ENTRY,
 *         function (EntryPublishedEvent $event) {
 *             // $event->entry  — the canonical entry that was published
 *             // $event->draft  — the draft that was applied (null for non-draft publishes)
 *         }
 *     );
 */
class EntryPublishedEvent extends Event
{
    /**
     * The canonical entry that was published.
     */
    public Entry $entry;

    /**
     * The draft that was applied, or null when the entry itself was saved
     * (i.e. no draft was involved in this scheduled publish).
     */
    public ?Entry $draft = null;

    /**
     * The EntryPublish schedule record that triggered this publication.
     */
    public EntryPublish $entryPublish;
}
