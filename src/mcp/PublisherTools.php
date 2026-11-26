<?php
/**
 * @link      https://novu.ch
 * @copyright Copyright (c) Novu
 */

namespace teamnovu\publisher\mcp;

use Craft;
use craft\elements\db\EntryQuery;
use craft\elements\Entry;
use craft\elements\User;
use craft\helpers\DateTimeHelper;
use craft\mcp\attributes\RequiresPermission;
use craft\mcp\McpActor;
use CraftCms\Cms\Mcp\Attributes\RequiresPermission as RequiresPermissionCraft6;
use CraftCms\Cms\Mcp\McpActor as McpActorCraft6;
use craft\models\Site;
use DateTime;
use DateTimeInterface;
use DateTimeZone;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Mcp\Exception\ToolCallException;
use Mcp\Schema\ToolAnnotations;
use teamnovu\publisher\elements\db\EntryPublishQuery;
use teamnovu\publisher\elements\db\EntryPublishQueryCraft6;
use teamnovu\publisher\elements\EntryPublish;
use teamnovu\publisher\Publisher;
use Throwable;

/**
 * Publisher X tools for Craft's MCP server (Craft 5.12+ and Craft 6).
 *
 * Only referenced when Craft's MCP classes exist, see Publisher::registerMcpCapabilities().
 */
class PublisherTools
{
    /**
     * A saved draft is applied to its entry at the publish date.
     */
    public const TYPE_DRAFT = 'draft';

    /**
     * The entry goes live at its post date. Publisher X re-saves it then.
     */
    public const TYPE_POST_DATE = 'post_date';

    /**
     * The entry expires at its expiry date. Publisher X re-saves it then.
     */
    public const TYPE_EXPIRY = 'expiry';

    /**
     * Most schedules one list call returns.
     */
    public const MAX_SCHEDULES = 200;

    private const DATE_PATTERN = '/^\d{4}-\d{2}-\d{2}(?:[ T]\d{2}:\d{2}(?::\d{2}(?:\.\d+)?)?)?(?:Z|[+-]\d{2}:?\d{2})?$/';

    private const SITE_SCHEMA = [
        'type' => ['string', 'integer', 'null'],
        'description' => 'Site handle (e.g. "de"), language (e.g. "en") or numeric site ID.',
    ];

