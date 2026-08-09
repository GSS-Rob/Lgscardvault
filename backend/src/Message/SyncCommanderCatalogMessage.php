<?php

namespace App\Message;

/**
 * Async weekly refresh of the local commanders catalog from Scryfall
 * (`is:commander`). Dispatched by CommanderCatalogSchedule (and the CLI).
 */
final readonly class SyncCommanderCatalogMessage
{
}
