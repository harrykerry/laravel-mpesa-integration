<?php

namespace App\Support;

use App\Exceptions\MpesaException;

/**
 * Checks a URL against the hosts this application may call.
 *
 * Outbound traffic carries credentials and payment data, so the host is
 * checked before a request is made rather than trusted from configuration.
 */
class HostGuard
{
    /**
     * Whether the URL's host is on the outbound allowlist.
     *
     * An empty allowlist permits nothing: the list is the whole point.
     */
    public static function isAllowed(string $url): bool
    {
        $host = parse_url($url, PHP_URL_HOST);

        if (!is_string($host) || $host === '') {
            return false;
        }

        foreach (config('mpesa.security.allowed_outbound_hosts', []) as $allowed) {
            $allowed = strtolower(trim((string) $allowed));
            $host = strtolower($host);

            // A leading dot allows any subdomain: ".example.com".
            if ($allowed === $host || (str_starts_with($allowed, '.') && str_ends_with($host, $allowed))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Refuse to continue when the URL's host is not allowlisted.
     *
     * @throws MpesaException
     */
    public static function assertAllowed(string $url): void
    {
        if (!self::isAllowed($url)) {
            throw MpesaException::disallowedHost((string) (parse_url($url, PHP_URL_HOST) ?: $url));
        }
    }
}
