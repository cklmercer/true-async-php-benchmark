<?php

declare(strict_types=1);

namespace App\Database;

/**
 * The chat log, addressed by workspace.
 *
 * Every operation takes a workspace id because that is the unit the modelled
 * setup is partitioned on: a tenant with a handful of users in it. The store
 * keeps that partition as an index prefix rather than a separate database.
 */
interface Store
{
    /**
     * Keyset page, newest first. A cursor of 0 means "from the top".
     *
     * @return list<array<string,mixed>>
     */
    public function page(int $workspace, int $cursor, int $limit): array;

    /** Append one message; returns nothing the caller needs. */
    public function append(int $workspace, string $username, string $body, int $at): void;

    /** @return array<string,mixed>|null */
    public function find(int $workspace, int $id): ?array;

    /** Human-readable name for the /stats endpoint. */
    public function driver(): string;
}
