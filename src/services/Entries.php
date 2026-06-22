<?php

namespace neustadt\publisher\services;

use Craft;
use craft\base\Component;
use craft\elements\Entry;
use neustadt\publisher\elements\EntryPublish;
use neustadt\publisher\events\EntryPublishedEvent;

/**
 * Class Entries
 *
 * @package neustadt\publisher\services
 */
class Entries extends Component
{
    /**
     * Fired after the plugin successfully publishes a scheduled entry or applies
     * a scheduled draft. The event carries the canonical entry, the draft (if
     * applicable), and the EntryPublish schedule record.
     *
     * @see EntryPublishedEvent
     */
    const EVENT_AFTER_PUBLISH_ENTRY = 'afterPublishEntry';

    /**
     * @var \DateTime
     */
    protected $now;

    public function __construct(array $config = [])
    {
        parent::__construct($config);

        $this->now = new \DateTime('now', new \DateTimeZone(Craft::$app->getTimeZone()));
    }

    /**
     * Set the time for the check of the publishAt date.
     *
     * @param \DateTime $date
     */
    public function setNow(\DateTime $date): void
    {
        $this->now = $date;
    }

    /**
     * Publishes or expires the due entries.
     *
     * @return bool
     * @throws \Throwable
     */
    public function publishDueEntries(): bool
    {
        $publishEntries = EntryPublish::find()->publishAt($this->now)->all();

        /** @var EntryPublish $entryPublish */
        foreach ($publishEntries as $entryPublish) {
            $entry = $entryPublish->getEntry();
            $draft = $entryPublish->getDraft();

            $success = false;

            if ($draft === null && $entry === null) {
                Craft::error('Entry and draft not found for EntryPublish ' . $entryPublish->id . ', removing orphaned record.', 'publisher-x');
                Craft::$app->elements->deleteElement($entryPublish, true);
                continue;
            }

            if ($draft !== null) {
                try {
                    Craft::$app->getDrafts()->applyDraft($draft);
                    $success = true;
                    Craft::info('Successfully published draft ' . $draft->draftId . ' for entry ' . $entry?->id, 'publisher-x');
                } catch (\Throwable $e) {
                    Craft::error('Could not apply draft ' . $draft->draftId . ' while publishing: ' . $e->getMessage(), 'publisher-x');
                }
            } elseif ($entry !== null) {
                try {
                    Craft::$app->elements->saveElement($entry);
                    $success = true;
                    Craft::info('Successfully saved entry ' . $entry->id . ' while publishing', 'publisher-x');
                } catch (\Throwable $e) {
                    Craft::error('Could not save element ' . $entry->id . ' while publishing: ' . $e->getMessage(), 'publisher-x');
                }
            }

            // Only delete the scheduled publish entry if the operation was successful
            if ($success) {
                Craft::$app->elements->deleteElement($entryPublish, true);

                if ($entry !== null && $this->hasEventHandlers(self::EVENT_AFTER_PUBLISH_ENTRY)) {
                    $event = new EntryPublishedEvent();
                    $event->entry = $entry;
                    $event->draft = $draft;
                    $event->entryPublish = $entryPublish;
                    $this->trigger(self::EVENT_AFTER_PUBLISH_ENTRY, $event);
                }
            }
        }

        return true;
    }

    /**
     * Returns all the pending entries for the entry with the ID.
     *
     * @param int $id
     * @return array
     */
    public function getPendingEntries(int $id): array
    {
        // returns results for all sites
        $query = EntryPublish::find()->sourceId($id);

        return $query->all();
    }

    /**
     * Returns the EntryPublish with the ID.
     *
     * @param int $id
     * @return EntryPublish|null
     */
    public function getEntryPublishById(int $id): ?EntryPublish
    {
        $query = EntryPublish::find()->id($id);
        /** @var EntryPublish|null $result */
        $result = $query->one();

        return $result;
    }

    /**
     * Saves the EntryPublish
     *
     * @param EntryPublish $model
     * @return bool
     * @throws \Throwable
     * @throws \yii\db\Exception
     */
    public function saveEntryPublish(EntryPublish $model): bool
    {
        $dbService = Craft::$app->getDb();

        if ($model->id) {
            $isNew = false;
            $record = \neustadt\publisher\records\EntryPublish::findOne($model->id);
        } else {
            $isNew = true;
            $record = new \neustadt\publisher\records\EntryPublish();
        }

        $record->sourceId = $model->sourceId;
        $record->sourceSiteId = $model->sourceSiteId;
        $record->publishDraftId = $model->publishDraftId;
        $record->publishAt = $model->publishAt;
        $record->expire = $model->expire;

        $record->validate();
        $model->addErrors($record->getErrors());

        if (!$model->hasErrors()) {
            $transaction = $dbService->beginTransaction();

            try {
                if (Craft::$app->elements->saveElement($model, false)) {
                    if ($isNew) {
                        $record->id = $model->id;
                    }

                    $record->save(false);

                    $transaction->commit();

                    return true;
                }

                $transaction->rollBack();
            } catch (\Exception $e) {
                $transaction->rollBack();

                throw $e;
            }
        }

        return false;
    }

    /**
     * Deletes the EntryPublish with the ID.
     *
     * @param int $id
     * @return bool
     * @throws \Throwable
     */
    public function deleteEntryPublish(int $id): bool
    {
        return Craft::$app->elements->deleteElementById($id, null, null, true);
    }

    /**
     * Will be executed when an entry gets saved
     * and checks if the postDate is in the future or the
     * expiryDate is set.
     *
     * @param Entry $entry
     * @return bool
     * @throws \Throwable
     * @throws \yii\db\Exception
     */
    public function onSaveEntry(Entry $entry): bool
    {
        $postDate = $entry->postDate;
        $expiryDate = $entry->expiryDate;

        if ($postDate > $this->now) {
            $model = new EntryPublish();
            $model->sourceId = $entry->id;
            $model->sourceSiteId = $entry->siteId;
            $model->publishAt = $postDate;
            $model->expire = false;

            $this->clearExistingPublishings($model);

            $this->saveEntryPublish($model);
        }

        if ($expiryDate !== null && $expiryDate > $this->now) {
            $model = new EntryPublish();
            $model->sourceId = $entry->id;
            $model->sourceSiteId = $entry->siteId;
            $model->publishAt = $expiryDate;
            $model->expire = true;

            $this->clearExistingUnpublishings($model);

            $this->saveEntryPublish($model);
        }

        return true;
    }

    /**
     * Clears all the existing publishing EntryPublishes for the entry.
     *
     * @param EntryPublish $model
     * @throws \Throwable
     */
    protected function clearExistingPublishings(EntryPublish $model): void
    {
        if (!$model->sourceId) {
            return;
        }

        $elements = EntryPublish::find()->sourceId($model->sourceId)->expire(false)->all();

        /** @var EntryPublish $element */
        foreach ($elements as $element) {
            if ($element->publishDraftId === null) {
                Craft::$app->elements->deleteElement($element, true);
            }
        }
    }

    /**
     * Clears all the existing unpublishing EntryPublishes for the entry.
     *
     * @param EntryPublish $model
     * @throws \Throwable
     */
    protected function clearExistingUnpublishings(EntryPublish $model): void
    {
        if (!$model->sourceId) {
            return;
        }

        $elements = EntryPublish::find()->sourceId($model->sourceId)->expire(true)->all();

        /** @var EntryPublish $element */
        foreach ($elements as $element) {
            # this if is not really needed, as drafts can't be unpublished
            if ($element->publishDraftId === null) {
                Craft::$app->elements->deleteElement($element, true);
            }
        }
    }
}
