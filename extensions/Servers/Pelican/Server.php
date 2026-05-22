<?php

/*
|--------------------------------------------------------------------------
| Pelican Panel — WemX Server Extension
|--------------------------------------------------------------------------
|
| Integration für https://pelican.dev als Nachfolger von Pterodactyl.
| Schwerpunkt: Multi-Port-Support für Gameserver (z.B. Source-Games,
| Voice + Query + RCON, Minecraft + dynmap, etc.).
|
| Wie wird die Anzahl extra Ports festgelegt?
|   Das Egg definiert eine Variable namens "WEMX_EXTRA_PORTS"
|   (env_variable). Ihr Default-Value (numerisch) bestimmt, wie viele
|   *zusätzlich* zur primären Allocation reserviert werden.
|   Fehlt die Variable → 0 extra Ports.
|
| Was passiert, wenn auf dem Node nicht genug freie Allocations sind?
|   Wenn "Auto-Create Allocations" aktiviert ist, legt WemX automatisch
|   neue Allocations auf dem Node an (innerhalb der konfigurierten
|   Port-Range). Andernfalls wirft die Extension einen klaren Fehler.
|
| Autor: Nova EDV (https://nova-edv.de)
| Lizenz: MIT
*/

namespace Extensions\Servers\Pelican;

