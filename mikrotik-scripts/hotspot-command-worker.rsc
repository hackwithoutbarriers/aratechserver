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

    :do {
        :if (($action != "create") && ($action != "update") && ($action != "enable") && ($action != "disable") && ($action != "delete")) do={ :error "unknown action" }
        :if ([:typeof $username] = "nothing" || [:len $username] = 0) do={ :error "username required" }
        :local ids [/ip hotspot user find where name=$username]

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
