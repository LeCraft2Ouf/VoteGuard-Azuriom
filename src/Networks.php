<?php

namespace Azuriom\Plugin\VoteGuard;

use Illuminate\Http\Request;

final class Networks
{
    /**
     * Hébergeurs / clouds / VPN datacenter. Pas les FAI résidentiels ni Cloudflare WARP (13335).
     */
    private const HOSTING = [
        16276, // OVH
        24940, 213230, // Hetzner
        51167, 40021, 141995, // Contabo
        14061, // DigitalOcean
        16509, 14618, // Amazon AWS
        396982, 15169, 19527, // Google Cloud
        8075, // Microsoft Azure
        63949, // Akamai / Linode
        20473, // Vultr
        12876, // Scaleway
        197540, // netcup
        60781, 28753, // LeaseWeb
        9009, // M247
        212238, 60068, // Datacamp / CDN77
        31898, // Oracle Cloud
        45102, // Alibaba Cloud
        132203, // Tencent Cloud
        36352, // ColoCrossing
        53667, // FranTech / BuyVM
        62240, // Clouvider
        50673, // Serverius
        202425, // IP Volume
        43350, // NForce
        49981, // WorldStream
    ];

    public static function asn(Request $request): ?int
    {
        $value = trim((string) $request->header('X-Client-ASN', ''));

        return ctype_digit($value) && $value !== '0' ? (int) $value : null;
    }

    public static function country(Request $request): ?string
    {
        $value = strtoupper(trim((string) $request->header('CF-IPCountry', '')));

        return preg_match('/^[A-Z]{2}$/', $value) === 1 && $value !== 'XX' ? $value : null;
    }

    public static function isHosting(?int $asn): bool
    {
        return $asn !== null && in_array($asn, self::HOSTING, true);
    }
}
