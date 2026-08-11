# =============================================================================
# ARA Tech WiFi - push-hotspot-status (V2.1)
# -----------------------------------------------------------------------------
# Script RouterOS a executer localement sur le routeur MikroTik (ARA-Tech).
# Construit un payload JSON enrichi (routeur + sessions hotspot) et le pousse
# vers le backend via POST api.php?route=push-status, exactement comme le
# faisait l'ancien script (memes en-tetes HTTPS / X-API-Key), mais avec un
# format de donnees enrichi.
#
# A PLANIFIER via /system scheduler toutes les 30 secondes (voir tout en bas
# de ce fichier pour la commande d'installation du scheduler).
#
# IMPORTANT AVANT MISE EN PRODUCTION :
#   - Remplacer $apiUrl par l'URL reelle du backend.
#   - Remplacer $apiKey par la valeur de HOTSPOT_SYNC_KEY (config.php).
#     Si la cle actuelle a deja circule en clair, elle doit etre consideree
#     comme compromise : generez-en une nouvelle cote backend (config.php /
#     variable d'environnement HOTSPOT_SYNC_KEY) ET cote routeur avant de
#     deployer ce script.
#   - Tester d'abord manuellement (/system script run push-hotspot-status)
#     avec 0, 1 puis plusieurs utilisateurs connectes, et verifier le contenu
#     recu cote Supabase (voir §24 du brief : active_count, router_identity,
#     router_uptime, router_version, cpu_load, memory_total, memory_free,
#     users_json).
#   - La syntaxe exacte de "/tool fetch" (noms de parametres http-header-field,
#     http-data, keep-result) peut varier legerement selon la version de
#     RouterOS. Verifiee ici pour la syntaxe RouterOS 7.x documentee ; a
#     confirmer sur le routeur reel (RouterOS 7.23.2) avant activation du
#     scheduler.
# =============================================================================

:local apiUrl "https://aratech-ldg0.onrender.com/api.php?route=push-status"
:local apiKey "REPLACE_WITH_ROTATED_HOTSPOT_SYNC_KEY"

