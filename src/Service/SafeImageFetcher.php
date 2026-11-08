<?php

namespace App\Service;

use App\Exception\ImageRejectedException;
use App\Exception\SsrfBlockedException;
use Symfony\Component\HttpClient\NoPrivateNetworkHttpClient;
use Symfony\Component\HttpFoundation\IpUtils;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpClientException;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * "Import image from URL" with SSRF protection (rule 8).
 *
 * Threat: the server fetches a URL chosen by the user. Without checks an attacker can make it request
 * http://127.0.0.1/..., the router admin page, or the cloud metadata service http://169.254.169.254/
 * (which hands out cloud credentials), from INSIDE the network.
 *
 * Defenses, in order:
 *  1. https only, port 443 only, no "user:pass@" in the URL.
 *  2. Resolve the host (A + AAAA). EVERY resolved address must be public; one private address = refused.
 *  3. The connection is PINNED to the address we validated (curl "resolve"), so DNS rebinding (public IP at
 *     check time, 127.0.0.1 at connect time) doesn't work.
 *  4. No redirects (a public URL could 302 to an internal one).
 *  5. 5 s timeout, 2 MB max (streamed, aborted as soon as it is exceeded).
 *  6. Defense in depth: the HTTP client is wrapped in Symfony's NoPrivateNetworkHttpClient, which re-checks
 *     the IP actually connected to.
 *  7. The downloaded bytes then go through exactly the same validation as an upload (ImageStorage).
 */
final class SafeImageFetcher
{
    private const TIMEOUT = 5.0;
    private const MAX_URL_LENGTH = 2048;

    /**
     * Blocked ranges: Symfony's list (RFC1918, loopback 127/8, link-local 169.254/16 incl. cloud metadata,
     * CGNAT, ::1, fc00::/7, fe80::/10, IPv4-mapped IPv6, 6to4, Teredo...) plus multicast/broadcast and NAT64.
     */
    private const EXTRA_BLOCKED = [
        '224.0.0.0/4',        // multicast
        '255.255.255.255/32', // broadcast
        '192.0.0.0/24',       // IETF protocol assignments
        'ff00::/8',           // IPv6 multicast
        '64:ff9b::/96',       // NAT64: could map to an internal IPv4
        '2001:db8::/32',      // documentation
    ];

    private readonly HttpClientInterface $client;

    public function __construct(
        HttpClientInterface $httpClient,
        private readonly ImageStorage $storage,
    ) {
        // SECURITY: defense in depth; this wrapper refuses any connection to a private IP even if a check above fails.
        $this->client = new NoPrivateNetworkHttpClient($httpClient);
    }

    /**
     * @return string the stored filename
     *
     * @throws SsrfBlockedException   URL refused by the SSRF checks (to be audit-logged)
     * @throws ImageRejectedException download failed or the content isn't an acceptable image
     */
    public function fetchAndStore(string $url): string
    {
        [$host, $ip] = $this->validateUrl(trim($url));

        $tmp = tempnam(sys_get_temp_dir(), 'imgimport');
        if (false === $tmp) {
            throw new ImageRejectedException('Temporary storage unavailable.');
        }

        try {
            $this->download(trim($url), $host, $ip, $tmp);

            // SECURITY: the same validation and re-encoding as a normal upload (rule 7).
            return $this->storage->validateAndStore($tmp);
        } finally {
            @unlink($tmp);
        }
    }

    /**
     * @return array{0: string, 1: string} [host, validated IP to connect to]
     */
    public function validateUrl(string $url): array
    {
        if ('' === $url || strlen($url) > self::MAX_URL_LENGTH || 1 === preg_match('/[\x00-\x20\x7F]/', $url)) {
            throw new SsrfBlockedException('malformed_url', 'Please enter a valid https:// image URL.');
        }

        $parts = parse_url($url);
        if (false === $parts || !isset($parts['scheme'], $parts['host'])) {
            throw new SsrfBlockedException('malformed_url', 'Please enter a valid https:// image URL.');
        }

        // SECURITY: https only. Blocks http://, file://, gopher://, ftp://, dict://, php://...
        if ('https' !== strtolower($parts['scheme'])) {
            throw new SsrfBlockedException('scheme_not_https', 'Only https:// URLs are allowed.');
        }
        // SECURITY: credentials in URLs are a classic parser-confusion trick (https://good.com@evil.com).
        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new SsrfBlockedException('userinfo_in_url');
        }
        // SECURITY: standard port only, otherwise the feature becomes a port scanner (https://host:22).
        if (isset($parts['port']) && 443 !== $parts['port']) {
            throw new SsrfBlockedException('non_standard_port');
        }