use App\Extensions\Foundation\ServerExtension;
use App\Models\Order;
use App\Models\Package;
use App\Models\PackagePrice;
use App\Models\ServerConnection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class Server extends ServerExtension
{
    protected string $id = 'server-pelican';
    protected string $name = 'Pelican Panel';
    protected string $description = 'Pelican panel integration with native multi-port support for game servers';
    protected string $type = 'Server';
    protected string $version = '1.0.0';
    protected array $wemxVersions = ['1.0.0'];
    protected array $authors = [
        ['name' => 'Nova EDV', 'email' => 'info@nova-edv.de'],
    ];

    /**
     * Name der Egg-Variable, die die Anzahl zusätzlicher Allocations vorgibt.
     * Eggs, die mehr als einen Port brauchen (z.B. Source-Games), tragen
     * diese Variable als Standard-Env-Var ein.
     */
    private const EGG_EXTRA_PORTS_VAR = 'WEMX_EXTRA_PORTS';

    /** Maximal-Anzahl zusätzlicher Ports, die ein einzelnes Egg verlangen darf. */
    private const MAX_EXTRA_PORTS = 16;

    /** Wie lange Egg-Definitionen gecacht werden (Sekunden). */
    private const EGG_CACHE_TTL = 3600;

    public function __construct(
        protected ?ServerConnection $connection = null,
        protected ?Order $order = null,
    ) {
        parent::__construct();
    }

    public function providers(): array { return []; }
    public function elements(): array  { return []; }
    public function setSettingsFields(): array { return []; }

    /* ===================================================================
     | Connection-Konfiguration (im Admin: Server → Connection anlegen)
     * =================================================================== */

    public function setConfig(): array
    {
        $noTrailingSlash = function ($attribute, $value, $fail) {
            if (preg_match('/\/$/', $value)) {
                $fail('Hostname URL darf nicht mit "/" enden, z.B. https://panel.example.com');
            }
        };

        return [
            [
                'key'           => 'hostname',
                'name'          => 'Hostname',
                'description'   => 'URL deines Pelican Panels, z.B. https://panel.example.com',
                'type'          => 'url',
                'default_value' => 'https://panel.example.com',
                'rules'         => ['required', 'active_url', $noTrailingSlash],
            ],
            [
                'key'         => 'api_key',
                'name'        => 'Application API Key',
                'description' => 'Application-API-Token aus Pelican (Admin → Application API).',
                'type'        => 'password',
                'rules'       => ['required', 'string', 'min:16'],
            ],
            [
                'key'           => 'auto_create_allocations',
                'name'          => 'Allocations automatisch anlegen',
                'description'   => 'Wenn auf dem Node nicht genug freie Allocations sind, wird automatisch im Port-Range darunter eine neue erzeugt.',
                'type'          => 'select',
                'options'       => ['1' => 'Ja', '0' => 'Nein'],
                'default_value' => '1',
                'rules'         => ['required', 'in:0,1'],
            ],
            [
                'key'           => 'auto_alloc_port_min',
                'name'          => 'Auto-Allocation Port-Min',
                'description'   => 'Untergrenze des Port-Bereichs für automatisch erzeugte Allocations.',
                'type'          => 'number',
                'default_value' => 25500,
                'rules'         => ['required', 'numeric', 'min:1024', 'max:65535'],
            ],
            [
                'key'           => 'auto_alloc_port_max',
                'name'          => 'Auto-Allocation Port-Max',
                'description'   => 'Obergrenze des Port-Bereichs für automatisch erzeugte Allocations.',
                'type'          => 'number',
                'default_value' => 25700,
                'rules'         => ['required', 'numeric', 'min:1024', 'max:65535'],
            ],
            [
                'key'           => 'send_account_email',
                'name'          => 'Login-Daten per E-Mail senden',
                'description'   => 'Schickt dem User die generierten Pelican-Login-Daten.',
                'type'          => 'select',
                'options'       => ['1' => 'Ja', '0' => 'Nein'],
                'default_value' => '1',
                'rules'         => ['required', 'in:0,1'],
            ],
            [
                'key'           => 'debug_mode',
                'name'          => 'Debug-Modus',
                'description'   => 'API-Fehler werden ins Log geschrieben. Im Produktivbetrieb deaktivieren.',
                'type'          => 'select',
                'options'       => ['0' => 'Aus', '1' => 'An'],
                'default_value' => '0',
                'rules'         => ['required', 'in:0,1'],
            ],
        ];
    }

    /* ===================================================================
     | Package-Konfiguration (im Admin: pro Package konfigurierbar)
     * =================================================================== */

    public function setPackageConfig(Package $package, ServerConnection $connection): array
    {
        $config = [
            [
                'key'         => 'location_id',
                'name'        => 'Location ID',
                'col'         => 'col-6',
                'description' => 'Pelican-Location, in der der Server deployed wird.',
                'type'        => 'text',
                'rules'       => ['required', 'numeric'],
                'is_configurable' => true,
            ],
            [
                'key'         => 'egg_id',
                'name'        => 'Egg ID',
                'col'         => 'col-6',
                'description' => 'Pelican-Egg, das verwendet wird (z.B. 4 für Paper-Minecraft).',
                'type'        => 'text',
                'rules'       => ['required', 'numeric'],
                'is_configurable' => false,
            ],
        ];

        try {
            $eggId = (int) ($package->data('egg_id') ?? 0);
            if ($eggId <= 0) {
                return $config; // Egg noch nicht gewählt — Default-Felder zeigen
            }

            $egg = $this->fetchEgg($connection, $eggId);

            // Limits-Block
            $config = array_merge($config, $this->limitFields());

            // Docker-Image + Startup aus dem Egg vorbelegt
            $config[] = [
                'col'           => 'col-12',
                'key'           => 'docker_image',
                'name'          => 'Docker Image',
                'description'   => 'Docker-Image für diesen Server.',
                'type'          => 'text',
                'default_value' => data_get($egg, 'docker_image'),
                'rules'         => ['required'],
                'is_configurable' => false,
            ];
            $config[] = [
                'col'           => 'col-12',
                'key'           => 'startup',
                'name'          => 'Startup-Befehl',
                'description'   => 'Wird beim Start des Containers ausgeführt.',
                'type'          => 'textarea',
                'default_value' => data_get($egg, 'startup'),
                'rules'         => ['required'],
                'is_configurable' => false,
            ];

            // Anzahl Extra-Ports aus Egg lesen — als read-only Info anzeigen
            $extra = $this->extractExtraPortsFromEgg($egg);
            $config[] = [
                'col'           => 'col-12',
                'key'           => '_info_extra_ports',
                'name'          => 'Extra-Ports laut Egg',
                'description'   => sprintf(
                    'Dieses Egg deklariert %d zusätzliche Allocations via "%s". Insgesamt %d Port(s) je Server.',
                    $extra,
                    self::EGG_EXTRA_PORTS_VAR,
                    1 + $extra
                ),
                'type'          => 'text',
                'default_value' => (string) $extra,
                'rules'         => ['nullable'],
                'is_configurable' => false,
            ];

            // Egg-Variablen ans Package hängen
            foreach (data_get($egg, 'relationships.variables.data', []) as $var) {
                $attr = $var['attributes'] ?? [];
                $envName = $attr['env_variable'] ?? null;
                if (!$envName) continue;

                // Die Marker-Variable selbst nicht als sichtbares Konfig-Feld anzeigen
                if (strcasecmp($envName, self::EGG_EXTRA_PORTS_VAR) === 0) {
                    continue;
                }

                $rules = $attr['rules'] ?? [];
                if (is_string($rules)) {
                    $rules = explode('|', $rules);
                }

                $config[] = [
                    'col'           => 'col-4',
                    'key'           => 'environment.' . $envName,
                    'name'          => $attr['name'] ?? $envName,
                    'description'   => $attr['description'] ?? '',
                    'type'          => 'text',
                    'default_value' => $attr['default_value'] ?? '',
                    'rules'         => $rules ?: ['nullable'],
                    'is_configurable' => (bool) ($attr['user_editable'] ?? true),
                ];
            }
        } catch (\Throwable $e) {
            // Bei Fehler: Default-Config zurückgeben — der Admin sieht den Fehler im Egg-ID-Feld
            $this->debugLog($connection, 'setPackageConfig failed', ['error' => $e->getMessage()]);
            return $config;
        }

        return $config;
    }

    private function limitFields(): array
    {
        return [
            ['col'=>'col-4','key'=>'memory_limit','name'=>'RAM (GB)','description'=>'Speicherlimit. 0 = unlimitiert.','type'=>'number','default_value'=>2,'rules'=>['required','numeric','min:0','max:256'],'is_configurable'=>true],
            ['col'=>'col-4','key'=>'disk_limit','name'=>'Disk (GB)','description'=>'Plattenlimit. 0 = unlimitiert.','type'=>'number','default_value'=>10,'rules'=>['required','numeric','min:0','max:4096'],'is_configurable'=>true],
            ['col'=>'col-4','key'=>'cpu_limit','name'=>'CPU (%)','description'=>'100 = ein Kern, 400 = vier Kerne. 0 = unlimitiert.','type'=>'number','default_value'=>100,'rules'=>['required','numeric','min:0','max:10000'],'is_configurable'=>true],
            ['col'=>'col-4','key'=>'swap_limit','name'=>'Swap (GB)','description'=>'-1 = unlimitiert, 0 = aus.','type'=>'number','default_value'=>0,'rules'=>['required','numeric','min:-1','max:128'],'is_configurable'=>false],
            ['col'=>'col-4','key'=>'io_weight','name'=>'Block-IO-Weight','description'=>'10–1000, Default 500.','type'=>'number','default_value'=>500,'rules'=>['required','numeric','min:10','max:1000'],'is_configurable'=>false],
            ['col'=>'col-4','key'=>'database_limit','name'=>'Datenbanken','description'=>'Wie viele DBs der User erstellen darf.','type'=>'number','default_value'=>1,'rules'=>['required','numeric','min:0','max:50'],'is_configurable'=>true],
            ['col'=>'col-4','key'=>'backup_limit','name'=>'Backups','description'=>'Wie viele Backups der User behalten darf.','type'=>'number','default_value'=>3,'rules'=>['required','numeric','min:0','max:100'],'is_configurable'=>true],
            ['col'=>'col-4','key'=>'user_allocation_limit','name'=>'User-Allocations','description'=>'Wie viele *zusätzliche* Allocations der User selbst hinzubuchen darf (über die Egg-Default-Ports hinaus).','type'=>'number','default_value'=>0,'rules'=>['required','numeric','min:0','max:50'],'is_configurable'=>true],
            ['col'=>'col-4','key'=>'cpu_pinning','name'=>'CPU-Pinning (optional)','description'=>'Z.B. "0,1" oder "0-3". Leer = alle Threads.','type'=>'text','rules'=>['nullable'],'is_configurable'=>false],
        ];
    }

    public function setCheckoutConfig(Package $package): array { return []; }

    /* ===================================================================
     | Lifecycle: create / suspend / unsuspend / terminate / upgrade
     * =================================================================== */

    public function create(Order $order, ServerConnection $connection)
    {
        $package = $order->package;
        $config  = $connection->config;
        $eggId   = (int) $package->data('egg_id');

        if ($eggId <= 0) {
            throw new \RuntimeException('Egg-ID ist im Package nicht gesetzt.');
        }

        // 1) User auf Pelican finden/anlegen
        $pelicanUserId = $this->getOrCreateUser($order, $connection);

        // 2) Egg laden → Anzahl Extra-Ports bestimmen
        $egg = $this->fetchEgg($connection, $eggId);
        $extraPorts = $this->extractExtraPortsFromEgg($egg);

        // 3) Limits aufbereiten (Pelican erwartet MiB)
        $diskMb   = $this->gbToMb((int) $order->option('disk_limit', $package->data('disk_limit', 0)));
        $memoryMb = $this->gbToMb((int) $order->option('memory_limit', $package->data('memory_limit', 0)));
        $swapMb   = (int) $order->option('swap_limit', $package->data('swap_limit', 0));
        $swapMb   = $swapMb > 0 ? $swapMb * 1024 : $swapMb; // -1 und 0 unverändert
        $cpu      = (int) $order->option('cpu_limit', $package->data('cpu_limit', 100));

        // 4) Node + 1+N Allocations finden
        $locationId  = (int) $order->option('location_id', $package->data('location_id', 0));
        $allocations = $this->findNodeAndAllocations(
            connection: $connection,
            locationId: $locationId,
            diskMb: $diskMb,
            memoryMb: $memoryMb,
            extraAllocations: $extraPorts,
        );

        $primaryAllocation     = $allocations['primary'];
        $additionalAllocations = $allocations['additional'];

        // 5) Environment zusammenstellen — Pelican erwartet alle Egg-Variablen
        $environment = $this->buildEnvironment($egg, $order, $package);

        // 6) Server-Create-POST
        $serverPayload = [
            'external_id' => "wemx_{$order->id}",
            'name'        => Str::limit($package->name, 191, ''),
            'description' => "WemX Order #{$order->id}",
            'user'        => $pelicanUserId,
            'egg'         => $eggId,
            'docker_image'=> $package->data('docker_image'),
            'startup'     => $package->data('startup'),
            'environment' => $environment,
            'limits'      => [
                'memory'      => $memoryMb,
                'swap'        => $swapMb,
                'disk'        => $diskMb,
                'io'          => (int) $order->option('io_weight', $package->data('io_weight', 500)),
                'cpu'         => $cpu,
                'threads'     => $this->normalizeCpuPinning((string) $order->option('cpu_pinning', '')),
                'oom_killer'  => true,
            ],
            'feature_limits' => [
                'databases'   => (int) $order->option('database_limit',   $package->data('database_limit',   0)),
                'allocations' => (int) $order->option('user_allocation_limit', $package->data('user_allocation_limit', 0)),
                'backups'     => (int) $order->option('backup_limit',     $package->data('backup_limit',     0)),
            ],
            'allocation' => [
                'default'    => $primaryAllocation,
                'additional' => $additionalAllocations,
            ],
            'start_on_completion' => true,
            'skip_scripts'        => false,
            'oom_disabled'        => false,
        ];

        $response = self::makeRequest($config, '/api/application/servers', 'post', $serverPayload);
        $server   = $response['attributes'] ?? null;

        if (!$server) {
            throw new \RuntimeException('Pelican-Antwort enthielt keinen "attributes"-Block.');
        }

        $order->update([
            'external_id' => $server['id'],
            'data'        => array_merge($server, [
                'wemx_primary_allocation'    => $primaryAllocation,
                'wemx_additional_allocations'=> $additionalAllocations,
                'wemx_egg_extra_ports'       => $extraPorts,
            ]),
        ]);
    }

    public function suspend(Order $order, ServerConnection $connection)
    {
        if (!$order->external_id) return;
        self::makeRequest($connection->config, "/api/application/servers/{$order->external_id}/suspend", 'post');
    }

    public function unsuspend(Order $order, ServerConnection $connection)
    {
        if (!$order->external_id) return;
        self::makeRequest($connection->config, "/api/application/servers/{$order->external_id}/unsuspend", 'post');
    }

    public function terminate(Order $order, ServerConnection $connection)
    {
        if (!$order->external_id) return;
        try {
            // ?force=true sorgt dafür, dass laufende Server zwangsgelöscht werden
            self::makeRequest($connection->config, "/api/application/servers/{$order->external_id}?force=true", 'delete');
        } catch (\Throwable $e) {
            // Idempotent: existiert der Server nicht mehr, geben wir uns nicht weiter beschweren
            $this->debugLog($connection, 'terminate ignored', ['error' => $e->getMessage(), 'order_id' => $order->id]);
        }
    }

    public function upgradeOrDowngrade(Order $order, PackagePrice $oldPackagePrice, PackagePrice $newPackagePrice, ServerConnection $connection)
    {
        if (!$order->external_id) return;
        $package = $order->package;

        $payload = [
            'allocation' => $order->data['wemx_primary_allocation'] ?? null,
            'memory'     => $this->gbToMb((int) $order->option('memory_limit', $package->data('memory_limit', 0))),
            'swap'       => (int) $order->option('swap_limit', $package->data('swap_limit', 0)) * 1024,
            'disk'       => $this->gbToMb((int) $order->option('disk_limit', $package->data('disk_limit', 0))),
            'io'         => (int) $order->option('io_weight', 500),
            'cpu'        => (int) $order->option('cpu_limit', 100),
            'feature_limits' => [
                'databases'   => (int) $order->option('database_limit', 0),
                'allocations' => (int) $order->option('user_allocation_limit', 0),
                'backups'     => (int) $order->option('backup_limit', 0),
            ],
        ];

        self::makeRequest($connection->config, "/api/application/servers/{$order->external_id}/build", 'patch', $payload);
    }

    /* ===================================================================
     | Multi-Port-Logik: Allocations finden / auto-anlegen
     * =================================================================== */

    /**
     * Findet einen passenden Node und reserviert 1 + $extraAllocations Ports.
     *
     * Wenn nicht genug freie Allocations vorhanden sind und
     * `auto_create_allocations` aktiviert ist, werden neue Allocations
     * im konfigurierten Port-Range angelegt.
     *
     * @return array{primary:int, additional:int[], node_id:int}
     */
    private function findNodeAndAllocations(
        ServerConnection $connection,
        int $locationId,
        int $diskMb,
        int $memoryMb,
        int $extraAllocations
    ): array {
        $extraAllocations = max(0, min(self::MAX_EXTRA_PORTS, $extraAllocations));
        $needed = 1 + $extraAllocations;

        $deployable = self::makeRequest($connection->config, '/api/application/nodes/deployable', 'get', [
            'disk'    => $diskMb,
            'memory'  => $memoryMb,
            'include' => 'allocations',
        ]);

        if (empty($deployable['data'])) {
            throw new \RuntimeException('Kein Node mit ausreichenden Ressourcen gefunden.');
        }

        $config = $connection->config;
        $autoCreate = (string) ($config['auto_create_allocations'] ?? '1') === '1';

        $errors = [];

        foreach ($deployable['data'] as $node) {
            $nodeAttr = $node['attributes'];

            // Location-Filter (sofern gesetzt)
            if ($locationId > 0 && (int) $nodeAttr['location_id'] !== $locationId) {
                continue;
            }

            // Freie, ungeloctte Allocations sammeln
            $allocations = data_get($nodeAttr, 'relationships.allocations.data', []);
            $free = [];
            foreach ($allocations as $a) {
                $at = $a['attributes'];
                if (empty($at['assigned'])) {
                    $free[] = (int) $at['id'];
                }
                if (count($free) >= $needed) break;
            }

            if (count($free) >= $needed) {
                return [
                    'primary'    => $free[0],
                    'additional' => array_slice($free, 1, $extraAllocations),
                    'node_id'    => (int) $nodeAttr['id'],
                ];
            }

            // Nicht genug frei → ggf. nachlegen
            if (!$autoCreate) {
                $errors[] = "Node {$nodeAttr['id']}: nur " . count($free) . " freie Allocations, benötigt $needed";
                continue;
            }

            try {
                $created = $this->autoCreateAllocations(
                    connection: $connection,
                    nodeId: (int) $nodeAttr['id'],
                    nodeFqdn: $nodeAttr['fqdn'] ?? '0.0.0.0',
                    count: $needed - count($free),
                );
                $free = array_merge($free, $created);
            } catch (\Throwable $e) {
                $errors[] = "Node {$nodeAttr['id']}: Auto-Allocation fehlgeschlagen ({$e->getMessage()})";
                continue;
            }

            if (count($free) >= $needed) {
                return [
                    'primary'    => $free[0],
                    'additional' => array_slice($free, 1, $extraAllocations),
                    'node_id'    => (int) $nodeAttr['id'],
                ];
            }
        }

        throw new \RuntimeException(
            "Keine Node mit $needed freien Allocations gefunden." .
            (empty($errors) ? '' : ' Details: ' . implode(' | ', $errors))
        );
    }

    /**
     * Legt $count Allocations auf dem Node an, indem aufsteigend freie Ports
     * aus dem konfigurierten Port-Range geprüft und via API erstellt werden.
     *
     * @return int[] IDs der frisch angelegten Allocations
     */
    private function autoCreateAllocations(
        ServerConnection $connection,
        int $nodeId,
        string $nodeFqdn,
        int $count
    ): array {
        $config = $connection->config;
        $portMin = (int) ($config['auto_alloc_port_min'] ?? 25500);
        $portMax = (int) ($config['auto_alloc_port_max'] ?? 25700);

        if ($portMin > $portMax) {
            throw new \RuntimeException('Auto-Allocation-Range ist ungültig (min > max).');
        }

        // IP-Adresse des Nodes ermitteln. In den meisten Setups ist die FQDN
        // resolvable; wir verwenden den FQDN als IP-Feld, was Pelican intern
        // sowohl als FQDN als auch IP akzeptiert (0.0.0.0 für Wildcard).
        $ip = $nodeFqdn === '' ? '0.0.0.0' : $nodeFqdn;

        // Bereits belegte Ports auf diesem Node abfragen, um Konflikte zu vermeiden.
        $existing = self::makeRequest($connection->config, "/api/application/nodes/{$nodeId}/allocations", 'get', [
            'per_page' => 500,
        ]);

        $usedPorts = [];
        foreach (data_get($existing, 'data', []) as $entry) {
            $usedPorts[(int) $entry['attributes']['port']] = true;
        }

        $createdIds = [];
        $tried = 0;
        for ($port = $portMin; $port <= $portMax && count($createdIds) < $count; $port++) {
            if (isset($usedPorts[$port])) {
                continue;
            }

            $tried++;
            try {
                self::makeRequest($connection->config, "/api/application/nodes/{$nodeId}/allocations", 'post', [
                    'ip'    => $ip,
                    'ports' => [(string) $port],
                ]);
            } catch (\Throwable $e) {
                // Port evtl. doch belegt (Race) — überspringen
                continue;
            }

            // Die frisch angelegte Allocation finden, um die ID zu bekommen
            $check = self::makeRequest($connection->config, "/api/application/nodes/{$nodeId}/allocations", 'get', [
                'per_page' => 500,
            ]);
            foreach (data_get($check, 'data', []) as $entry) {
                if ((int) $entry['attributes']['port'] === $port && empty($entry['attributes']['assigned'])) {
                    $createdIds[] = (int) $entry['attributes']['id'];
                    break;
                }
            }

            if ($tried > ($portMax - $portMin + 1) * 2) {
                break; // Schutz vor Endlosschleifen
            }
        }

        if (count($createdIds) < $count) {
            throw new \RuntimeException(sprintf(
                'Konnte nur %d von %d Allocations im Range %d-%d anlegen.',
                count($createdIds), $count, $portMin, $portMax
            ));
        }

        return $createdIds;
    }

    /* ===================================================================
     | Egg-Helfer
     * =================================================================== */

    /**
     * Holt die Egg-Definition inkl. Variablen (mit Caching).
     */
    private function fetchEgg(ServerConnection $connection, int $eggId): array
    {
        $connectionKey = $connection->id ?? 'default';
        $cacheKey = "pelican:egg:{$connectionKey}:{$eggId}";

        return Cache::remember($cacheKey, self::EGG_CACHE_TTL, function () use ($connection, $eggId) {
            $response = self::makeRequest($connection->config, "/api/application/eggs/{$eggId}", 'get', [
                'include' => 'variables',
            ]);
            if (!isset($response['attributes'])) {
                throw new \RuntimeException("Egg #{$eggId} nicht gefunden.");
            }
            return $response['attributes'];
        });
    }

    /**
     * Liest die Anzahl zusätzlicher Allocations aus der Egg-Variable WEMX_EXTRA_PORTS.
     */
    private function extractExtraPortsFromEgg(array $egg): int
    {
        foreach (data_get($egg, 'relationships.variables.data', []) as $var) {
            $attr = $var['attributes'] ?? [];
            if (strcasecmp($attr['env_variable'] ?? '', self::EGG_EXTRA_PORTS_VAR) === 0) {
                $val = (int) ($attr['default_value'] ?? 0);
                return max(0, min(self::MAX_EXTRA_PORTS, $val));
            }
        }
        return 0;
    }

    /**
     * Baut das Environment-Array für den Server-Create-Call.
     * Alle Egg-Variablen müssen einen Wert haben (Default oder vom Admin/Order).
     */
    private function buildEnvironment(array $egg, Order $order, Package $package): array
    {
        $env = [];
        $optionEnv  = (array) ($order->option('environment') ?? []);
        $packageEnv = (array) ($package->data('environment') ?? []);

        foreach (data_get($egg, 'relationships.variables.data', []) as $var) {
            $attr = $var['attributes'] ?? [];
            $name = $attr['env_variable'] ?? null;
            if (!$name) continue;

            // Prioritäten: Order-Option → Package-Default → Egg-Default
            $value = $optionEnv[$name]
                ?? $packageEnv[$name]
                ?? $attr['default_value']
                ?? '';

            $env[$name] = (string) $value;
        }
        return $env;
    }

    /* ===================================================================
     | User-Handling
     * =================================================================== */

    private function getOrCreateUser(Order $order, ServerConnection $connection): int
    {
        $user = $order->user;

        // Versuch: User per E-Mail finden
        $existing = self::makeRequest($connection->config, '/api/application/users', 'get', [
            'filter[email]' => $user->email,
        ]);

        if (!empty($existing['data'][0]['attributes'])) {
            $userAttr = $existing['data'][0]['attributes'];
            $this->storeExternalUser($order, $userAttr, password: null);
            return (int) $userAttr['id'];
        }

        // User anlegen
        $password = Str::password(16);
        $created  = self::makeRequest($connection->config, '/api/application/users', 'post', [
            'first_name' => $user->first_name ?: 'WemX',
            'last_name'  => $user->last_name ?: "User{$user->id}",
            'email'      => $user->email,
            'username'   => $this->sanitizeUsername(($user->username ?? 'user') . $user->id),
            'password'   => $password,
        ]);

        if (!isset($created['attributes'])) {
            throw new \RuntimeException('User-Anlage auf Pelican fehlgeschlagen.');
        }

        $this->storeExternalUser($order, $created['attributes'], $password);

        if ((string) ($connection->config['send_account_email'] ?? '1') === '1') {
            $this->sendCredentialsEmail($order, $connection, $created['attributes']['email'], $password);
        }

        return (int) $created['attributes']['id'];
    }

    private function storeExternalUser(Order $order, array $userAttr, ?string $password): void
    {
        $order->createExternalUser([
            'external_id' => $userAttr['id'],
            'username'    => $userAttr['username'] ?? null,
            'password'    => $password ?? 'unknown',
            'data'        => array_merge($userAttr, ['password' => $password]),
        ]);
    }

    public function changePassword(Order $order, string $newPassword)
    {
        $external = $order->getExternalUser();
        if (!$external) return;

        $data = $external->data ?? [];
        $config = $order->package->serverConnection->config;

        self::makeRequest($config, "/api/application/users/{$data['id']}", 'patch', [
            'email'      => $data['email'],
            'username'   => $data['username'],
            'first_name' => $data['first_name'] ?? 'User',
            'last_name'  => $data['last_name'] ?? "U{$order->user_id}",
            'password'   => $newPassword,
        ]);

        $order->updateExternalPassword($newPassword);
    }

    private function sanitizeUsername(string $raw): string
    {
        $clean = preg_replace('/[^A-Za-z0-9_]/', '', $raw) ?: ('user' . Str::random(6));
        return Str::limit($clean, 30, '');
    }

    private function sendCredentialsEmail(Order $order, ServerConnection $connection, string $email, string $password): void
    {
        $panelUrl = rtrim((string) ($connection->config['hostname'] ?? ''), '/');

        $order->user->email([
            'mailable_type' => Order::class,
            'mailable_id'   => $order->id,
            'subject'       => 'Dein Gameserver-Panel-Zugang',
            'lines' => [
                'Hi! Dein Account im Gameserver-Panel ist eingerichtet.',
                'Login-Daten:',
                "E-Mail: {$email}",
                "Passwort: {$password}",
                'Bitte ändere das Passwort nach dem ersten Login.',
            ],
            'button' => [
                'name' => 'Zum Gameserver-Panel',
                'url'  => $panelUrl ?: 'https://panel.example.com',
            ],
        ]);
    }

    /* ===================================================================
     | API-Helfer (Test + makeRequest)
     * =================================================================== */

    public static function testConnection(array $credentials)
    {
        $r = self::makeRequest($credentials, '/api/application/users', 'get', ['per_page' => 1]);
        if (!isset($r['object']) && !isset($r['data'])) {
            throw new \RuntimeException('Pelican-API antwortet, aber das Format ist unerwartet.');
        }
        return true;
    }

    public static function makeRequest(array $credentials, string $endpoint, string $method = 'get', array $data = []): array
    {
        $method = strtolower($method);
        if (!in_array($method, ['get', 'post', 'put', 'patch', 'delete'])) {
            throw new \InvalidArgumentException("HTTP-Methode nicht erlaubt: $method");
        }

        $apiKey   = $credentials['api_key']  ?? '';
        $hostname = rtrim($credentials['hostname'] ?? '', '/');

        if ($apiKey === '' || $hostname === '') {
            throw new \RuntimeException('Pelican: hostname oder api_key fehlt in der Connection.');
        }

        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . $apiKey,
            'Accept'        => 'application/json',
            'Content-Type'  => 'application/json',
        ])
        ->acceptJson()
        ->timeout(30)
        ->retry(2, 250, throw: false)
        ->$method($hostname . $endpoint, $data);

        if ($response->failed()) {
            $body = $response->body();
            $code = $response->status();
            $msg  = "Pelican API $code @ $endpoint";

            // 404 bei DELETE/GET nicht als Fehler werten, das prüft der Aufrufer
            if (in_array($code, [404]) && in_array($method, ['delete'])) {
                return [];
            }

            // Lesbarere Fehlermeldung extrahieren
            $json = json_decode($body, true);
            $detail = data_get($json, 'errors.0.detail') ?: data_get($json, 'message') ?: $body;
            throw new \RuntimeException("$msg: $detail");
        }

        // 204 No Content
        if ($response->status() === 204) {
            return [];
        }

        return $response->json() ?? [];
    }

    /* ===================================================================
     | Diverse Helfer
     * =================================================================== */

    private function gbToMb(int $gb): int
    {
        return $gb > 0 ? $gb * 1024 : 0;
    }

    /**
     * Normalisiert CPU-Pinning ("0,1", "0-3", "") für Pelican.
     * Pelican erwartet einen String oder null.
     */
    private function normalizeCpuPinning(string $raw): ?string
    {
        $raw = trim($raw);
        if ($raw === '') return null;
        if (!preg_match('/^[0-9,\-\s]+$/', $raw)) {
            throw new \InvalidArgumentException("Ungültiges CPU-Pinning: $raw");
        }
        return preg_replace('/\s+/', '', $raw);
    }

    private function debugLog(ServerConnection $connection, string $message, array $context = []): void
    {
        if ((string) ($connection->config['debug_mode'] ?? '0') !== '1') {
            return;
        }
        Log::channel(config('logging.default'))->warning("[Pelican] $message", $context);
    }
}
