<?php

namespace App\Scheduler;

use App\Message\SyncCommanderCatalogMessage;
use Symfony\Component\Scheduler\Attribute\AsSchedule;
use Symfony\Component\Scheduler\RecurringMessage;
use Symfony\Component\Scheduler\Schedule;
use Symfony\Component\Scheduler\ScheduleProviderInterface;
use Symfony\Contracts\Cache\CacheInterface;

/**
 * Weekly refresh of every Scryfall-legal commander into the local
 * `commanders` table so typeahead never hits Scryfall at request time.
 *
 * Run the ticker with:
 *   php bin/console messenger:consume scheduler_commanders
 * (the dispatched SyncCommanderCatalogMessage is handled by the `async` worker).
 */
#[AsSchedule('commanders')]
final readonly class CommanderCatalogSchedule implements ScheduleProviderInterface
{
    /** Sunday 06:00 UTC — off the daily TCGCSV/catalog peak. */
    private const WEEKLY_RUN_TIME = 'Sunday 06:00 UTC';

    public function __construct(
        private CacheInterface $cache,
    ) {
    }

    public function getSchedule(): Schedule
    {
        return (new Schedule())
            ->stateful($this->cache)
            ->add(RecurringMessage::every(
                '1 week',
                new SyncCommanderCatalogMessage(),
                from: new \DateTimeImmutable(self::WEEKLY_RUN_TIME),
            ));
    }
}
