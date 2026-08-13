# Hotspot V2.1 Phase H3 - Command worker (RouterOS v7 with :deserialize JSON)
# Configure these two values after import.
:global hotspotApiUrl "https://aratech-ldg0.onrender.com/api.php"
:global hotspotSyncKey "REPLACE_WITH_ROTATED_HOTSPOT_SYNC_KEY"

:local routerIdentity [/system identity get name]
:local pendingUrl ($hotspotApiUrl . "?route=hotspot-commands-pending&router_identity=" . $routerIdentity)
:local fetchResult ""

:do {
    :set fetchResult [/tool fetch url=$pendingUrl mode=https http-method=get http-header-field=("X-API-Key: " . $hotspotSyncKey) output=user as-value]
} on-error={
    :log warning "hotspot-command-worker: pending fetch failed"
    :return
}

:local body ($fetchResult->"data")
:if ([:typeof $body] = "nothing" || [:len $body] = 0) do={ :return }

:local decoded [:deserialize from=json value=$body]
:if (($decoded->"success") != true) do={
    :log warning "hotspot-command-worker: API returned failure"
    :return
}

:local items (($decoded->"data")->"items")
:foreach command in=$items do={
    :local commandId ($command->"id")
    :local action ($command->"action")
    :local payload ($command->"payload")
    :local username ($payload->"username")
    :local ok false
    :local msg "failed"

    :local isProfileAction (($action = "profile-create") || ($action = "profile-update") || ($action = "profile-delete"))

    :do {
        :if (($action != "create") && ($action != "update") && ($action != "enable") && ($action != "disable") && ($action != "delete") \
            && ($action != "profile-create") && ($action != "profile-update") && ($action != "profile-delete") && ($action != "disconnect")) do={ :error "unknown action" }
        :if ([:typeof $username] = "nothing" || [:len $username] = 0) do={ :error "username required" }

        # Pour les actions "profile-*", $username contient en réalité le NOM
        # DU PROFIL (identifiant générique réutilisé côté Supabase, voir
        # api.php). On ne recherche donc un utilisateur hotspot que pour les
        # actions qui ciblent réellement un utilisateur.
        :local ids ({})
        :if (!$isProfileAction && ($action != "disconnect")) do={
            :set ids [/ip hotspot user find where name=$username]
        }

        :if ($action = "create") do={
            :local password ($payload->"password")
            :if ([:typeof $password] = "nothing" || [:len $password] = 0) do={ :error "password required" }
            :if ([:len $ids] > 0) do={
                :set ok true; :set msg "already exists"
            } else={
                :local profile ($payload->"profile")
                :if ([:typeof $profile] = "nothing" || [:len $profile] = 0) do={ :set profile "default" }
                :local comment ($payload->"comment")
                :if ([:typeof $comment] = "nothing") do={ :set comment "" }

                :local limitUptime ($payload->"limit_uptime")
                :local limitBytes ($payload->"limit_bytes_total")
                :local hasUptime false
                :local hasBytes false
                :if ([:typeof $limitUptime] != "nothing" && [:len [:tostr $limitUptime]] > 0) do={ :set hasUptime true }
                :if ([:typeof $limitBytes] != "nothing" && [:len [:tostr $limitBytes]] > 0) do={ :set hasBytes true }

                :if ($hasUptime && $hasBytes) do={
                    /ip hotspot user add name=$username password=$password profile=$profile comment=$comment disabled=no limit-uptime=$limitUptime limit-bytes-total=$limitBytes
                } else={
                    :if ($hasUptime) do={
                        /ip hotspot user add name=$username password=$password profile=$profile comment=$comment disabled=no limit-uptime=$limitUptime
                    } else={
                        :if ($hasBytes) do={
                            /ip hotspot user add name=$username password=$password profile=$profile comment=$comment disabled=no limit-bytes-total=$limitBytes
                        } else={
                            /ip hotspot user add name=$username password=$password profile=$profile comment=$comment disabled=no
                        }
                    }
                }
                :set ok true; :set msg "created"
            }
        }

        :if ($action = "update") do={
            :if ([:len $ids] = 0) do={ :error "user not found" }
            :local id [:pick $ids 0]
            :local profile ($payload->"profile")
            :local password ($payload->"password")
            :local comment ($payload->"comment")
            :if ([:typeof $profile] != "nothing" && [:len $profile] > 0) do={ /ip hotspot user set $id profile=$profile }
            :if ([:typeof $password] != "nothing" && [:len $password] > 0) do={ /ip hotspot user set $id password=$password }
            :if ([:typeof $comment] != "nothing") do={ /ip hotspot user set $id comment=$comment }

            :local limitUptime ($payload->"limit_uptime")
            :if ([:typeof $limitUptime] != "nothing") do={
                :if ([:tostr $limitUptime] = "") do={
                    /ip hotspot user set $id limit-uptime=""
                } else={
                    /ip hotspot user set $id limit-uptime=$limitUptime
                }
            }
            :local limitBytes ($payload->"limit_bytes_total")
            :if ([:typeof $limitBytes] != "nothing") do={
                /ip hotspot user set $id limit-bytes-total=$limitBytes
            }
            :set ok true; :set msg "updated"
        }

        :if ($action = "enable") do={
            :if ([:len $ids] = 0) do={ :error "user not found" }
            /ip hotspot user set [:pick $ids 0] disabled=no
            :set ok true; :set msg "enabled"
        }

        :if ($action = "disable") do={
            :if ([:len $ids] = 0) do={ :error "user not found" }
            /ip hotspot user set [:pick $ids 0] disabled=yes
            :set ok true; :set msg "disabled"
        }

        :if ($action = "delete") do={
            :if ([:len $ids] = 0) do={
                :set ok true; :set msg "already absent"
            } else={
                /ip hotspot user remove [:pick $ids 0]
                :set ok true; :set msg "deleted"
            }
        }

        # -------------------------------------------------------------
        # Extension "Profils + Déconnexion" (routeur derrière CGNAT :
        # exécutées ici en pull, jamais en connexion entrante Render->routeur)
        # -------------------------------------------------------------
        :if ($action = "profile-create") do={
            :local pids [/ip hotspot user profile find where name=$username]
            :if ([:len $pids] > 0) do={
                :set ok true; :set msg "already exists"
            } else={
                /ip hotspot user profile add name=$username
                :local pid [/ip hotspot user profile find where name=$username]
                :local sharedUsers ($payload->"shared_users")
                :local rateLimit ($payload->"rate_limit")
                :local onLogin ($payload->"on_login")
                :local addressPool ($payload->"address_pool")
                :if ([:typeof $sharedUsers] != "nothing" && [:len [:tostr $sharedUsers]] > 0) do={ /ip hotspot user profile set $pid shared-users=$sharedUsers }
                :if ([:typeof $rateLimit] != "nothing" && [:len $rateLimit] > 0) do={ /ip hotspot user profile set $pid rate-limit=$rateLimit }
                :if ([:typeof $onLogin] != "nothing" && [:len $onLogin] > 0) do={ /ip hotspot user profile set $pid on-login=$onLogin }
                :if ([:typeof $addressPool] != "nothing" && [:len $addressPool] > 0) do={ /ip hotspot user profile set $pid address-pool=$addressPool }
                :set ok true; :set msg "created"
            }
        }

        :if ($action = "profile-update") do={
            :local pids [/ip hotspot user profile find where name=$username]
            :if ([:len $pids] = 0) do={ :error "profile not found" }
            :local pid [:pick $pids 0]
            :local sharedUsers ($payload->"shared_users")
            :local rateLimit ($payload->"rate_limit")
            :local onLogin ($payload->"on_login")
            :local addressPool ($payload->"address_pool")
            :if ([:typeof $sharedUsers] != "nothing" && [:len [:tostr $sharedUsers]] > 0) do={ /ip hotspot user profile set $pid shared-users=$sharedUsers }
            :if ([:typeof $rateLimit] != "nothing") do={ /ip hotspot user profile set $pid rate-limit=$rateLimit }
            :if ([:typeof $onLogin] != "nothing") do={ /ip hotspot user profile set $pid on-login=$onLogin }
            :if ([:typeof $addressPool] != "nothing") do={ /ip hotspot user profile set $pid address-pool=$addressPool }
            :set ok true; :set msg "updated"
        }

        :if ($action = "profile-delete") do={
            :local pids [/ip hotspot user profile find where name=$username]
            :if ([:len $pids] = 0) do={
                :set ok true; :set msg "already absent"
            } else={
                /ip hotspot user profile remove [:pick $pids 0]
                :set ok true; :set msg "deleted"
            }
        }

        :if ($action = "disconnect") do={
            :local sids [/ip hotspot active find where user=$username]
            :if ([:len $sids] = 0) do={
                :set ok true; :set msg "already offline"
            } else={
                /ip hotspot active remove [:pick $sids 0]
                :set ok true; :set msg "disconnected"
            }
        }
    } on-error={
        :set ok false
        :set msg "router error"
    }

    :local ackJson ("{\"command_id\":" . $commandId . ",\"success\":" . $ok . ",\"message\":\"" . $msg . "\"}")
    :do {
        /tool fetch url=($hotspotApiUrl . "?route=hotspot-command-ack") mode=https http-method=post http-header-field=("Content-Type: application/json,X-API-Key: " . $hotspotSyncKey) http-data=$ackJson output=none
    } on-error={
        :log warning ("hotspot-command-worker: ACK failed command_id=" . $commandId)
    }
}
