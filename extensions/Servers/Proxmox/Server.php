<?php

/*
|--------------------------------------------------------------------------
| Proxmox VE — WemX Server Extension
|--------------------------------------------------------------------------
|
| Unterstützt:
|   - VMs (KVM/QEMU)  →  Clone aus Template ODER Frischerstellung aus ISO
|   - LXC-Container   →  Clone aus Template ODER Frischerstellung aus .tar.zst
|
| Auth: API-Token (empfohlen ggü. Ticket+CSRF). Format:
|       Authorization: PVEAPIToken=USER@REALM!TOKEN_ID=TOKEN_SECRET
|
| Lifecycle:
|   create        → VMID aus Cluster reservieren, klonen/erstellen,
|                   Cloud-Init bzw. LXC-Init setzen, Disk resizen, starten
|   suspend       → ACPI-Shutdown (fallback: stop nach Timeout)
|   unsuspend     → start
|   terminate     → stop (force) + destroy
|
| Alle langlaufenden API-Calls warten via UPID-Polling auf Abschluss.
|
| Autor: Nova EDV (https://nova-edv.de)
| Lizenz: MIT
*/

namespace Extensions\Servers\Proxmox;

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
    protected string $id = 'server-proxmox';
    protected string $name = 'Proxmox VE';
    protected string $description = 'Proxmox Virtual Environment - VMs (KVM) und LXC-Container, Clone- oder ISO-/Template-basiert.';
    protected string $type = 'Server';
    protected string $version = '1.0.0';
    protected array $wemxVersions = ['1.0.0'];
    protected array $authors = [
        ['name' => 'Nova EDV', 'email' => 'info@nova-edv.de'],
    ];

    /** Maximale Wartezeit pro Proxmox-Task (Sekunden). Clone & ISO-Boot brauchen Zeit. */
    private const TASK_TIMEOUT_SECONDS = 600;
    private const TASK_POLL_INTERVAL_MS = 1000;

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
     | Connection-Konfiguration
     * =================================================================== */

    public function setConfig(): array
    {
        $noTrailingSlash = function ($attribute, $value, $fail) {
            if (preg_match('/\/$/', $value)) {
                $fail('Hostname darf nicht mit "/" enden.');
            }
        };

        return [
            [
                'key'           => 'hostname',
                'name'          => 'Proxmox Hostname',
                'description'   => 'Inkl. Schema und Port, z.B. https://pve01.example.com:8006',
                'type'          => 'url',
                'default_value' => 'https://pve01.example.com:8006',
                'rules'         => ['required', 'url', $noTrailingSlash],
            ],
            [
                'key'         => 'token_id',
                'name'        => 'API-Token-ID',
                'description' => 'Format: user@realm!tokenid — z.B. "wemx@pve!api"',
                'type'        => 'text',
                'rules'       => ['required', 'regex:/^[^!@\s]+@[a-zA-Z]+![A-Za-z0-9_\-]+$/'],
            ],
            [
                'key'         => 'token_secret',
                'name'        => 'API-Token-Secret',
                'description' => 'Der UUID-artige Secret, der beim Anlegen des Tokens angezeigt wurde.',
                'type'        => 'password',
                'rules'       => ['required', 'string', 'min:30'],
            ],
            [
                'key'           => 'verify_ssl',
                'name'          => 'SSL-Zertifikat verifizieren',
                'description'   => 'Bei selbstsigniertem PVE-Zertifikat ggf. deaktivieren.',
                'type'          => 'select',
                'options'       => ['1' => 'Ja', '0' => 'Nein'],
                'default_value' => '1',
                'rules'         => ['required', 'in:0,1'],
            ],
            [
                'key'           => 'default_node',
                'name'          => 'Default-Node',
                'description'   => 'Falls im Package kein Node fest gesetzt ist, wird dieser verwendet (Cluster-Hostname, z.B. "pve01").',
                'type'          => 'text',
                'default_value' => 'pve01',
                'rules'         => ['required', 'string'],
            ],
            [
                'key'           => 'vmid_min',
                'name'          => 'VMID-Range Untergrenze',
                'description'   => 'Falls > 0: VMIDs werden ab dieser Untergrenze vergeben (statt cluster/nextid).',
                'type'          => 'number',
                'default_value' => 0,
                'rules'         => ['required', 'numeric', 'min:0', 'max:999999999'],
            ],
            [
                'key'           => 'vmid_max',
                'name'          => 'VMID-Range Obergrenze',
                'description'   => 'Nur ausgewertet, wenn Untergrenze > 0.',
                'type'          => 'number',
                'default_value' => 0,
                'rules'         => ['required', 'numeric', 'min:0', 'max:999999999'],
            ],
            [
                'key'           => 'debug_mode',
                'name'          => 'Debug-Modus',
                'description'   => 'API-Antworten ins Log schreiben. Im Produktivbetrieb aus.',
                'type'          => 'select',
                'options'       => ['0' => 'Aus', '1' => 'An'],
                'default_value' => '0',
                'rules'         => ['required', 'in:0,1'],
            ],
        ];
    }

    /* ===================================================================
     | Package-Konfiguration
     * =================================================================== */

    public function setPackageConfig(Package $package, ServerConnection $connection): array
    {
        return [
            // --- Workload-Typ + Erstellungsmodus ---
            ['col'=>'col-6','key'=>'workload_type','name'=>'Workload','description'=>'Virtuelle Maschine (volle Virtualisierung) oder LXC-Container (leichtgewichtig, nur Linux).','type'=>'select','options'=>['vm'=>'VM (KVM/QEMU)','lxc'=>'LXC-Container'],'default_value'=>'vm','rules'=>['required','in:vm,lxc'],'is_configurable'=>false],
            ['col'=>'col-6','key'=>'creation_mode','name'=>'Erstellungsmodus','description'=>'Clone: aus vorbereitetem Template kopieren (schnell, empfohlen). Fresh: aus ISO / LXC-Template neu installieren.','type'=>'select','options'=>['clone'=>'Aus Template klonen','fresh'=>'Neu aus ISO/LXC-Template'],'default_value'=>'clone','rules'=>['required','in:clone,fresh'],'is_configurable'=>false],

            // --- Node (optional, sonst connection.default_node) ---
            ['col'=>'col-6','key'=>'target_node','name'=>'Ziel-Node (optional)','description'=>'Name des PVE-Nodes (leer = Default-Node aus Connection).','type'=>'text','rules'=>['nullable','string'],'is_configurable'=>false],

            // --- Quelle ---
            ['col'=>'col-6','key'=>'template_vmid','name'=>'Template-VMID (bei Clone)','description'=>'Nur bei Erstellungsmodus "Clone". VMID des Templates auf dem Quell-Node.','type'=>'number','rules'=>['nullable','numeric','min:100'],'is_configurable'=>false],
            ['col'=>'col-12','key'=>'iso_image','name'=>'ISO (bei VM + Fresh)','description'=>'Storage-Pfad zur ISO, z.B. "local:iso/debian-12.iso".','type'=>'text','rules'=>['nullable','string'],'is_configurable'=>false],
            ['col'=>'col-12','key'=>'lxc_template','name'=>'LXC-Template (bei LXC + Fresh)','description'=>'Storage-Pfad zum Template, z.B. "local:vztmpl/ubuntu-22.04-standard_22.04-1_amd64.tar.zst".','type'=>'text','rules'=>['nullable','string'],'is_configurable'=>false],

            // --- Storage ---
            ['col'=>'col-6','key'=>'storage','name'=>'Storage','description'=>'Name des PVE-Storage für die Disk/Rootfs, z.B. "local-lvm" oder "ceph-rbd".','type'=>'text','default_value'=>'local-lvm','rules'=>['required','string'],'is_configurable'=>false],
            ['col'=>'col-6','key'=>'disk_size_gb','name'=>'Disk-Größe (GB)','description'=>'Bei Clone wird die Disk auf diese Größe vergrößert. Verkleinern ist nicht möglich.','type'=>'number','default_value'=>20,'rules'=>['required','numeric','min:1','max:4096'],'is_configurable'=>true],

            // --- Ressourcen ---
            ['col'=>'col-4','key'=>'memory_mb','name'=>'RAM (MB)','description'=>'Hauptspeicher in MB.','type'=>'number','default_value'=>2048,'rules'=>['required','numeric','min:128','max:524288'],'is_configurable'=>true],
            ['col'=>'col-4','key'=>'swap_mb','name'=>'Swap (MB, nur LXC)','description'=>'Swap-Speicher (nur LXC), 0 = aus.','type'=>'number','default_value'=>512,'rules'=>['required','numeric','min:0','max:65536'],'is_configurable'=>false],
            ['col'=>'col-4','key'=>'cores','name'=>'CPU-Kerne','description'=>'Anzahl vCores/Kerne.','type'=>'number','default_value'=>2,'rules'=>['required','numeric','min:1','max:128'],'is_configurable'=>true],
            ['col'=>'col-6','key'=>'cpu_type','name'=>'CPU-Typ (nur VM)','description'=>'z.B. "host" für maximale Performance, "x86-64-v2-AES" für Kompatibilität.','type'=>'text','default_value'=>'host','rules'=>['nullable','string'],'is_configurable'=>false],
            ['col'=>'col-6','key'=>'os_type','name'=>'OS-Type (nur VM)','description'=>'z.B. "l26" (Linux), "win11", "w2k22"...','type'=>'text','default_value'=>'l26','rules'=>['nullable','string'],'is_configurable'=>false],

            // --- Netzwerk ---
            ['col'=>'col-6','key'=>'network_bridge','name'=>'Netzwerk-Bridge','description'=>'PVE-Bridge, meist "vmbr0".','type'=>'text','default_value'=>'vmbr0','rules'=>['required','string'],'is_configurable'=>false],
            ['col'=>'col-3','key'=>'vlan_tag','name'=>'VLAN-Tag (optional)','description'=>'Leer = kein VLAN.','type'=>'number','rules'=>['nullable','numeric','min:1','max:4094'],'is_configurable'=>false],
            ['col'=>'col-3','key'=>'network_model','name'=>'Netzwerk-Modell (VM)','description'=>'virtio (Standard), e1000, vmxnet3.','type'=>'select','options'=>['virtio'=>'virtio','e1000'=>'e1000','vmxnet3'=>'vmxnet3'],'default_value'=>'virtio','rules'=>['required','in:virtio,e1000,vmxnet3'],'is_configurable'=>false],

            // --- Cloud-Init / LXC-Init ---
            ['col'=>'col-6','key'=>'ci_user','name'=>'Initial-User','description'=>'Cloud-Init-User (VM) bzw. wird als Hint im Mail genannt.','type'=>'text','default_value'=>'root','rules'=>['required','string'],'is_configurable'=>false],
            ['col'=>'col-6','key'=>'ci_ipconfig','name'=>'IP-Konfiguration','description'=>'VM Cloud-Init: "ip=dhcp" oder "ip=1.2.3.4/24,gw=1.2.3.1". LXC: "dhcp" oder "ip=...".','type'=>'text','default_value'=>'ip=dhcp','rules'=>['required','string'],'is_configurable'=>false],
            ['col'=>'col-12','key'=>'ci_sshkeys','name'=>'SSH-Public-Keys (optional)','description'=>'Mehrere Keys mit Zeilenumbruch trennen. Werden bei VM via Cloud-Init injiziert.','type'=>'textarea','rules'=>['nullable','string'],'is_configurable'=>true],

            // --- Sonstiges ---
            ['col'=>'col-6','key'=>'start_after_create','name'=>'Nach Erstellung starten','description'=>'Wenn aus, bleibt die VM/CT nach Bereitstellung gestoppt.','type'=>'select','options'=>['1'=>'Ja','0'=>'Nein'],'default_value'=>'1','rules'=>['required','in:0,1'],'is_configurable'=>false],
            ['col'=>'col-6','key'=>'send_credentials_email','name'=>'Zugangs-Mail senden','description'=>'Schickt dem User generiertes Root-Passwort & IP per Mail.','type'=>'select','options'=>['1'=>'Ja','0'=>'Nein'],'default_value'=>'1','rules'=>['required','in:0,1'],'is_configurable'=>false],
        ];
    }

    public function setCheckoutConfig(Package $package): array { return []; }

    /* ===================================================================
     | Lifecycle: create
     * =================================================================== */

    public function create(Order $order, ServerConnection $connection)
    {
        $package = $order->package;
        $type    = (string) ($order->option('workload_type', $package->data('workload_type', 'vm')));
        $mode    = (string) ($order->option('creation_mode', $package->data('creation_mode', 'clone')));

        $node = trim((string) ($order->option('target_node', $package->data('target_node', ''))));
        if ($node === '') {
            $node = (string) ($connection->config['default_node'] ?? '');
        }
        if ($node === '') {
            throw new \RuntimeException('Kein Ziel-Node konfiguriert (weder Package noch Connection).');
        }

        $vmid = $this->reserveVmid($connection);
        $rootPassword = Str::password(20);

        $context = [
            'vmid'         => $vmid,
            'node'         => $node,
            'workload'     => $type,
            'creation'     => $mode,
            'root_password'=> $rootPassword,
        ];

        try {
            if ($type === 'vm' && $mode === 'clone') {
                $this->createVmFromClone($order, $connection, $context);
            } elseif ($type === 'vm' && $mode === 'fresh') {
                $this->createVmFromIso($order, $connection, $context);
            } elseif ($type === 'lxc' && $mode === 'clone') {
                $this->createLxcFromClone($order, $connection, $context);
            } elseif ($type === 'lxc' && $mode === 'fresh') {
                $this->createLxcFromTemplate($order, $connection, $context);
            } else {
                throw new \RuntimeException("Kombination workload=$type / mode=$mode ist nicht unterstützt.");
            }

            // Daten dauerhaft speichern
            $order->update([
                'external_id' => $vmid,
                'data'        => array_merge((array) $order->data, [
                    'proxmox_vmid'     => $vmid,
                    'proxmox_node'     => $node,
                    'proxmox_type'     => $type,
                    'proxmox_creation' => $mode,
                    'root_user'        => $type === 'lxc' ? 'root' : (string) ($package->data('ci_user') ?? 'root'),
                    'root_password'    => $rootPassword,
                ]),
            ]);

            // Optional starten
            if ((string) ($order->option('start_after_create', $package->data('start_after_create', '1'))) === '1') {
                $this->startInstance($connection, $node, $vmid, $type);
            }

            // Optional Credentials-Mail
            if ((string) ($order->option('send_credentials_email', $package->data('send_credentials_email', '1'))) === '1') {
                $this->sendCredentialsEmail($order, $connection, $node, $vmid, $rootPassword, $type);
            }
        } catch (\Throwable $e) {
            // Best-effort Cleanup: angelegte halbgare VM/CT wieder entfernen
            $this->safeDestroy($connection, $node, $vmid, $type);
            throw $e;
        }
    }

    /* ------------------ VM / Clone ----------------------------------- */

    private function createVmFromClone(Order $order, ServerConnection $connection, array $ctx): void
    {
        $package    = $order->package;
        $templateId = (int) ($order->option('template_vmid', $package->data('template_vmid', 0)));
        if ($templateId <= 0) {
            throw new \RuntimeException('Template-VMID ist nicht gesetzt.');
        }

        // Klon-Operation (Quell-Template kann auf einem anderen Node liegen; PVE migriert automatisch)
        $upid = $this->call($connection, 'POST', "/nodes/{$ctx['node']}/qemu/{$templateId}/clone", [
            'newid'  => $ctx['vmid'],
            'name'   => $this->safeName($package->name, $order->id),
            'target' => $ctx['node'],
            'full'   => 1,
        ]);
        $this->waitForTask($connection, $ctx['node'], $upid['data'] ?? null);

        // Disk auf gewünschte Größe vergrößern (nur falls > aktueller Größe)
        $diskGb = (int) $order->option('disk_size_gb', $package->data('disk_size_gb', 20));
        try {
            $this->call($connection, 'PUT', "/nodes/{$ctx['node']}/qemu/{$ctx['vmid']}/resize", [
                'disk' => 'scsi0',
                'size' => "{$diskGb}G",
            ]);
        } catch (\Throwable $e) {
            // Resize schlägt fehl, wenn Disk bereits größer ist — das ist OK.
            $this->debugLog($connection, 'resize ignored', ['error' => $e->getMessage()]);
        }

        // Cloud-Init + Ressourcen anpassen
        $this->applyVmConfig($order, $connection, $ctx, $package, includeBootMedia: false);

        // Cloud-Init Drive aktualisieren (regeneriert config drive)
        try {
            $this->call($connection, 'POST', "/nodes/{$ctx['node']}/qemu/{$ctx['vmid']}/cloudinit");
        } catch (\Throwable $e) {
            // Wenn kein Cloud-Init-Drive vorhanden ist: ignorieren
            $this->debugLog($connection, 'cloudinit refresh skipped', ['error' => $e->getMessage()]);
        }
    }

    /* ------------------ VM / ISO ------------------------------------- */

    private function createVmFromIso(Order $order, ServerConnection $connection, array $ctx): void
    {
        $package = $order->package;
        $iso     = (string) ($order->option('iso_image', $package->data('iso_image', '')));
        if ($iso === '') {
            throw new \RuntimeException('ISO-Image ist nicht gesetzt.');
        }

        $storage = (string) ($order->option('storage', $package->data('storage', 'local-lvm')));
        $diskGb  = (int) $order->option('disk_size_gb', $package->data('disk_size_gb', 20));
        $memMb   = (int) $order->option('memory_mb', $package->data('memory_mb', 2048));
        $cores   = (int) $order->option('cores', $package->data('cores', 2));
        $osType  = (string) ($package->data('os_type', 'l26'));
        $cpuType = (string) ($package->data('cpu_type', 'host'));
        $bridge  = (string) ($package->data('network_bridge', 'vmbr0'));
        $vlan    = (int)    ($package->data('vlan_tag', 0));
        $model   = (string) ($package->data('network_model', 'virtio'));

        $net0 = "{$model},bridge={$bridge}" . ($vlan > 0 ? ",tag={$vlan}" : '');

        $payload = [
            'vmid'        => $ctx['vmid'],
            'name'        => $this->safeName($package->name, $order->id),
            'ostype'      => $osType,
            'cpu'         => $cpuType,
            'cores'       => $cores,
            'sockets'     => 1,
            'memory'      => $memMb,
            'scsihw'      => 'virtio-scsi-single',
            'scsi0'       => "{$storage}:{$diskGb},iothread=1,discard=on",
            'ide2'        => "{$iso},media=cdrom",
            'boot'        => 'order=scsi0;ide2;net0',
            'net0'        => $net0,
            'agent'       => 1,
            'onboot'      => 1,
        ];

        // SSH-Keys via cicustom geht hier nicht ohne cloud-init drive — bei ISO ohne CI.
        // Beim ersten Boot installiert der User manuell via noVNC.

        $upid = $this->call($connection, 'POST', "/nodes/{$ctx['node']}/qemu", $payload);
        $this->waitForTask($connection, $ctx['node'], $upid['data'] ?? null);
    }

    /* ------------------ Gemeinsame VM-Config-Routine ----------------- */

    private function applyVmConfig(Order $order, ServerConnection $connection, array $ctx, Package $package, bool $includeBootMedia): void
    {
        $memMb   = (int) $order->option('memory_mb', $package->data('memory_mb', 2048));
        $cores   = (int) $order->option('cores', $package->data('cores', 2));
        $cpuType = (string) ($package->data('cpu_type', 'host'));
        $bridge  = (string) ($package->data('network_bridge', 'vmbr0'));
        $vlan    = (int)    ($package->data('vlan_tag', 0));
        $model   = (string) ($package->data('network_model', 'virtio'));

        $ciUser     = (string) ($package->data('ci_user', 'root'));
        $ipconfig0  = (string) ($package->data('ci_ipconfig', 'ip=dhcp'));
        $sshKeys    = (string) ($order->option('ci_sshkeys', $package->data('ci_sshkeys', '')));

        $payload = [
            'cores'        => $cores,
            'sockets'      => 1,
            'memory'       => $memMb,
            'cpu'          => $cpuType,
            'net0'         => "{$model},bridge={$bridge}" . ($vlan > 0 ? ",tag={$vlan}" : ''),
            'agent'        => 1,
            'onboot'       => 1,
            'ciuser'       => $ciUser,
            'cipassword'   => $ctx['root_password'],
            'ipconfig0'    => $ipconfig0,
        ];

        if ($sshKeys !== '') {
            // Proxmox erwartet die SSH-Keys URL-encoded (jede Zeile = ein Key)
            $payload['sshkeys'] = rawurlencode($sshKeys);
        }

        if ($includeBootMedia) {
            $payload['boot'] = 'order=scsi0;net0';
        }

        $this->call($connection, 'PUT', "/nodes/{$ctx['node']}/qemu/{$ctx['vmid']}/config", $payload);
    }

    /* ------------------ LXC / Clone ---------------------------------- */

    private function createLxcFromClone(Order $order, ServerConnection $connection, array $ctx): void
    {
        $package    = $order->package;
        $templateId = (int) ($order->option('template_vmid', $package->data('template_vmid', 0)));
        if ($templateId <= 0) {
            throw new \RuntimeException('Template-VMID (LXC) ist nicht gesetzt.');
        }

        $upid = $this->call($connection, 'POST', "/nodes/{$ctx['node']}/lxc/{$templateId}/clone", [
            'newid'    => $ctx['vmid'],
            'hostname' => $this->safeHostname($package->name, $order->id),
            'full'     => 1,
        ]);
        $this->waitForTask($connection, $ctx['node'], $upid['data'] ?? null);

        // Ressourcen + Netzwerk + Passwort setzen
        $this->applyLxcConfig($order, $connection, $ctx, $package);

        // Disk (rootfs) vergrößern
        $diskGb = (int) $order->option('disk_size_gb', $package->data('disk_size_gb', 20));
        try {
            $this->call($connection, 'PUT', "/nodes/{$ctx['node']}/lxc/{$ctx['vmid']}/resize", [
                'disk' => 'rootfs',
                'size' => "{$diskGb}G",
            ]);
        } catch (\Throwable $e) {
            $this->debugLog($connection, 'lxc resize ignored', ['error' => $e->getMessage()]);
        }
    }

    /* ------------------ LXC / Fresh ---------------------------------- */

    private function createLxcFromTemplate(Order $order, ServerConnection $connection, array $ctx): void
    {
        $package  = $order->package;
        $template = (string) ($order->option('lxc_template', $package->data('lxc_template', '')));
        if ($template === '') {
            throw new \RuntimeException('LXC-Template ist nicht gesetzt.');
        }

        $storage = (string) ($order->option('storage', $package->data('storage', 'local-lvm')));
        $diskGb  = (int) $order->option('disk_size_gb', $package->data('disk_size_gb', 20));
        $memMb   = (int) $order->option('memory_mb', $package->data('memory_mb', 2048));
        $swapMb  = (int) $order->option('swap_mb', $package->data('swap_mb', 512));
        $cores   = (int) $order->option('cores', $package->data('cores', 2));
        $bridge  = (string) ($package->data('network_bridge', 'vmbr0'));
        $vlan    = (int)    ($package->data('vlan_tag', 0));
        $ipconf  = (string) ($package->data('ci_ipconfig', 'ip=dhcp'));

        $net0Parts = ["name=eth0", "bridge={$bridge}", "firewall=1"];
        if ($vlan > 0) {
            $net0Parts[] = "tag={$vlan}";
        }
        // LXC akzeptiert ip=dhcp oder ip=cidr,gw=...
        $net0Parts[] = $ipconf;
        $net0 = implode(',', $net0Parts);

        $payload = [
            'vmid'       => $ctx['vmid'],
            'ostemplate' => $template,
            'hostname'   => $this->safeHostname($package->name, $order->id),
            'password'   => $ctx['root_password'],
            'storage'    => $storage,
            'rootfs'     => "{$storage}:{$diskGb}",
            'cores'      => $cores,
            'memory'     => $memMb,
            'swap'       => $swapMb,
            'net0'       => $net0,
            'onboot'     => 1,
            'features'   => 'nesting=1',
            'unprivileged' => 1,
        ];

        // SSH-Keys, falls vorhanden
        $sshKeys = (string) ($order->option('ci_sshkeys', $package->data('ci_sshkeys', '')));
        if ($sshKeys !== '') {
            $payload['ssh-public-keys'] = $sshKeys;
        }

        $upid = $this->call($connection, 'POST', "/nodes/{$ctx['node']}/lxc", $payload);
        $this->waitForTask($connection, $ctx['node'], $upid['data'] ?? null, self::TASK_TIMEOUT_SECONDS);
    }

    private function applyLxcConfig(Order $order, ServerConnection $connection, array $ctx, Package $package): void
    {
        $memMb   = (int) $order->option('memory_mb', $package->data('memory_mb', 2048));
        $swapMb  = (int) $order->option('swap_mb', $package->data('swap_mb', 512));
        $cores   = (int) $order->option('cores', $package->data('cores', 2));
        $bridge  = (string) ($package->data('network_bridge', 'vmbr0'));
        $vlan    = (int)    ($package->data('vlan_tag', 0));
        $ipconf  = (string) ($package->data('ci_ipconfig', 'ip=dhcp'));

        $net0Parts = ["name=eth0", "bridge={$bridge}", "firewall=1"];
        if ($vlan > 0) $net0Parts[] = "tag={$vlan}";
        $net0Parts[] = $ipconf;

        // Passwort kann nur via separatem Endpoint gesetzt werden
        try {
            $this->call($connection, 'PUT', "/nodes/{$ctx['node']}/lxc/{$ctx['vmid']}/config", [
                'cores'  => $cores,
                'memory' => $memMb,
                'swap'   => $swapMb,
                'net0'   => implode(',', $net0Parts),
                'onboot' => 1,
            ]);
        } catch (\Throwable $e) {
            throw new \RuntimeException("LXC-Konfig konnte nicht gesetzt werden: " . $e->getMessage());
        }
    }

    /* ===================================================================
     | Lifecycle: suspend / unsuspend / terminate / upgrade
     * =================================================================== */

    public function suspend(Order $order, ServerConnection $connection)
    {
        $node = $order->data['proxmox_node'] ?? null;
        $vmid = (int) $order->external_id;
        $type = $order->data['proxmox_type'] ?? 'vm';
        if (!$node || !$vmid) return;

        $endpoint = $type === 'lxc'
            ? "/nodes/{$node}/lxc/{$vmid}/status/shutdown"
            : "/nodes/{$node}/qemu/{$vmid}/status/shutdown";

        try {
            $upid = $this->call($connection, 'POST', $endpoint, ['timeout' => 60, 'forceStop' => 1]);
            $this->waitForTask($connection, $node, $upid['data'] ?? null, 120);
        } catch (\Throwable $e) {
            // Fallback: hartes Stop
            $stopEp = $type === 'lxc'
                ? "/nodes/{$node}/lxc/{$vmid}/status/stop"
                : "/nodes/{$node}/qemu/{$vmid}/status/stop";
            $upid = $this->call($connection, 'POST', $stopEp);
            $this->waitForTask($connection, $node, $upid['data'] ?? null, 60);
        }
    }

    public function unsuspend(Order $order, ServerConnection $connection)
    {
        $node = $order->data['proxmox_node'] ?? null;
        $vmid = (int) $order->external_id;
        $type = $order->data['proxmox_type'] ?? 'vm';
        if (!$node || !$vmid) return;

        $this->startInstance($connection, $node, $vmid, $type);
    }

    public function terminate(Order $order, ServerConnection $connection)
    {
        $node = $order->data['proxmox_node'] ?? null;
        $vmid = (int) $order->external_id;
        $type = $order->data['proxmox_type'] ?? 'vm';
        if (!$node || !$vmid) return;

        // Erst stoppen (force), dann zerstören
        $stopEp = $type === 'lxc'
            ? "/nodes/{$node}/lxc/{$vmid}/status/stop"
            : "/nodes/{$node}/qemu/{$vmid}/status/stop";
        try {
            $upid = $this->call($connection, 'POST', $stopEp);
            $this->waitForTask($connection, $node, $upid['data'] ?? null, 60);
        } catch (\Throwable $e) {
            // Schon aus oder existiert nicht — egal
        }

        $delEp = $type === 'lxc'
            ? "/nodes/{$node}/lxc/{$vmid}?purge=1"
            : "/nodes/{$node}/qemu/{$vmid}?destroy-unreferenced-disks=1&purge=1";

        try {
            $upid = $this->call($connection, 'DELETE', $delEp);
            $this->waitForTask($connection, $node, $upid['data'] ?? null, 300);
        } catch (\Throwable $e) {
            $this->debugLog($connection, 'destroy failed', ['error' => $e->getMessage(), 'vmid' => $vmid]);
            throw $e;
        }
    }

    public function upgradeOrDowngrade(Order $order, PackagePrice $oldPackagePrice, PackagePrice $newPackagePrice, ServerConnection $connection)
    {
        $node = $order->data['proxmox_node'] ?? null;
        $vmid = (int) $order->external_id;
        $type = $order->data['proxmox_type'] ?? 'vm';
        if (!$node || !$vmid) return;

        $package = $order->package;
        $memMb   = (int) $order->option('memory_mb', $package->data('memory_mb', 2048));
        $cores   = (int) $order->option('cores', $package->data('cores', 2));

        $endpoint = $type === 'lxc'
            ? "/nodes/{$node}/lxc/{$vmid}/config"
            : "/nodes/{$node}/qemu/{$vmid}/config";

        $this->call($connection, 'PUT', $endpoint, [
            'cores'  => $cores,
            'memory' => $memMb,
        ]);

        // Disk vergrößern, sofern größer
        $diskGb = (int) $order->option('disk_size_gb', $package->data('disk_size_gb', 0));
        if ($diskGb > 0) {
            $diskKey = $type === 'lxc' ? 'rootfs' : 'scsi0';
            $resizeEp = $type === 'lxc'
                ? "/nodes/{$node}/lxc/{$vmid}/resize"
                : "/nodes/{$node}/qemu/{$vmid}/resize";
            try {
                $this->call($connection, 'PUT', $resizeEp, ['disk' => $diskKey, 'size' => "{$diskGb}G"]);
            } catch (\Throwable $e) {
                $this->debugLog($connection, 'upgrade resize ignored', ['error' => $e->getMessage()]);
            }
        }
    }

    public function changePassword(Order $order, string $newPassword)
    {
        // Proxmox-User selbst ändern wir hier nicht (die Token-basierte API
        // gehört dem Provider). Wir speichern nur das neue Root-Password lokal.
        $order->update([
            'data' => array_merge((array) $order->data, ['root_password' => $newPassword]),
        ]);
    }

    /* ===================================================================
     | Start-Helper
     * =================================================================== */

    private function startInstance(ServerConnection $connection, string $node, int $vmid, string $type): void
    {
        $endpoint = $type === 'lxc'
            ? "/nodes/{$node}/lxc/{$vmid}/status/start"
            : "/nodes/{$node}/qemu/{$vmid}/status/start";
        $upid = $this->call($connection, 'POST', $endpoint);
        $this->waitForTask($connection, $node, $upid['data'] ?? null, 120);
    }

    private function safeDestroy(ServerConnection $connection, string $node, int $vmid, string $type): void
    {
        try {
            $stopEp = $type === 'lxc'
                ? "/nodes/{$node}/lxc/{$vmid}/status/stop"
                : "/nodes/{$node}/qemu/{$vmid}/status/stop";
            $this->call($connection, 'POST', $stopEp);
        } catch (\Throwable) { /* ignore */ }

        try {
            $delEp = $type === 'lxc'
                ? "/nodes/{$node}/lxc/{$vmid}?purge=1"
                : "/nodes/{$node}/qemu/{$vmid}?destroy-unreferenced-disks=1&purge=1";
            $this->call($connection, 'DELETE', $delEp);
        } catch (\Throwable) { /* ignore */ }
    }

    /* ===================================================================
     | VMID-Verwaltung
     * =================================================================== */

    /**
     * Reserviert eine freie VMID — entweder via /cluster/nextid (Default)
     * oder durch eigenen Range-Scan, falls vmid_min/max gesetzt sind.
     */
    private function reserveVmid(ServerConnection $connection): int
    {
        $min = (int) ($connection->config['vmid_min'] ?? 0);
        $max = (int) ($connection->config['vmid_max'] ?? 0);

        if ($min > 0 && $max > 0 && $max >= $min) {
            return $this->reserveVmidFromRange($connection, $min, $max);
        }

        // Default: cluster/nextid
        $response = $this->call($connection, 'GET', '/cluster/nextid');
        $vmid = (int) ($response['data'] ?? 0);
        if ($vmid < 100) {
            throw new \RuntimeException('cluster/nextid lieferte keine valide VMID zurück.');
        }
        return $vmid;
    }

    private function reserveVmidFromRange(ServerConnection $connection, int $min, int $max): int
    {
        // Alle aktuell genutzten VMIDs aus dem Cluster holen
        $resources = $this->call($connection, 'GET', '/cluster/resources', ['type' => 'vm']);
        $used = [];
        foreach (data_get($resources, 'data', []) as $r) {
            $used[(int) ($r['vmid'] ?? 0)] = true;
        }

        for ($id = $min; $id <= $max; $id++) {
            if (!isset($used[$id])) {
                return $id;
            }
        }

        throw new \RuntimeException("Keine freie VMID im Range {$min}-{$max} verfügbar.");
    }

    /* ===================================================================
     | API + Task-Polling
     * =================================================================== */

    public static function testConnection(array $credentials)
    {
        $instance = new self();
        $instance->callRaw($credentials, 'GET', '/version');
        return true;
    }

    private function call(ServerConnection $connection, string $method, string $path, array $data = []): array
    {
        return $this->callRaw($connection->config, $method, $path, $data);
    }

    /**
     * Roher API-Call. Trennt Pfad und Methode sauber, behandelt Fehler.
     */
    private function callRaw(array $credentials, string $method, string $path, array $data = []): array
    {
        $method   = strtoupper($method);
        $hostname = rtrim($credentials['hostname'] ?? '', '/');
        $tokenId  = $credentials['token_id'] ?? '';
        $secret   = $credentials['token_secret'] ?? '';
        $verify   = (string) ($credentials['verify_ssl'] ?? '1') === '1';

        if ($hostname === '' || $tokenId === '' || $secret === '') {
            throw new \RuntimeException('Proxmox: hostname/token_id/token_secret fehlen.');
        }

        $url = $hostname . '/api2/json' . $path;

        $http = Http::withHeaders([
            'Authorization' => "PVEAPIToken={$tokenId}={$secret}",
            'Accept'        => 'application/json',
        ])
        ->timeout(60)
        ->withOptions([
            'verify' => $verify,
        ])
        ->acceptJson()
        ->asForm(); // Proxmox erwartet form-encoded bei POST/PUT

        $response = match ($method) {
            'GET'    => $http->get($url, $data),
            'POST'   => $http->post($url, $data),
            'PUT'    => $http->put($url, $data),
            'DELETE' => $http->delete($url, $data),
            default  => throw new \InvalidArgumentException("HTTP $method nicht unterstützt"),
        };

        if ($response->failed()) {
            $body = $response->body();
            $code = $response->status();

            // PVE liefert "errors" oft im JSON; menschlich extrahieren
            $json = json_decode($body, true);
            $errors = data_get($json, 'errors');
            $detail = is_array($errors) ? json_encode($errors) : ($json['message'] ?? $body);

            throw new \RuntimeException("Proxmox API $code $method $path: $detail");
        }

        return $response->json() ?? [];
    }

    /**
     * Wartet auf einen PVE-Background-Task. UPID-Format:
     *   UPID:nodename:PID_HEX:PSTART_HEX:STARTTIME_HEX:type:id:user:
     */
    private function waitForTask(ServerConnection $connection, string $node, ?string $upid, int $timeoutSeconds = self::TASK_TIMEOUT_SECONDS): void
    {
        if (!$upid || !str_starts_with($upid, 'UPID:')) {
            // Manche Endpunkte (z.B. /config update) liefern keinen UPID — nichts zu warten
            return;
        }

        $deadline = microtime(true) + $timeoutSeconds;
        $encoded = rawurlencode($upid);

        while (microtime(true) < $deadline) {
            $status = $this->call($connection, 'GET', "/nodes/{$node}/tasks/{$encoded}/status");
            $d = $status['data'] ?? [];

            if (($d['status'] ?? '') === 'stopped') {
                if (($d['exitstatus'] ?? '') === 'OK') {
                    return;
                }
                throw new \RuntimeException("Proxmox-Task fehlgeschlagen: " . ($d['exitstatus'] ?? 'unknown'));
            }

            usleep(self::TASK_POLL_INTERVAL_MS * 1000);
        }

        throw new \RuntimeException("Proxmox-Task nicht innerhalb von {$timeoutSeconds}s abgeschlossen ({$upid}).");
    }

    /* ===================================================================
     | Helfer
     * =================================================================== */

    private function safeName(string $raw, int $orderId): string
    {
        // Proxmox-Namen dürfen nur a-z, 0-9, _ - . und max ~63 Zeichen
        $clean = preg_replace('/[^A-Za-z0-9\-.]/', '-', $raw) ?: 'srv';
        $clean = trim($clean, '-.');
        return Str::limit($clean . '-' . $orderId, 60, '');
    }

    private function safeHostname(string $raw, int $orderId): string
    {
        $clean = preg_replace('/[^A-Za-z0-9\-]/', '-', $raw) ?: 'ct';
        $clean = trim($clean, '-');
        return Str::limit(strtolower($clean) . '-' . $orderId, 60, '');
    }

    private function sendCredentialsEmail(Order $order, ServerConnection $connection, string $node, int $vmid, string $password, string $type): void
    {
        $panel = rtrim((string) ($connection->config['hostname'] ?? ''), '/');
        $consoleUrl = $panel . "/?console=" . ($type === 'lxc' ? 'lxc' : 'kvm')
            . "&xtermjs=1&vmid={$vmid}&node={$node}&resize=on";

        $order->user->email([
            'mailable_type' => Order::class,
            'mailable_id'   => $order->id,
            'subject'       => 'Dein Server ist bereit',
            'lines' => [
                'Hallo! Dein ' . ($type === 'lxc' ? 'LXC-Container' : 'virtueller Server') . ' wurde bereitgestellt.',
                "Node: {$node}",
                "VMID: {$vmid}",
                "User: root",
                "Passwort: {$password}",
                'Bitte ändere das Passwort nach dem ersten Login.',
            ],
            'button' => [
                'name' => 'Web-Konsole öffnen',
                'url'  => $consoleUrl,
            ],
        ]);
    }

    private function debugLog(ServerConnection $connection, string $message, array $context = []): void
    {
        if ((string) ($connection->config['debug_mode'] ?? '0') !== '1') {
            return;
        }
        Log::channel(config('logging.default'))->warning("[Proxmox] $message", $context);
    }
}