    /**
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'publisher_x_schedules_list',
        title: 'Publisher X: scheduled publications',
        description: 'Lists what Publisher X will publish, earliest first. Each schedule has a type: "draft" (a saved draft is applied to the live entry at publishAt), "post_date" (the entry goes live at its post date) or "expiry" (the entry expires at its expiry date). Only "draft" schedules can be moved or cancelled with the Publisher X tools. Dates are in the system time zone returned as timeZone. "overdue" means publishAt has passed but the publisher-x/publish cron job has not handled it yet. Filter by date range (e.g. from/to for "this week"), entry, site or type.',
        annotations: new ToolAnnotations(readOnlyHint: true),
    )]
    #[RequiresPermission('accessCp')]
    #[RequiresPermissionCraft6('accessCp')]
    public function schedulesList(
        #[Schema(description: 'Earliest publish date, e.g. "2026-11-23" or "2026-11-23T08:00" (system time zone if no offset is given). Leave out to include overdue schedules.')]
        ?string $from = null,
        #[Schema(description: 'Latest publish date, inclusive. A date without a time means the end of that day.')]
        ?string $to = null,
        #[Schema(description: 'Only the schedules of this entry (its canonical ID, not a draft ID).', minimum: 1)]
        ?int $entryId = null,
        #[Schema(definition: self::SITE_SCHEMA)]
        string|int|null $site = null,
        #[Schema(definition: [
            'type' => ['string', 'null'],
            'enum' => [self::TYPE_DRAFT, self::TYPE_POST_DATE, self::TYPE_EXPIRY, null],
            'description' => 'Only schedules of this type.',
        ])]
        ?string $type = null,
        #[Schema(description: 'Most schedules to return, at most 200.', minimum: 1, maximum: self::MAX_SCHEDULES)]
        int $limit = 50,
    ): array {
        $user = $this->user();
        $now = $this->now();
        $fromDate = $this->isBlank($from) ? null : $this->parseDate((string)$from, 'from');
        $toDate = $this->isBlank($to) ? null : $this->parseDate((string)$to, 'to', endOfDay: true);

        if ($fromDate && $toDate && $fromDate > $toDate) {
            throw new ToolCallException('from must be before to.');
        }

        if ($type !== null && !in_array($type, [self::TYPE_DRAFT, self::TYPE_POST_DATE, self::TYPE_EXPIRY], true)) {
            throw new ToolCallException('Unknown type "' . $type . '". Use draft, post_date or expiry.');
        }

        $limit = max(1, min(self::MAX_SCHEDULES, $limit));
        $query = $this->scheduleQuery();

        if ($entryId !== null) {
            $query->sourceId($entryId);
        }

        if (!$this->isBlank($site)) {
            $query->sourceSiteId((int)$this->resolveSite($site, 'site')->id);
        }

        $elements = Craft::$app->getElements();
        $schedules = [];
        $total = 0;

        foreach ($this->sortSchedules($query->all()) as $schedule) {
            $publishAt = $this->toDateTime($schedule->publishAt);

            if (
                !$publishAt
                || ($fromDate && $publishAt < $fromDate)
                || ($toDate && $publishAt > $toDate)
                || ($type !== null && $this->scheduleType($schedule) !== $type)
            ) {
                continue;
            }

            $entry = $schedule->getEntry();

            if (!$entry || !$elements->canView($entry, $user)) {
                continue;
            }

            $total++;

            if (count($schedules) < $limit) {
                $schedules[] = $this->describeSchedule($schedule, $user, $now);
            }
        }

        $result = [
            'now' => $this->formatDate($now),
            'timeZone' => $this->timeZone()->getName(),
            'count' => count($schedules),
            'total' => $total,
            'schedules' => $schedules,
        ];

        if ($total > count($schedules)) {
            $result['note'] = sprintf('%d more schedules match. Raise limit or narrow the date range.', $total - count($schedules));
        }

        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'publisher_x_entry_drafts',
        title: 'Publisher X: drafts of an entry',
        description: 'Shows the saved drafts of an entry on one site, the publish dates already scheduled for each draft, and the entry\'s other schedules (post date, expiry). Use it to find the draft to pass to publisher_x_schedule_draft. Find the entry ID first with Craft\'s own tools, such as entries_list or elements_list.',
        annotations: new ToolAnnotations(readOnlyHint: true),
    )]
    #[RequiresPermission('accessCp')]
    #[RequiresPermissionCraft6('accessCp')]
    public function entryDrafts(
        #[Schema(description: 'ID of the entry (its canonical ID, not a draft ID).', minimum: 1)]
        int $entryId,
        #[Schema(definition: ['description' => 'Site of the entry. Defaults to the primary site, or the first site the entry exists on.'] + self::SITE_SCHEMA)]
        string|int|null $site = null,
    ): array {
        $user = $this->user();
        $now = $this->now();
        $siteModel = $this->isBlank($site) ? null : $this->resolveSite($site, 'site');
        $entry = $this->findEntry($entryId, $siteModel, $user);
        $elements = Craft::$app->getElements();

        $schedulesByDraftId = [];
        $otherSchedules = [];
        $query = $this->scheduleQuery()->sourceId((int)$entry->id)->sourceSiteId((int)$entry->siteId);

        foreach ($this->sortSchedules($query->all()) as $schedule) {
            if ($this->scheduleType($schedule) === self::TYPE_DRAFT) {
                $schedulesByDraftId[(int)$schedule->publishDraftId][] = [
                    'scheduleId' => (int)$schedule->id,
                    'publishAt' => $this->formatOptionalDate($schedule->publishAt),
                    'overdue' => $this->isOverdue($schedule, $now),
                ];
            } else {
                $otherSchedules[] = $this->describeSchedule($schedule, $user, $now, withEntry: false);
            }
        }

        $drafts = Entry::find()
            ->draftOf($entry)
            ->siteId((int)$entry->siteId)
            ->status(null)
            ->orderBy(['elements.dateUpdated' => SORT_DESC])
            ->all();
        $described = [];

        foreach ($drafts as $draft) {
            if (!$elements->canView($draft, $user)) {
                continue;
            }

            $described[] = $this->describeDraft($draft) + [
                'schedules' => $schedulesByDraftId[(int)$draft->draftId] ?? [],
            ];
            unset($schedulesByDraftId[(int)$draft->draftId]);
        }

        $result = [
            'now' => $this->formatDate($now),
            'timeZone' => $this->timeZone()->getName(),
            'entry' => $this->describeEntry($entry),
            'site' => $this->describeSite($entry->getSite()),
            'canPublish' => $elements->canSave($entry, $user),
            'drafts' => $described,
            'otherSchedules' => $otherSchedules,
        ];

        if ($schedulesByDraftId) {
            $result['note'] = 'Some scheduled drafts are not listed: you cannot view them or they were deleted. See publisher_x_schedules_list.';
        }

        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'publisher_x_schedule_draft',
        title: 'Publisher X: schedule or move a draft',
        description: 'Schedules a saved draft to be applied to its live entry at publishAt, or moves an existing draft schedule to a new date. Give either draftElementId (the draft\'s element "id" from publisher_x_entry_drafts or drafts_list, not its "draftId") or scheduleId (from publisher_x_schedules_list). A draft that is already scheduled on that site is moved, not scheduled twice. publishAt must be in the future; without an offset it is read in the system time zone. Tell the user the exact date, time and time zone and wait for their confirmation before calling this tool.',
        annotations: new ToolAnnotations(readOnlyHint: false, destructiveHint: true, idempotentHint: true, openWorldHint: false),
        meta: ['anthropic/requiresUserInteraction' => true],
    )]
    #[RequiresPermission('accessCp')]
    #[RequiresPermissionCraft6('accessCp')]
    public function scheduleDraft(
        #[Schema(description: 'When to publish, e.g. "2026-11-23T08:00" (system time zone) or "2026-11-23T08:00:00+01:00".')]
        string $publishAt,
        #[Schema(description: 'Element ID of the draft to schedule (its "id", not its "draftId").', minimum: 1)]
        ?int $draftElementId = null,
        #[Schema(description: 'ID of the draft schedule to move, from publisher_x_schedules_list.', minimum: 1)]
        ?int $scheduleId = null,
        #[Schema(definition: ['description' => 'Site of the draft, used with draftElementId. Defaults to the primary site, or the first site the draft exists on.'] + self::SITE_SCHEMA)]
        string|int|null $site = null,
    ): array {
        $user = $this->user();

        if (($draftElementId === null) === ($scheduleId === null)) {
            throw new ToolCallException('Give either draftElementId or scheduleId.');
        }

        $date = $this->parseDate($publishAt, 'publishAt', requireTime: true);
        $now = $this->now();

        if ($date <= $now) {
            throw new ToolCallException(sprintf(
                'publishAt must be in the future. It is now %s (%s).',
                $this->formatDate($now),
                $this->timeZone()->getName()
            ));
        }

        if ($scheduleId !== null) {
            $schedule = $this->findSchedule($scheduleId, $user);

            if ($this->scheduleType($schedule) !== self::TYPE_DRAFT) {
                throw new ToolCallException($this->notDraftScheduleMessage($schedule));
            }

            $draft = $schedule->getDraft();

            if (!$draft) {
                throw new ToolCallException(sprintf('The draft of schedule %d no longer exists. Cancel the schedule with publisher_x_schedule_cancel.', $scheduleId));
            }

            $this->assertCanPublishDraft($draft, $user);
            $existing = [$schedule];
        } else {
            $draft = $this->findDraft((int)$draftElementId, $this->isBlank($site) ? null : $this->resolveSite($site, 'site'));
            $this->assertCanPublishDraft($draft, $user);

            $query = $this->scheduleQuery()
                ->sourceId((int)$draft->getCanonicalId())
                ->sourceSiteId((int)$draft->siteId);
            $query->publishDraftId = (int)$draft->draftId;
            $existing = $this->sortSchedules($query->all());

            if (count($existing) > 1) {
                throw new ToolCallException(sprintf(
                    'Draft %d is scheduled %d times on the site "%s" (schedule IDs %s). Pass scheduleId to move one of them and cancel the others with publisher_x_schedule_cancel.',
                    $draft->id,
                    count($existing),
                    $draft->getSite()->handle,
                    implode(', ', array_map(fn(EntryPublish $schedule) => $schedule->id, $existing))
                ));
            }
        }

        $previousPublishAt = null;

        if ($existing) {
            $schedule = $existing[0];
            $previousPublishAt = $this->formatOptionalDate($schedule->publishAt);
            $schedule->publishAt = $date;
            $action = 'moved';
        } else {
            $schedule = new EntryPublish();
            $schedule->sourceId = (int)$draft->getCanonicalId();
            $schedule->sourceSiteId = (int)$draft->siteId;
            $schedule->publishDraftId = (int)$draft->draftId;
            $schedule->publishAt = $date;
            $schedule->expire = false;
            $action = 'scheduled';
        }

        if (!Publisher::getInstance()->entries->saveEntryPublish($schedule)) {
            throw new ToolCallException('The schedule could not be saved: ' . implode(' ', $schedule->getErrorSummary(true)));
        }

        return [
            'action' => $action,
            'previousPublishAt' => $previousPublishAt,
            'schedule' => $this->describeSchedule($schedule, $user, $now),
            'timeZone' => $this->timeZone()->getName(),
            'note' => 'The publisher-x/publish cron job applies the draft on its first run after publishAt, so it must run every minute.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'publisher_x_schedule_cancel',
        title: 'Publisher X: cancel a scheduled draft',
        description: 'Cancels a scheduled draft so it is not applied to the live entry. The draft is kept unless deleteDraft is true, which deletes it permanently together with all its schedules. Only "draft" schedules can be cancelled: "post_date" and "expiry" schedules follow the entry\'s own post date and expiry date, so change those dates on the entry instead (e.g. with elements_update). Confirm with the user before calling this tool.',
        annotations: new ToolAnnotations(readOnlyHint: false, destructiveHint: true, idempotentHint: false, openWorldHint: false),
        meta: ['anthropic/requiresUserInteraction' => true],
    )]
    #[RequiresPermission('accessCp')]
    #[RequiresPermissionCraft6('accessCp')]
    public function scheduleCancel(
        #[Schema(description: 'ID of the schedule, from publisher_x_schedules_list or publisher_x_entry_drafts.', minimum: 1)]
        int $scheduleId,
        #[Schema(description: 'Also delete the draft permanently. Only when the user asks to drop the draft too.')]
        bool $deleteDraft = false,
    ): array {
        $user = $this->user();
        $now = $this->now();
        $elements = Craft::$app->getElements();
        $schedule = $this->findSchedule($scheduleId, $user);

        if ($this->scheduleType($schedule) !== self::TYPE_DRAFT) {
            throw new ToolCallException($this->notDraftScheduleMessage($schedule));
        }

        $draft = $schedule->getDraft();

        if ($draft) {
            $this->assertCanPublishDraft($draft, $user);

            if ($deleteDraft && !$elements->canDelete($draft, $user)) {
                throw new ToolCallException(sprintf('You are not allowed to delete draft %d. Nothing was changed.', $draft->id));
            }
        } elseif (!$elements->canSave($schedule->getEntry(), $user)) {
            throw new ToolCallException(sprintf('You are not allowed to change the schedules of entry %d.', $schedule->sourceId));
        }

        $described = $this->describeSchedule($schedule, $user, $now);
        $entries = Publisher::getInstance()->entries;

        if (!$entries->deleteEntryPublish((int)$schedule->id)) {
            throw new ToolCallException(sprintf('Schedule %d could not be cancelled.', $scheduleId));
        }

        $result = [
            'cancelled' => $described,
            'draftDeleted' => false,
        ];

        if ($deleteDraft && $draft) {
            // The draft's other schedules (e.g. on other sites) would apply nothing once it is gone.
            $query = $this->scheduleQuery();
            $query->publishDraftId = (int)$draft->draftId;
            $alsoCancelled = [];

            foreach ($query->all() as $other) {
                if ($entries->deleteEntryPublish((int)$other->id)) {
                    $alsoCancelled[] = (int)$other->id;
                }
            }

            // Like "Delete draft" in the control panel.
            if (!$elements->deleteElement($draft, true)) {
                throw new ToolCallException(sprintf('Schedule %d was cancelled, but draft %d could not be deleted.', $scheduleId, $draft->id));
            }

            $result['draftDeleted'] = true;

            if ($alsoCancelled) {
                $result['alsoCancelledScheduleIds'] = $alsoCancelled;
            }
        } elseif ($deleteDraft) {
            $result['note'] = 'The draft no longer existed.';
        }

        return $result;
    }

    // Private Methods
    // =========================================================================

    private function user(): User
    {
        if (class_exists(McpActorCraft6::class)) {
            return app(McpActorCraft6::class)->user()->asElement();
        }

        return (new McpActor())->user();
    }

    private function scheduleQuery(): EntryPublishQuery|EntryPublishQueryCraft6
    {
        /** @var EntryPublishQuery|EntryPublishQueryCraft6 $query */
        $query = EntryPublish::find();