:do {

    # -------------------------------------------------------------------
    # Fonction utilitaire : echappement JSON (guillemets, antislash,
    # retours a la ligne, tabulations). Indispensable car les usernames,
    # commentaires ou identites peuvent contenir des caracteres qui
    # casseraient un JSON construit "a la main" (voir §2.3 du brief).
    # -------------------------------------------------------------------
    :local jsonEscape do={
        :local inStr [:tostr $1]
        :local out ""
        :local n [:len $inStr]
        :local idx 0
        :while ($idx < $n) do={
            :local c [:pick $inStr $idx ($idx + 1)]
            :if ($c = "\"") do={
                :set out ($out . "\\\"")
            } else={ :if ($c = "\\") do={
                :set out ($out . "\\\\")
            } else={ :if ($c = "\n") do={
                :set out ($out . "\\n")
            } else={ :if ($c = "\r") do={
                :set out ($out . "")
            } else={ :if ($c = "\t") do={
                :set out ($out . "\\t")
            } else={
                :set out ($out . $c)
            }}}}}
            :set idx ($idx + 1)
        }
        :return $out
    }

    # -------------------------------------------------------------------
    # 1) Informations routeur (/system identity, /system resource)
    #    Chaque valeur est recuperee individuellement et proteguee par un
    #    bloc :do/on-error : si une propriete n'existe pas sur cette
    #    version de RouterOS, on retombe sur une valeur vide plutot que de
    #    faire echouer tout le script (voir §2.2 et §18 du brief).
    # -------------------------------------------------------------------
    :local routerIdentity ""
    :do { :set routerIdentity [:tostr [/system identity get name]] } on-error={ :set routerIdentity "" }

    :local routerUptime ""
    :do { :set routerUptime [:tostr [/system resource get uptime]] } on-error={ :set routerUptime "" }

    :local routerVersion ""
    :do { :set routerVersion [:tostr [/system resource get version]] } on-error={ :set routerVersion "" }

    :local cpuLoad ""
    :do { :set cpuLoad [/system resource get cpu-load] } on-error={ :set cpuLoad "" }

    :local memTotal ""
    :do { :set memTotal [/system resource get total-memory] } on-error={ :set memTotal "" }

    :local memFree ""
    :do { :set memFree [/system resource get free-memory] } on-error={ :set memFree "" }

    :local cpuField "null"
    :if ($cpuLoad != "") do={ :set cpuField [:tostr $cpuLoad] }

    :local memTotalField "null"
    :if ($memTotal != "") do={ :set memTotalField [:tostr $memTotal] }

    :local memFreeField "null"
    :if ($memFree != "") do={ :set memFreeField [:tostr $memFree] }

    :local routerJson ("{\"identity\":\"" . [$jsonEscape $routerIdentity] . \
        "\",\"uptime\":\"" . [$jsonEscape $routerUptime] . \
        "\",\"version\":\"" . [$jsonEscape $routerVersion] . \
        "\",\"cpu\":" . $cpuField . \
        ",\"memory_total\":" . $memTotalField . \
        ",\"memory_free\":" . $memFreeField . "}")

    # -------------------------------------------------------------------
    # 2) Sessions Hotspot actives (/ip hotspot active print)
    #    Le "profil" n'est pas expose directement par /ip hotspot active :
    #    on le recupere via /ip hotspot user (meme nom d'utilisateur), avec
    #    repli sur une chaine vide si introuvable (compte statique, etc.).
    # -------------------------------------------------------------------
    :local activeIds [/ip hotspot active find]
    :local activeCount [:len $activeIds]

    :local usersJson "["
    :local isFirst true

    :foreach s in=$activeIds do={
        :local uUser ""
        :do { :set uUser [:tostr [/ip hotspot active get $s user]] } on-error={ :set uUser "" }

        :local uMac ""
        :do { :set uMac [:tostr [/ip hotspot active get $s mac-address]] } on-error={ :set uMac "" }

        :local uIp ""
        :do { :set uIp [:tostr [/ip hotspot active get $s address]] } on-error={ :set uIp "" }

        :local uUptime ""
        :do { :set uUptime [:tostr [/ip hotspot active get $s uptime]] } on-error={ :set uUptime "" }

        :local uServer ""
        :do { :set uServer [:tostr [/ip hotspot active get $s server]] } on-error={ :set uServer "" }

        :local uBytesIn ""
        :do { :set uBytesIn [/ip hotspot active get $s bytes-in] } on-error={ :set uBytesIn "" }

        :local uBytesOut ""
        :do { :set uBytesOut [/ip hotspot active get $s bytes-out] } on-error={ :set uBytesOut "" }

        :local uProfile ""
        :do {
            :local uid [/ip hotspot user find where name=$uUser]
            :if ([:len $uid] > 0) do={
                :set uProfile [:tostr [/ip hotspot user get [:pick $uid 0] profile]]
            }
        } on-error={ :set uProfile "" }

        :local bytesInField "null"
        :if ($uBytesIn != "") do={ :set bytesInField [:tostr $uBytesIn] }

        :local bytesOutField "null"
        :if ($uBytesOut != "") do={ :set bytesOutField [:tostr $uBytesOut] }

        :if ($isFirst = false) do={ :set usersJson ($usersJson . ",") }
        :set isFirst false

        :set usersJson ($usersJson . "{\"user\":\"" . [$jsonEscape $uUser] . \
            "\",\"mac\":\"" . [$jsonEscape $uMac] . \
            "\",\"ip\":\"" . [$jsonEscape $uIp] . \
            "\",\"profile\":\"" . [$jsonEscape $uProfile] . \
            "\",\"uptime\":\"" . [$jsonEscape $uUptime] . \
            "\",\"bytes_in\":" . $bytesInField . \
            ",\"bytes_out\":" . $bytesOutField . \
            ",\"server\":\"" . [$jsonEscape $uServer] . "\"}")
    }

    :set usersJson ($usersJson . "]")

    # -------------------------------------------------------------------
    # 3) Assemblage du payload final et envoi (compatibilite conservee :
    #    HTTPS, POST, en-tete X-API-Key, meme route api.php?route=push-status
    #    - voir §2.4 du brief).
    # -------------------------------------------------------------------
    :local payload ("{\"active\":" . $activeCount . \
        ",\"router\":" . $routerJson . \
        ",\"users\":" . $usersJson . "}")

    /tool fetch url=$apiUrl http-method=post \
        http-header-field=("Content-Type: application/json,X-API-Key: " . $apiKey) \
        http-data=$payload keep-result=no

    :log info ("ARA-Tech: push-status envoye (" . $activeCount . " session(s)).")

} on-error={
    :log warning "ARA-Tech: echec du push-status (voir /log pour le detail)."
}

# =============================================================================
# INSTALLATION
# =============================================================================
# 1) Copier ce script en tant que script RouterOS nomme "push-hotspot-status" :
#
#    /system script add name=push-hotspot-status source=[/file get [/file find name="push-hotspot-status.rsc"] contents]
#
#    (ou coller directement le contenu ci-dessus dans Winbox > System > Scripts
#    > "+", Name = push-hotspot-status)
#
# 2) Planifier son execution toutes les 30 secondes (aligne sur
#    meta.refresh_interval renvoye par GET api.php?route=status) :
#
#    /system scheduler add name=ara-tech-push-status interval=30s \
#        on-event="/system script run push-hotspot-status" \
#        comment="ARA Tech - push statut hotspot toutes les 30s"
#
# 3) Tester manuellement avant d'activer le planificateur :
#
#    /system script run push-hotspot-status
#    /log print where message~"ARA-Tech"
# =============================================================================
