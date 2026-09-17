<?php

namespace pmwh3\Utils\Web;

/**
 * Web server adapter contract (Apache today, nginx/lighttpd later).
 *
 * The adapter owns the `pmwh3_web_subdomains`-shaped store (schema:
 * subdomain, domain, customer, path, data, alias_of; fpext/traffic
 * are legacy columns -- NEVER read or written by pmwh3).
 *
 * The `data` column carries the server-specific config block for the
 * row. Its format is the server's business (ApacheAdapter renders a
 * vhost), but pmwh3 keeps the surrounding contract server-neutral:
 *
 *   - Row fields (subdomain/domain/customer/path/alias_of) are the
 *     portable facts any backend can read.
 *   - `data` = skeleton + custom section between the
 *     "### START CUSTOM ###" / "### END CUSTOM ###" markers. The
 *     custom section is user-editable and preserved verbatim across
 *     skeleton regeneration, whatever backend it belongs to.
 *
 * Rows with empty `data` are invisible to a vhost-reading backend
 * (e.g. the mod_perl reader loads WHERE data <> '') -- used by the
 * IP-forwarding variant (DNS-only, no local vhost).
 */
interface WebAdapterInterface
{

    // ===== Discovery (for AdapterRegistry / option_providers) ===========

    /** Short identifier persisted in the WEB_TYPE setting. */
    public static function getKey(): string;

    /** Human-readable name shown in the options dropdown. */
    public static function getName(): string;

    /**
     * Runtime availability check. Adapters returning false are filtered
     * out of the dropdown.
     */
    public static function isAvailable(): bool;

    /**
     * Capability flags of THIS adapter:
     *   'subdomain-write'  CRUD on the subdomain rows
     *   'vhost-data'       renders `data` blocks (skeleton + custom)
     *   'subdomain-list'   listByDomain/getRow reads
     *   'ssl'              builds TLS vhost blocks and resolves
     *                      certificates (listCerts/resolveCert active)
     * Adapters return ONLY keys they really support.
     */
    public static function capabilities(): array;

    // ===== Reads ========================================================

    /** True if a row with this exact FQDN exists. */
    public static function rowExists(string $fqdn): bool;

    /** The full row for one FQDN or null. */
    public static function getRow(string $fqdn): ?array;

    /**
     * All rows whose subdomain is the apex itself, ends with
     * ".<domain>" or points (alias_of) into that domain.
     */
    public static function listByDomain(string $domain): array;

    // ===== Writes =======================================================

    /**
     * Insert one row. Expected keys: subdomain (FQDN), domain,
     * customer, path, alias_of (nullable), data (nullable).
     * Legacy columns (fpext/traffic) are never touched.
     */
    public static function createSubdomain(array $row): bool;

    /**
     * Update one row identified by its current FQDN. Fields subset:
     * subdomain (rename), path, data, alias_of.
     */
    public static function updateSubdomain(string $oldFqdn, array $fields): bool;

    /** Remove one row by FQDN. Idempotent. */
    public static function deleteSubdomain(string $fqdn): bool;

    // ===== vhost data ===================================================

    /**
     * Render the `data` block for one row. Input keys:
     *   subdomain  FQDN of the row
     *   domain     parent domain
     *   customer   owner name
     *   path       document root (directory variant)
     *   alias_of   FQDN of the target when this row is an alias
     *   custom     custom config section (rendered between markers;
     *              empty = adapter's configured snippet)
     *   ssl_cert   ''|null = http only; otherwise certificate BASENAME
     *              (without extension, per WEB_SSL_DIR naming:
     *              <name>.pem/.key, wildcard = '_.domain.tld') -- the
     *              adapter renders a :443 vhost wrapping/behind the
     *              http one. When the adapters declares the 'ssl'
     *              capability.
     */
    public static function buildVhostData(array $args): string;

    // ===== certificates =================================================
    // Adapters without the 'ssl' capability return the no-ops.

    /**
     * Available certificate basenames (without .pem/.key) from
     * WEB_SSL_DIR (shared volume, visible to pmwh3 AND the web
     * container). Basis for the UI picker and the
     * auto-resolution. Empty array when the directory is not
     * readable or the adapter has no ssl support.
     */
    public static function listCerts(): array;

    /**
     * Resolve a certificate for one FQDN: exact name first, then
     * wildcard files ('_.<rest>' masking the leading label, applied
     * iteratively until the apex is reached). Returns the basename
     * (no extension) or null when nothing matches.
     */
    public static function resolveCert(string $fqdn): ?string;

    /**
     * Extract the custom section (between the markers) from a data
     * block. Returns '' when the block has no markers.
     */
    public static function extractCustom(string $data): string;

    /**
     * Regenerate the skeleton around the existing custom section of
     * one row (identity/path/alias changes) and write it back.
     * Returns false when the row doesn't exist or the adapter can't
     * render data.
     */
    public static function regenerateVhostData(string $fqdn): bool;
}