        // Schedules are not localized; this also finds them when the request runs on another site.
        $query->site('*')->unique()->status(null);

        return $query;
    }

    /**
     * A schedule the user may see, or a ToolCallException.
     */
    private function findSchedule(int $scheduleId, User $user): EntryPublish
    {
        /** @var EntryPublish|null $schedule */
        $schedule = $this->scheduleQuery()->id($scheduleId)->one();
        $entry = $schedule?->getEntry();

        if (!$schedule || !$entry || !Craft::$app->getElements()->canView($entry, $user)) {
            throw new ToolCallException(sprintf('No Publisher X schedule with ID %d. It may have been published or cancelled already: see publisher_x_schedules_list.', $scheduleId));
        }

        return $schedule;
    }

    private function findEntry(int $entryId, ?Site $site, User $user): Entry
    {
        $query = Entry::find()->id($entryId)->status(null);
        $this->applySite($query, $site);
        $entry = $query->one();

        if (!$entry) {
            $draft = Entry::find()->id($entryId)->drafts()->provisionalDrafts(null)->site('*')->status(null)->one();

            if ($draft && !$draft->getIsUnpublishedDraft()) {
                throw new ToolCallException(sprintf('%d is a draft of entry %d. Pass the entry ID %d.', $entryId, $draft->getCanonicalId(), $draft->getCanonicalId()));
            }

            throw new ToolCallException(sprintf('No entry with ID %d%s.', $entryId, $site ? sprintf(' on the site "%s"', $site->handle) : ''));
        }

        if (!Craft::$app->getElements()->canView($entry, $user)) {
            throw new ToolCallException(sprintf('You are not allowed to view entry %d on the site "%s".', $entryId, $entry->getSite()->handle));
        }

        return $entry;
    }

    private function findDraft(int $draftElementId, ?Site $site): Entry
    {
        $query = Entry::find()->id($draftElementId)->drafts()->provisionalDrafts(null)->status(null);
        $this->applySite($query, $site);
        $draft = $query->one();

        if (!$draft) {
            if (Entry::find()->id($draftElementId)->site('*')->status(null)->exists()) {
                throw new ToolCallException(sprintf('%d is an entry, not a draft. List its drafts with publisher_x_entry_drafts and pass the draft\'s draftElementId.', $draftElementId));
            }

            throw new ToolCallException(sprintf(
                'No draft with element ID %d%s. Pass the draft\'s element "id", not its "draftId".',
                $draftElementId,
                $site ? sprintf(' on the site "%s"', $site->handle) : ''
            ));
        }

        if ($draft->isProvisionalDraft) {
            throw new ToolCallException(sprintf('%d holds someone\'s unsaved changes, not a saved draft. Save it as a draft first.', $draftElementId));
        }

        if ($draft->getIsUnpublishedDraft()) {
            throw new ToolCallException(sprintf('Draft %d is a new entry that was never published. Publisher X schedules drafts of existing entries; to publish a new entry later, set its post date.', $draftElementId));
        }

        return $draft;
    }

    /**
     * Prefers the primary site when no site is given.
     */
    private function applySite(EntryQuery $query, ?Site $site): void
    {
        if ($site) {
            $query->siteId((int)$site->id);

            return;
        }

        $query->site('*')->unique()->preferSites([(int)Craft::$app->getSites()->getPrimarySite()->id]);
    }

    /**
     * The checks Craft's drafts_apply tool makes: applying a draft saves its canonical entry.
     */
    private function assertCanPublishDraft(Entry $draft, User $user): void
    {
        $elements = Craft::$app->getElements();

        if (!$elements->canView($draft, $user) || !$elements->canSave($draft, $user) || !$elements->canSaveCanonical($draft, $user)) {
            throw new ToolCallException(sprintf(
                'You are not allowed to publish draft %d of entry %d on the site "%s", so you cannot schedule it or cancel its schedule.',
                $draft->id,
                $draft->getCanonicalId(),
                $draft->getSite()->handle
            ));
        }
    }

    private function scheduleType(EntryPublish $schedule): string
    {
        if ($schedule->publishDraftId !== null) {
            return self::TYPE_DRAFT;
        }

        return $schedule->expire ? self::TYPE_EXPIRY : self::TYPE_POST_DATE;
    }

    private function notDraftScheduleMessage(EntryPublish $schedule): string
    {
        if ($this->scheduleType($schedule) === self::TYPE_EXPIRY) {
            return sprintf(
                'Schedule %d follows the expiry date of entry %d: Craft expires the entry at that date and Publisher X only re-saves it then, so caches are refreshed. To cancel or move the expiry, change or clear the entry\'s expiry date (e.g. with elements_update).',
                $schedule->id,
                $schedule->sourceId
            );
        }

        return sprintf(
            'Schedule %d follows the post date of entry %d: Craft makes the entry live at its post date and Publisher X only re-saves it then, so caches are refreshed. Cancelling the schedule would not stop the publication. To cancel or move it, change the entry\'s post date or disable the entry (e.g. with elements_update).',
            $schedule->id,
            $schedule->sourceId
        );
    }

    /**
     * @param EntryPublish[] $schedules
     * @return EntryPublish[]
     */
    private function sortSchedules(array $schedules): array
    {
        usort($schedules, function(EntryPublish $a, EntryPublish $b) {
            $aTime = $this->toDateTime($a->publishAt)?->getTimestamp() ?? PHP_INT_MAX;
            $bTime = $this->toDateTime($b->publishAt)?->getTimestamp() ?? PHP_INT_MAX;

            return [$aTime, (int)$a->id] <=> [$bTime, (int)$b->id];
        });

        return $schedules;
    }

    private function isOverdue(EntryPublish $schedule, DateTime $now): bool
    {
        $publishAt = $this->toDateTime($schedule->publishAt);

        return $publishAt !== null && $publishAt <= $now;
    }

    /**
     * @return array<string, mixed>
     */
    private function describeSchedule(EntryPublish $schedule, User $user, DateTime $now, bool $withEntry = true): array
    {
        $type = $this->scheduleType($schedule);
        $described = [
            'scheduleId' => (int)$schedule->id,
            'type' => $type,
            'action' => match ($type) {
                self::TYPE_DRAFT => 'The draft is applied to the live entry.',
                self::TYPE_EXPIRY => 'The entry expires (its expiry date).',
                default => 'The entry goes live (its post date).',
            },
            'publishAt' => $this->formatOptionalDate($schedule->publishAt),
            'overdue' => $this->isOverdue($schedule, $now),
            'cancellable' => $type === self::TYPE_DRAFT,
        ];

        if ($withEntry) {
            $entry = $schedule->getEntry();
            $described['entry'] = $entry ? $this->describeEntry($entry) : ['id' => (int)$schedule->sourceId];
            $described['site'] = $schedule->sourceSiteId ? $this->describeSiteById((int)$schedule->sourceSiteId) : null;
        }

        if ($type === self::TYPE_DRAFT) {
            $draft = $schedule->getDraft();

            if ($draft && Craft::$app->getElements()->canView($draft, $user)) {
                $described['draft'] = $this->describeDraft($draft);
            } else {
                $described['draft'] = [
                    'draftId' => (int)$schedule->publishDraftId,
                    'note' => $draft ? 'You cannot view this draft.' : 'The draft no longer exists.',
                ];
            }
        }

        return $described;
    }

    /**
     * @return array<string, mixed>
     */
    private function describeEntry(Entry $entry): array
    {
        return [
            'id' => (int)$entry->id,
            'title' => (string)$entry->title,
            'section' => $entry->getSection()?->handle,
            'status' => $entry->getStatus(),
            'postDate' => $this->formatOptionalDate($entry->postDate),
            'expiryDate' => $this->formatOptionalDate($entry->expiryDate),
            'cpEditUrl' => $entry->getCpEditUrl(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function describeDraft(Entry $draft): array
    {
        $behavior = $draft->getBehavior('draft');
        // Craft 6 keeps the draft data on the element, not in a behavior.
        $creatorId = $behavior->creatorId ?? $draft->draftCreatorId ?? null;
        $creator = $creatorId ? Craft::$app->getUsers()->getUserById((int)$creatorId) : null;

        return [
            'draftElementId' => (int)$draft->id,
            'draftId' => (int)$draft->draftId,
            'name' => $behavior->draftName ?? $draft->draftName ?? null,
            'notes' => ($behavior->draftNotes ?? $draft->draftNotes ?? null) ?: null,
            'creator' => $creator?->getName(),
            'dateUpdated' => $this->formatOptionalDate($draft->dateUpdated),
            'cpEditUrl' => $draft->getCpEditUrl(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function describeSite(Site $site): array
    {
        return [
            'id' => (int)$site->id,
            'handle' => $site->handle,
            'name' => $site->getName(),
            'language' => $site->language,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function describeSiteById(int $siteId): array
    {
        $site = Craft::$app->getSites()->getSiteById($siteId, true);

        return $site ? $this->describeSite($site) : ['id' => $siteId];
    }

    private function resolveSite(string|int $value, string $argument): Site
    {
        $sites = Craft::$app->getSites();
        $needle = trim((string)$value);
        $site = null;

        if ($needle !== '' && ctype_digit($needle)) {
            $site = $sites->getSiteById((int)$needle);
        }

        if (!$site && $needle !== '') {
            $allSites = $sites->getAllSites();
            $lower = strtolower($needle);

            foreach ($allSites as $candidate) {
                if (strtolower($candidate->handle) === $lower) {
                    $site = $candidate;
                    break;
                }
            }

            if (!$site) {
                // Fall back to the language, e.g. "en" for "en-US", if exactly one site matches.
                $matches = array_values(array_filter($allSites, function(Site $candidate) use ($lower) {
                    $language = strtolower($candidate->language);

                    return $language === $lower || explode('-', $language)[0] === $lower;
                }));

                if (count($matches) === 1) {
                    $site = $matches[0];
                }
            }
        }

        if (!$site) {
            throw new ToolCallException(sprintf(
                'Unknown site "%s" in %s. Sites: %s.',
                $needle,
                $argument,
                implode(', ', array_map(
                    fn(Site $site) => sprintf('%s (%s, %s, ID %d)', $site->handle, $site->getName(), $site->language, $site->id),
                    $sites->getAllSites()
                ))
            ));
        }

        return $site;
    }

    private function isBlank(mixed $value): bool
    {
        return $value === null || trim((string)$value) === '';
    }

    private function timeZone(): DateTimeZone
    {
        return new DateTimeZone(Craft::$app->getTimeZone());
    }

    private function now(): DateTime
    {
        return new DateTime('now', $this->timeZone());
    }

    /**
     * Parses a date given to a tool. Without an offset it is read in the system time zone.
     */
    private function parseDate(string $value, string $argument, bool $endOfDay = false, bool $requireTime = false): DateTime
    {
        $value = trim($value);
        $hasTime = (bool)preg_match('/^\d{4}-\d{2}-\d{2}[ T]/', $value);
        $error = sprintf(
            '%s must be %s, e.g. "2026-11-23T08:00" (read in the system time zone, %s) or "2026-11-23T08:00:00+01:00". Got "%s".',
            $argument,
            $requireTime ? 'a date and time' : 'a date or a date and time',
            $this->timeZone()->getName(),
            $value
        );

        if (!preg_match(self::DATE_PATTERN, $value) || ($requireTime && !$hasTime)) {
            throw new ToolCallException($error);
        }

        try {
            $date = new DateTime($value, $this->timeZone());
        } catch (Throwable) {
            throw new ToolCallException($error);
        }

        $errors = DateTime::getLastErrors();

        // PHP rolls over impossible dates such as 2026-02-31 with a warning.
        if ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0)) {
            throw new ToolCallException(sprintf('%s is not a valid date: "%s".', $argument, $value));
        }

        if (!$hasTime) {
            $endOfDay ? $date->setTime(23, 59, 59) : $date->setTime(0, 0);
        }

        return $date->setTimezone($this->timeZone());
    }

    private function toDateTime(mixed $value): ?DateTime
    {
        if ($value === null || $value === '') {
            return null;
        }

        // Values read from the database are UTC.
        $date = $value instanceof DateTimeInterface ? DateTime::createFromInterface($value) : DateTimeHelper::toDateTime($value);

        return $date ?: null;
    }

    private function formatDate(DateTimeInterface $date): string
    {
        return DateTime::createFromInterface($date)->setTimezone($this->timeZone())->format(DateTimeInterface::ATOM);
    }

    private function formatOptionalDate(mixed $value): ?string
    {
        $date = $this->toDateTime($value);

        return $date ? $this->formatDate($date) : null;
    }
}
