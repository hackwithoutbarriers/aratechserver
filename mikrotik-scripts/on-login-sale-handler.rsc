# =============================================================================
# ARA Tech WiFi - HotSpot on-login sale handler
# -----------------------------------------------------------------------------
# A LOGIN IS NOT A SALE.
#
# The old handler called log-sale and created a Mikhmon history script on every
# login/re-login. That was the root cause of inflated or inconsistent business
# KPIs.
#
# This handler:
#   1. Detects a fresh activation (vc/up/empty comment).
#   2. Updates expiry and syncs expiry on every login.
#   3. Records ONE business transaction only for the fresh activation.
#   4. Creates ONE Mikhmon history script only for the fresh activation.
#   5. Uses an event-based transaction_id, so the same username can be sold
#      again later without being collapsed as a duplicate.
#
# PRICE MAP (verify before production):
#   10H        = 100 XOF
#   24H        = 200 XOF
#   Abonnement = 1000 XOF (observed in current export; change if your current
#                commercial price is different)
#   test/demo  = NOT a business sale
#
# Replace REPLACE_WITH_ROTATED_HOTSPOT_SYNC_KEY before installing.
# =============================================================================

:local userId [/ip hotspot user find where name="$user"]
:if ([:len $userId] = 0) do={
    :log warning ("ARA Tech: utilisateur Hotspot introuvable: " . $user)
    :return
}

:local userComment [:tostr [/ip hotspot user get [:pick $userId 0] comment]]
:local userProfile [:tostr [/ip hotspot user get [:pick $userId 0] profile]]
:local ucode [:pick $userComment 0 2]
:local isNewSale false

# -----------------------------------------------------------------------------
# 1. Detect a fresh activation.
# -----------------------------------------------------------------------------
:if ($ucode = "vc" or $ucode = "up" or $userComment = "") do={
    :set isNewSale true

    :local date [/system clock get date]
    /system scheduler add name=$user disable=no start-date=$date interval="24h"
    :delay 2s

    :local schedulerId [/system scheduler find where name="$user"]
    :local exp ""
    :if ([:len $schedulerId] > 0) do={
        :set exp [:tostr [/system scheduler get [:pick $schedulerId 0] next-run]]
        /system scheduler remove $schedulerId
    }

    :if ($exp != "") do={
        /ip hotspot user set comment=$exp [:pick $userId 0]
        :set userComment $exp
    }
}

# -----------------------------------------------------------------------------
# 2. Expiry synchronization remains allowed on every login/re-login.
# -----------------------------------------------------------------------------
:if ($userComment != "") do={
    :do {
        /tool fetch mode=https http-method=post duration=6s \
            http-header-field="Content-Type:application/json,X-API-Key:REPLACE_WITH_ROTATED_HOTSPOT_SYNC_KEY" \
            http-data=("{\"user\":\"" . $user . "\",\"expiry\":\"" . $userComment . "\"}") \
            url="https://aratech-ldg0.onrender.com/api.php?route=set-expiry" \
            check-certificate=yes output=none
    } on-error={
        :log warning ("ARA Tech: sync expiry echoue pour " . $user)
    }
}

# -----------------------------------------------------------------------------
# 3. Commercial event — ONLY once per fresh activation.
# -----------------------------------------------------------------------------
:if ($isNewSale = true) do={
    :local saleDate [/system clock get date]
    :local saleTime [/system clock get time]
    :local saleMac $"mac-address"
    :local saleAmount 0
    :local isBusinessSale true

    :if ($userProfile = "10H") do={ :set saleAmount 100 }
    :if ($userProfile = "24H") do={ :set saleAmount 200 }
    :if ($userProfile = "Abonnement") do={ :set saleAmount 1000 }

    :if ($userProfile = "test" or $userProfile = "testing" or $userProfile = "demo") do={
        :set saleAmount 0
        :set isBusinessSale false
    }

    :if ($saleAmount > 0 and $isBusinessSale = true) do={
        # Stable event id: a retry of the same event is idempotent; a later sale
        # of the same username gets a different timestamp and therefore a new id.
        :local transactionId ($saleDate . "|" . $saleTime . "|" . $user . "|" . $saleAmount . "|" . $address . "|" . $saleMac . "|" . $userProfile . "|" . $userComment)

        :local saleJson ("{\"transaction_id\":\"" . $transactionId . "\",\"date\":\"" . $saleDate . "\",\"time\":\"" . $saleTime . "\",\"user\":\"" . $user . "\",\"amount\":" . $saleAmount . ",\"ip\":\"" . $address . "\",\"mac\":\"" . $saleMac . "\",\"profile\":\"" . $userProfile . "\",\"comment\":\"" . $userComment . "\",\"is_business_sale\":true}")

        :do {
            /tool fetch mode=https duration=6s http-method=post \
                url="https://aratech-ldg0.onrender.com/record-sale.php" \
                http-header-field="Content-Type:application/json,X-API-Key:REPLACE_WITH_ROTATED_HOTSPOT_SYNC_KEY" \
                http-data=$saleJson check-certificate=yes output=none
        } on-error={
            :log warning ("ARA Tech: enregistrement vente echoue pour " . $user)
        }

        # Preserve the Mikhmon history mechanism, but ONLY for actual sales.
        :local scriptName ($saleDate . "-| -" . $saleTime . "-| -" . $user . "-| -" . $saleAmount . "-| -" . $address . "-| -" . $saleMac . "-| -" . $userProfile . "-| -" . $userComment)
        /system script add name=$scriptName owner="mikhmon" source=$saleDate comment="mikhmon"
    }
}
