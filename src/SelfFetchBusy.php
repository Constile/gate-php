<?php

declare(strict_types=1);

namespace Constile\Gate;

/** Every self-fetch slot is taken. The gate passes the page through instead (the agent gets the page, no offer). */
final class SelfFetchBusy extends \RuntimeException
{
}
