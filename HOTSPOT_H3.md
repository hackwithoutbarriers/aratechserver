# Hotspot V2.1 — Phase H3

## API MikroTik

Toutes les routes worker utilisent `X-API-Key: <HOTSPOT_SYNC_KEY>` et HTTPS.

### GET `/api.php?route=hotspot-commands-pending`

Réclame atomiquement jusqu'à `HOTSPOT_COMMAND_POLL_LIMIT` commandes (`10` par défaut, borné côté serveur) et les passe de `PENDING` à `PROCESSING`.

Réponse :

```json
{
  "success": true,
  "data": { "items": [{ "id": 123, "action": "create", "payload": { "username": "client01" } }] }
}
```

### POST `/api.php?route=hotspot-command-ack`

Payload :

```json
{ "command_id": 123, "success": true, "message": "created" }
```

Succès : `PROCESSING -> EXECUTED`. Échec RouterOS : `PROCESSING -> FAILED`.

## États

`PENDING -> PROCESSING -> EXECUTED` ou `PENDING -> PROCESSING -> FAILED`.

Une commande `PROCESSING` n'est redistribuée qu'après `HOTSPOT_COMMAND_PROCESSING_TIMEOUT` secondes (`900` par défaut), afin d'éviter une double exécution immédiate si l'ACK est perdu.

## Worker MikroTik

Script : `mikrotik-scripts/hotspot-command-worker.rsc`.

Après import, configurez :

```routeros
/system script set hotspot-command-worker source=[/system script get hotspot-command-worker source]
```

Éditez dans le script :

- `hotspotApiUrl` : URL Render, par exemple `https://example.onrender.com/api.php`.
- `hotspotSyncKey` : valeur de `HOTSPOT_SYNC_KEY`.

Scheduler recommandé :

```routeros
/system scheduler add name=hotspot-command-worker interval=30s start-time=startup on-event="/system script run hotspot-command-worker" disabled=no comment="Hotspot V2.1 H3 command polling"
```

Le worker traite `create`, `update`, `enable`, `disable`, `delete`, puis termine. Il ne contient pas de boucle infinie.

## Tests manuels

1. Créer une commande via `hotspot-user-create`.
2. Exécuter `/system script run hotspot-command-worker`.
3. Vérifier `/ip hotspot user print`.
4. Vérifier le statut via `GET /api.php?route=hotspot-command-status&id=...&token=...`.