        $host = strtolower(trim($parts['host'], '[]'));

        // Literal IP in the URL (https://127.0.0.1/, https://[::1]/).
        if (false !== filter_var($host, \FILTER_VALIDATE_IP)) {
            $this->assertPublicIp($host);

            return [$host, $host];
        }

        // SECURITY: a hostname must look like one. Rejects decimal/octal/hex IP tricks (https://2130706433/,
        // https://0x7f.1/) that some resolvers turn into 127.0.0.1.
        if (1 !== preg_match('/^(?=.{1,253}$)(?!-)[a-z0-9-]{1,63}(?<!-)(\.(?!-)[a-z0-9-]{1,63}(?<!-))*\.[a-z]{2,63}$/', $host)) {
            throw new SsrfBlockedException('invalid_hostname');
        }

        $ips = $this->resolve($host);
        if ([] === $ips) {
            throw new SsrfBlockedException('dns_resolution_failed', 'This host could not be resolved.');
        }

        // SECURITY: ALL addresses must be public, otherwise the client could pick the private one.
        foreach ($ips as $ip) {
            $this->assertPublicIp($ip);
        }

        return [$host, $ips[0]];
    }

    public static function isPublicIp(string $ip): bool
    {
        if (false === filter_var($ip, \FILTER_VALIDATE_IP)) {
            return false;
        }

        return !IpUtils::checkIp($ip, array_merge(IpUtils::PRIVATE_SUBNETS, self::EXTRA_BLOCKED));
    }

    private function assertPublicIp(string $ip): void
    {
        if (!self::isPublicIp($ip)) {
            throw new SsrfBlockedException('private_ip:'.$ip);
        }
    }

    /**
     * @return list<string>
     */
    private function resolve(string $host): array
    {
        $ips = [];
        $records = @dns_get_record($host, \DNS_A | \DNS_AAAA);
        foreach (false === $records ? [] : $records as $record) {
            if (isset($record['ip'])) {
                $ips[] = $record['ip'];
            } elseif (isset($record['ipv6'])) {
                $ips[] = $record['ipv6'];
            }
        }
        if ([] === $ips) {
            $v4 = @gethostbynamel($host);
            $ips = false === $v4 ? [] : $v4;
        }

        return array_values(array_unique($ips));
    }

    private function download(string $url, string $host, string $ip, string $tmp): void
    {
        $handle = fopen($tmp, 'wb');
        if (false === $handle) {
            throw new ImageRejectedException('Temporary storage unavailable.');
        }

        try {
            $response = $this->client->request('GET', $url, [
                // SECURITY: connect to the validated IP only (anti DNS rebinding). TLS is still checked
                // against the hostname, so the certificate must be valid for $host.
                'resolve' => [$host => $ip],
                // SECURITY: no redirects at all.
                'max_redirects' => 0,
                'timeout' => self::TIMEOUT,
                'max_duration' => self::TIMEOUT,
                'verify_peer' => true,
                'verify_host' => true,
                'headers' => ['Accept' => 'image/jpeg, image/png, image/webp', 'User-Agent' => 'SecureMarketplace-ImageImport/1.0'],
            ]);

            if (200 !== $response->getStatusCode()) {
                // 3xx included: redirects are refused.
                throw new ImageRejectedException('The URL did not return an image (HTTP '.$response->getStatusCode().').');
            }

            $declared = $response->getHeaders(false)['content-length'][0] ?? null;
            if (null !== $declared && (int) $declared > ImageStorage::MAX_BYTES) {
                throw new ImageRejectedException('The image must be smaller than 2 MB.');
            }

            // SECURITY: streamed with a hard cap; a lying Content-Length or an endless stream is cut at 2 MB.
            $received = 0;
            foreach ($this->client->stream($response, self::TIMEOUT) as $chunk) {
                if ($chunk->isTimeout()) {
                    $response->cancel();
                    throw new ImageRejectedException('The download took too long.');
                }
                $received += strlen($chunk->getContent());
                if ($received > ImageStorage::MAX_BYTES) {
                    $response->cancel();
                    throw new ImageRejectedException('The image must be smaller than 2 MB.');
                }
                fwrite($handle, $chunk->getContent());
            }
        } catch (HttpClientException $e) {
            // NoPrivateNetworkHttpClient refusals land here too.
            // NoPrivateNetworkHttpClient message format: 'IP|Host "x" is blocked for "url".'
            if (str_contains($e->getMessage(), '" is blocked for "')) {
                throw new SsrfBlockedException('client_blocked_private_network');
            }
            throw new ImageRejectedException('The image could not be downloaded.');
        } finally {
            fclose($handle);
        }
    }
}
