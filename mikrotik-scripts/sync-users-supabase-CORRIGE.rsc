# sync-users-supabase — VERSION CORRIGEE (audit "507 utilisateurs", aout 2026)
#
# Ce qui a change par rapport a l'ancien script :
#   - Suppression du plafond ":count < 200" : TOUS les utilisateurs
#     sont desormais envoyes, pas seulement les 200 premiers.
#   - Envoi PAGINE (par lots de $batchSize, defaut 100) au lieu d'un
#     seul gros JSON, pour rester dans les capacites d'un RB951Ui-2HnD
#     (128 Mo RAM, CPU mono-coeur) et eviter un timeout /tool fetch.
#   - Cote backend, la route sync-users a ete corrigee (voir
#     sync-users-fix.patch) pour faire un UPSERT par utilisateur au
#     lieu d'un DELETE+INSERT du lot recu : envoyer en plusieurs
#     appels successifs est donc desormais sans danger (aucune perte
#     des utilisateurs des autres lots).
#
# A tester d'abord en heure creuse, puis planifier au meme intervalle
# que l'ancien scheduler "sync-users-supabase".

:local batchSize 100

:local users [/ip hotspot user print as-value]
:local total [:len $users]

:local apiUrl "https://aratech-ldg0.onrender.com/api.php"
:local syncKey "REMPLACER_PAR_LA_NOUVELLE_CLE_APRES_ROTATION"
:local headers ("Content-Type: application/json\r\nX-API-Key: " . $syncKey)
:local fullUrl ($apiUrl . "?route=sync-users")

:local sent 0
:local batchNum 0

:while ($sent < $total) do={
    :local batchData ({})
    :local end ($sent + $batchSize)
    :if ($end > $total) do={ :set end $total }

    :local idx $sent
    :while ($idx < $end) do={
        :local u [:pick $users $idx]
        :local userMap {
            "name"=($u->"name");
            "password"=($u->"password");
            "profile"=($u->"profile");
            "mac-address"=($u->"mac-address");
            "comment"=($u->"comment");
            "disabled"=($u->"disabled");
            "bytes-in"=($u->"bytes-in");
            "bytes-out"=($u->"bytes-out");
            "uptime"=($u->"uptime");
            "server"=($u->"server")
        }
        :set batchData ($batchData, $userMap)
        :set idx ($idx + 1)
    }

    :local json [:serialize to=json {"users"=$batchData}]
    :set batchNum ($batchNum + 1)

    :do {
        /tool fetch url=$fullUrl http-method=post http-header-field=$headers http-data=$json output=none
        :log info "sync-users: lot $batchNum envoye ($sent a $end sur $total)"
    } on-error={
        :log warning "sync-users: echec envoi lot $batchNum ($sent a $end) - la sync reprendra au prochain cycle"
    }

    :set sent $end
    :delay 500ms
}

:log info "sync-users: termine - $total utilisateurs envoyes en $batchNum lot(s)"
