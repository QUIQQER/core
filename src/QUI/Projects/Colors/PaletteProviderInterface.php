<?php

declare(strict_types=1);

namespace QUI\Projects\Colors;

use QUI\Projects\Project;

/**
 * Prepared contract for ticket 07. Discovery and applying sets are deliberately not implemented here.
 * Providers return immutable package definitions, never the mutable project palette.
 */
interface PaletteProviderInterface
{
    /** Package name, e.g. quiqqer/template-presentation. */
    public function getProviderId(): string;

    /** Local set ID, or null when the provider has no recommended default. */
    public function getDefaultPaletteId(Project $Project): ?string;

    /**
     * Names are locale descriptors. Additional roles must use template:<provider>:<role> IDs.
     * Sets contain only concrete values, never references. Missing Core roles use Colors::defaults().
     *
     * @return list<array{
     *     id: string,
     *     name: array{group: string, var: string},
     *     roles: array<string, array{name: array{group: string, var: string}, displayGroup?: string}>,
     *     colors: array<string, array{value?: string, light?: string, dark?: string}>
     * }>
     */
    public function getPalettes(Project $Project): array;
}
