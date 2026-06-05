<?php

declare(strict_types=1);

namespace Coyotito\Ipp\Discovery;

/**
 * Pure-PHP mDNS discovery (no external binary) — the portable strategy that
 * also works on Windows. Sends multicast DNS PTR queries for `_ipp._tcp.local`
 * and `_ipps._tcp.local`, then parses the SRV/TXT/A records in the replies into
 * printer URIs.
 *
 * The DNS parsing ({@see parse()}) is a pure function tested with canned
 * packets; the socket I/O lives behind {@see MdnsExchanger}.
 */
final class MdnsDiscovery implements DiscoveryStrategy
{
    private const array SERVICES = ['_ipp._tcp.local', '_ipps._tcp.local'];

    private const int TYPE_A = 1;

    private const int TYPE_TXT = 16;

    private const int TYPE_SRV = 33;

    private const int QTYPE_PTR = 12;

    public function __construct(
        private readonly MdnsExchanger $exchanger = new SocketMdnsExchanger,
    ) {}

    public function discover(int $timeout = 5): array
    {
        $queries = array_map(self::buildQuery(...), self::SERVICES);

        $printers = [];

        foreach ($this->exchanger->query($queries, $timeout) as $packet) {
            foreach (self::parse($packet) as $printer) {
                $printers[$printer->uri] = $printer;
            }
        }

        return array_values($printers);
    }

    /**
     * Build a one-shot mDNS PTR query for a service, with the QU (unicast
     * response) bit set so printers reply directly to us.
     */
    public static function buildQuery(string $service): string
    {
        $header = pack('n6', 0, 0, 1, 0, 0, 0); // id, flags, qdcount=1, an, ns, ar

        return $header.self::encodeName($service).pack('n', self::QTYPE_PTR).pack('n', 0x8001);
    }

    /**
     * Parse an mDNS response packet into discovered printers.
     *
     * @return array<int,DiscoveredPrinter>
     */
    public static function parse(string $packet): array
    {
        if (strlen($packet) < 12) {
            return [];
        }

        /** @var array{qd:int,an:int,ns:int,ar:int} $header */
        $header = unpack('nid/nflags/nqd/nan/nns/nar', substr($packet, 0, 12));
        $offset = 12;

        // Skip the echoed questions.
        for ($i = 0; $i < $header['qd']; $i++) {
            [, $offset] = self::readName($packet, $offset);
            $offset += 4; // qtype + qclass
        }

        $srv = [];
        $txt = [];
        $records = $header['an'] + $header['ns'] + $header['ar'];

        for ($i = 0; $i < $records; $i++) {
            if ($offset + 10 > strlen($packet)) {
                break;
            }

            [$name, $offset] = self::readName($packet, $offset);

            /** @var array{type:int,class:int,ttl:int,rdlength:int} $rr */
            $rr = unpack('ntype/nclass/Nttl/nrdlength', substr($packet, $offset, 10));
            $offset += 10;
            $rdataStart = $offset;
            $rdata = substr($packet, $offset, $rr['rdlength']);
            $offset += $rr['rdlength'];

            if ($rr['type'] === self::TYPE_SRV && strlen($rdata) >= 7) {
                /** @var array{port:int} $parts */
                $parts = unpack('npriority/nweight/nport', substr($rdata, 0, 6));
                [$target] = self::readName($packet, $rdataStart + 6);
                $srv[$name] = ['port' => $parts['port'], 'target' => $target];
            } elseif ($rr['type'] === self::TYPE_TXT) {
                $txt[$name] = self::parseTxt($rdata);
            }
            // A records (self::TYPE_A) are parsed on demand; .local targets are
            // resolvable by the OS mDNS resolver and keep TLS hostnames intact.
        }

        return self::build($srv, $txt);
    }

    /**
     * @param  array<string,array{port:int,target:string}>  $srv
     * @param  array<string,array<string,string>>  $txt
     * @return array<int,DiscoveredPrinter>
     */
    private static function build(array $srv, array $txt): array
    {
        $printers = [];

        foreach ($srv as $instance => $info) {
            $target = rtrim($info['target'], '.');

            if ($target === '') {
                continue;
            }

            $scheme = str_contains($instance, '_ipps._tcp') ? 'ipps' : 'ipp';
            $path = ltrim($txt[$instance]['rp'] ?? 'ipp/print', '/');
            $uri = "{$scheme}://{$target}:{$info['port']}/{$path}";

            $printers[$uri] = new DiscoveredPrinter($uri, $target, $info['port'], $scheme, self::instanceName($instance));
        }

        return array_values($printers);
    }

    private static function encodeName(string $name): string
    {
        $out = '';

        foreach (explode('.', trim($name, '.')) as $label) {
            $out .= chr(strlen($label)).$label;
        }

        return $out."\x00";
    }

    /**
     * Read a (possibly compressed) DNS name starting at $offset.
     *
     * @return array{0:string,1:int} [name, offset after the name in the main stream]
     */
    private static function readName(string $packet, int $offset): array
    {
        $labels = [];
        $next = null;
        $jumps = 0;
        $length = strlen($packet);

        while ($offset < $length) {
            $byte = ord($packet[$offset]);

            if ($byte === 0) {
                $offset++;
                $next ??= $offset;
                break;
            }

            if (($byte & 0xC0) === 0xC0) { // compression pointer
                if ($offset + 1 >= $length) {
                    break;
                }
                $pointer = (($byte & 0x3F) << 8) | ord($packet[$offset + 1]);
                $next ??= $offset + 2;
                $offset = $pointer;

                if (++$jumps > 128) {
                    break; // guard against pointer loops
                }

                continue;
            }

            $start = $offset + 1;

            if ($start + $byte > $length) {
                break;
            }

            $labels[] = substr($packet, $start, $byte);
            $offset = $start + $byte;
        }

        return [implode('.', $labels), $next ?? $offset];
    }

    /**
     * @return array<string,string>
     */
    private static function parseTxt(string $rdata): array
    {
        $out = [];
        $i = 0;
        $length = strlen($rdata);

        while ($i < $length) {
            $len = ord($rdata[$i]);
            $i++;

            if ($len === 0 || $i + $len > $length) {
                $i += $len;

                continue;
            }

            $entry = substr($rdata, $i, $len);
            $i += $len;

            if (str_contains($entry, '=')) {
                [$key, $value] = explode('=', $entry, 2);
                $out[strtolower($key)] = $value;
            } else {
                $out[strtolower($entry)] = '';
            }
        }

        return $out;
    }

    private static function instanceName(string $instance): ?string
    {
        foreach (['._ipps._tcp', '._ipp._tcp'] as $service) {
            $position = strpos($instance, $service);

            if ($position !== false) {
                return substr($instance, 0, $position);
            }
        }

        return null;
    }
}
