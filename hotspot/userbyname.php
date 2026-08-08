<?php
/*
 *  Copyright (C) 2018 Laksamadi Guko.
 *
 *  This program is free software; you can redistribute it and/or modify
 *  it under the terms of the GNU General Public License as published by
 *  the Free Software Foundation; either version 2 of the License, or
 *  (at your option) any later version.
 *
 *  This program is distributed in the hope that it will be useful,
 *  but WITHOUT ANY WARRANTY; without even the implied warranty of
 *  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *  GNU General Public License for more details.
 *
 *  You should have received a copy of the GNU General Public License
 *  along with this program.  If not, see <http://www.gnu.org/licenses/>.
 */
session_start();
// hide all error
error_reporting(0);
if (!isset($_SESSION["mikhmon"])) {
  header("Location:../admin.php?id=login");
} else {

  $getallqueue = $API->comm("/queue/simple/print", array(
    "?dynamic" => "false",
  ));

  $getpool = $API->comm("/ip/pool/print");

  if (isset($_POST['name'])) {
    $name = (preg_replace('/\s+/', '-',$_POST['name']));
    $sharedusers = ($_POST['sharedusers']);
    $ratelimit = ($_POST['ratelimit']);
    $expmode = ($_POST['expmode']);
    $validity = ($_POST['validity']);
    $graceperiod = ($_POST['graceperiod']);
    $getprice = ($_POST['price']);
    $getsprice = ($_POST['sprice']);
    $addrpool = ($_POST['ppool']);
    if ($getprice == "") {
      $price = "0";
    } else {
      $price = $getprice;
    }
    if ($getsprice == "") {
      $sprice = "0";
    } else {
      $sprice = $getsprice;
    }
    $getlock = ($_POST['lockunlock']);
    if ($getlock == "Enable") {
      $lock = '; [:local mac $"mac-address"; /ip hotspot user set mac-address=$mac [find where name=$user]]';
    } else {
      $lock = "";
    }

    $randstarttime = "0".rand(1,5).":".rand(10,59).":".rand(10,59);
    $randinterval = "00:02:".rand(10,59);

    $parent = ($_POST['parent']);
    
    $record = '; :local mac $"mac-address"; :local time [/system clock get time ]; /system script add name="$date-|-$time-|-$user-|-'.$price.'-|-$address-|-$mac-|-' . $validity . '-|-'.$name.'-|-$comment" owner="$month$year" source="$date" comment="mikhmon"';
    
// ===== UPDATED on-login script (compatible with v7) =====
$onlogin = ':put (",'.$expmode.',' . $price . ',' . $validity . ','.$sprice.',,' . $getlock . ',"); {:local comment [ /ip hotspot user get [/ip hotspot user find where name="$user"] comment]; :local ucode [:pick $comment 0 2]; :if ($ucode = "vc" or $ucode = "up" or $comment = "") do={ :local date [ /system clock get date ]; :local time [ /system clock get time ]; /sys sch add name="$user" disable=no start-date=$date interval="' . $validity . '"; :delay 5s; :local exp [ /sys sch get [ /sys sch find where name="$user" ] next-run]; :delay 5s; /sys sch remove [find where name="$user"]; /ip hotspot user set comment="$exp" [find where name="$user"]; }}';

    if ($expmode == "rem") {
      $onlogin = $onlogin . $lock . "}}";
      $mode = "remove";
    } elseif ($expmode == "ntf") {
      $onlogin = $onlogin . $lock . "}}";
      $mode = "set limit-uptime=1s";
    } elseif ($expmode == "remc") {
      $onlogin = $onlogin . $record . $lock . "}}";
      $mode = "remove";
    } elseif ($expmode == "ntfc") {
      $onlogin = $onlogin . $record . $lock . "}}";
      $mode = "set limit-uptime=1s";
    } elseif ($expmode == "0" && $price != "") {
      $onlogin = ':put (",,' . $price . ',,,noexp,' . $getlock . ',")' . $lock;
    } else {
      $onlogin = "";
    }

// ===== UPDATED bgservice script (v7‑compatible) =====
$bgservice = ':local dateint do={:local year [:pick $d 0 4];:local month [:pick $d 5 7];:local day [:pick $d 8 10];:return ($year * 10000 + $month * 100 + $day);}; :local timeint do={:local hours [:pick $t 0 2];:local minutes [:pick $t 3 5];:local seconds [:pick $t 6 8];:return ($hours * 10000 + $minutes * 100 + $seconds);}; :local date [ /system clock get date ]; :local time [ /system clock get time ]; :local today [$dateint d=$date] ; :local curtime [$timeint t=$time] ; :foreach i in [ /ip hotspot user find where profile="'.$name.'" ] do={ :local comment [ /ip hotspot user get $i comment]; :local name [ /ip hotspot user get $i name]; :if ([:pick $comment 0 3] = "vc-" or [:pick $comment 0 3] = "up-") do={ :local expstr [:pick $comment 3]; :local expdate [:pick $expstr 0 10]; :local exptime [:pick $expstr 11 19]; :local expd [$dateint d=$expdate] ; :local expt [$timeint t=$exptime] ; :if (($expd < $today) or ($expd = $today and $expt < $curtime)) do={ [ /ip hotspot user '.$mode.' $i ]; [ /ip hotspot active remove [find where user=$name] ];}}}';

    $API->comm("/ip/hotspot/user/profile/add", array(
      "name" => "$name",
      "address-pool" => "$addrpool",
      "rate-limit" => "$ratelimit",
      "shared-users" => "$sharedusers",
      "status-autorefresh" => "1m",
      "on-login" => "$onlogin",
      "parent-queue" => "$parent",
    ));

    if($expmode != "0"){
      if (empty($monid)){
        $API->comm("/system/scheduler/add", array(
          "name" => "$name",
          "start-time" => "$randstarttime",
          "interval" => "$randinterval",
          "on-event" => "$bgservice",
          "disabled" => "no",
          "comment" => "Monitor Profile $name",
          ));
      }else{
      $API->comm("/system/scheduler/set", array(
        ".id" => "$monid",
        "name" => "$name",
        "start-time" => "$randstarttime",
        "interval" => "$randinterval",
        "on-event" => "$bgservice",
        "disabled" => "no",
        "comment" => "Monitor Profile $name",
        ));
      }}else{
        $API->comm("/system/scheduler/remove", array(
          ".id" => "$monid"));
      }

    $getprofile = $API->comm("/ip/hotspot/user/profile/print", array(
      "?name" => "$name",
    ));
    $pid = $getprofile[0]['.id'];
    echo "<script>window.location='./?user-profile=" . $pid . "&session=" . $session . "'</script>";
  }
}
?>

<script>
  function PassUser(){
    var x = document.getElementById('passUser');
    if (x.type === 'password') {
    x.type = 'text';
    } else {
    x.type = 'password';
    }}
    var _0x7baa=["\x63\x6C\x69\x63\x6B","\x2E\x70\x72\x69\x6E\x74\x42\x54","\x72\x65\x61\x64\x79"];$(document)[_0x7baa[2]](function(){$(_0x7baa[1])[_0x7baa[0]](function(){printBT()})})
</script>
<div class="row">
<div class="col-12"></div>
<div class="card">
<div class="card-header">
    <h3><i class="fa fa-edit"></i> <?php  echo $_edit_user.' '.$uname.' '; if ($utimelimit == "1s") {  echo $_expired;}?></h3>
</div>
<div class="card-body">
<form autocomplete="new-password" method="post" action="">
  <div>
    <?php if ($_SESSION['ubp'] != "") {
      echo "    <a class='btn bg-warning' href='./?hotspot=users&profile=" . $_SESSION['ubp'] . "&session=" . $session . "'><i class='fa fa-close'></i> ".$_close."</a>";
    } elseif ($_SESSION['ubc'] != "") {
      echo "    <a class='btn bg-warning' href='./?hotspot=users&comment=" . $_SESSION['ubc'] . "&session=" . $session . "'><i class='fa fa-close'></i> ".$_close."</a>";
    } elseif ($_SESSION['hua'] != "") {
      $_SESSION['ubn'] = "";
      echo "    <a class='btn bg-warning' href='./?hotspot=active&session=" . $session . "'><i class='fa fa-close'></i> ".$_close."</a>";
      $_SESSION['hua'] = "";
    } elseif ($_SESSION['ubn'] != "") {
      echo "    <a class='btn bg-warning' href='./?hotspot=users&profile=all&session=" . $session . "'><i class='fa fa-close'></i> ".$_close."</a>";
      $_SESSION['ubn'] = "";
    } else {
      echo "    <a class='btn bg-warning' href='./?hotspot=users&profile=all&session=" . $session . "'><i class='fa fa-close'></i> ".$_close."</a>";
    }
    ?>
    <button type="submit" name="save" class="btn bg-primary" > <i class="fa fa-save"></i> <?= $_save ?></button>
    <div class="btn bg-danger"  onclick="if(confirm('Are you sure to delete username (<?= $uname; ?>)?')){loadpage('./?remove-hotspot-user=<?= $uid; ?>&session=<?= $session; ?>')}else{}" title='Remove <?= $uname; ?>'><i class='fa fa-minus-square'></i> <?= $_remove ?></div>
    <a class="btn bg-secondary"  title="Print" href="javascript:window.open('./voucher/print.php?user=<?= $usermode . "-" . $uname; ?>&qr=no&session=<?= $session; ?>','_blank','width=310,height=450').print();"> <i class="fa fa-print"></i> <?= $_print ?></a>
    <a class="btn bg-info"  title="Print QR" href="javascript:window.open('./voucher/print.php?user=<?= $usermode . "-" . $uname; ?>&qr=yes&session=<?= $session; ?>','_blank','width=310,height=450').print();"> <i class="fa fa-qrcode"></i> <?= $_print_qr ?></a>
    <?php if ($utimelimit == "1s") {
      echo '<a class="btn bg-info"  href="./?reset-hotspot-user=' . $uid . '&session=' . $session . '"> <i class="fa fa-retweet"></i> Reset</a>';
    } ?>
    <a id="shareWA" class="btn bg-green" title="Share WhatsApp" href="whatsapp://send?text=<?= $shareWA; ?>"> <i class="fa fa-whatsapp"></i> <?= $_share ?></a>
    <!--<div id="shareWA" class="btn bg-blue printBT" title="Print Bluetooth"><i class="fa fa-bluetooth"></i> <?= $_print ?> BT</div>-->
    <div id="shareWA" class="btn bg-blue" onclick="sendToQuickPrinterChrome()" title="Print Bluetooth"><i class="fa fa-bluetooth"></i> <?= $_print ?> BT</div><br>
  </div>
<table class="table">
  <tr>
    <td class="align-middle">Enabled</td>
    <td>
			<select class="form-control" name="disabled" required="1">
				<option value="<?= $ueduser; ?>"><?php if ($ueduser == "true") {
          echo "No";
        } else {
          echo "Yes";
        } ?></option>
				<option value="no">Yes</option>
				<option value="yes">No</option>
			</select>
    </td>
  </tr>
  <tr>
    <td class="align-middle">Server</td>
    <td>
			<select class="form-control" name="server" required="1">
				<option><?php if ($userver == "") {
  echo "all";
} else {
  echo $userver;
} ?></option>
				<option>all</option>
				<?php $TotalReg = count($srvlist);
    for ($i = 0; $i < $TotalReg; $i++) {
      echo "<option>" . $srvlist[$i]['name'] . "</option>";
    }
    ?>
			</select>
		</td>
	</tr>
  <tr>
    <td class="align-middle"><?= $_name ?></td><td><input id="name" class="form-control" type="text" autocomplete="off" name="name" value="<?= $uname; ?>"></td>
  </tr>
  <tr>
    <td class="align-middle"><?= $_password ?></td><td>
	<div class="input-group">
    <div class="input-group-11 col-box-10">
      <input class="group-item group-item-l" id="passUser" type="password" name="pass" autocomplete="new-password" value="<?= $upass; ?>">
    </div>
          <div class="input-group-1 col-box-2">
<div class="group-item group-item-r pd-2p5 text-center">
  <input title="Show/Hide Password" type="checkbox" onclick="PassUser()">
</div>
          </div>
  </div>
		</td>
  </tr>
  <tr>
    <td class="align-middle"><?= $_profile ?></td><td>
			<select class="form-control" name="profile" required="1">
				<option><?= $uprofile; ?></option>
				<?php $TotalReg = count($getprofile);
    for ($i = 0; $i < $TotalReg; $i++) {
      echo "<option>" . $getprofile[$i]['name'] . "</option>";
    }
    ?>
			</select>
		</td>
	</tr>
  <tr>
    <td class="align-middle">Mac Address</td><td><input class="form-control" type="text" value="<?= $umac; ?>"></td>
  </tr>
  <tr>
    <td class="align-middle">Uptime</td><td><input class="form-control" type="text" value="<?php if ($uuptime == 0) {
      } else {
        echo $uuptime;
      } ?>" disabled></td>
  </tr>
  <tr>
    <td class="align-middle">Bytes  In / Out</td><td><input class="form-control" type="text" value="<?php if ($ubytesout == 0) {
    } else {
      echo formatBytes($ubytesin, 2);
    } ?> / <?php if ($ubytesout == 0) {
    } else {
          echo formatBytes($ubytesout, 2);
    } ?>" disabled></td>
  </tr>
  <tr>
    <td class="align-middle"><?= $_time_limit ?></td><td><input id="timelimit" class="form-control" type="text" size="4" autocomplete="off" name="timelimit" value="<?php if ($utimelimit == "1s") {echo "";} else {echo $utimelimit;} ?>"></td>
  </tr>
  <tr>
    <td class="align-middle"><?= $_data_limit ?></td><td>
      <div class="input-group">
        <div class="input-group-10 col-box-9">
        <input class="group-item group-item-l" type="number" min="0" max="9999" id="datalimit" name="datalimit" value="<?= $udatalimit; ?>">
      </div>
          <div class="input-group-2 col-box-3">
              <select style="padding: 4.2px;" class="group-item group-item-r" id="mbgb" name="mbgb" required="1">
				        <option value="<?php if ($MG == "MB") {echo "1048576";  } elseif ($MG == "GB") {echo "1073741824";  } ?>"><?= $MG; ?></option>
				        <option value="1048576">MB</option>
				        <option value="1073741824">GB</option>
			        </select>
          </div>
      </div>
    </td>
  </tr>
  <tr>
    <td class="align-middle"><?= $_tcomment ?></td><td><input class="form-control" type="text" id="comment" autocomplete="off" name="comment" title="No special characters" value="<?= $ucomment; ?>" <?= $commt ?>><input type="hidden" name="h_comment" value="<?= $ucomment ?>"></td>
  </tr>
  <tr>
  <tr <?=$display?>>
    <td class="align-middle"><?= $_tcomment2 ?></td><td><input class="form-control" type="<?= $comment2t ;?>" id="comment2" autocomplete="off" name="comment2" title="No special characters" value="<?= $ucomment2; ?>"></td>
  </tr>
  <tr>
    <td class="align-middle"><?= $_price ?></td><td><input class="form-control" id="price" type="text" value="<?php if ($getprice == 0) {
      } else {
        if ($currency == in_array($currency, $cekindo['indo'])) {
          echo $currency . " " . number_format((float)$getprice, 0, ",", ".");
        } else {
          echo $currency . " " . number_format((float)$getprice);
        }
      } ?>" disabled></td>
  </tr>
  <tr>
    <td class="align-middle"><?= $_selling_price ?></td><td><input class="form-control" id="price" type="text" value="<?php if ($getprice == 0) {
      } else {
        if ($currency == in_array($currency, $cekindo['indo'])) {
          echo $currency . " " . number_format((float)$getsprice, 0, ",", ".");
        } else {
          echo $currency . " " . number_format((float)$getsprice);
        }
      } ?>" disabled></td>
  </tr>
  <?php if ($getvalid != "") { ?>
  <tr>
    <td class="align-middle"><?= $_validity ?></td><td><input class="form-control" type="text" id="validity" value="<?= $getvalid; ?>" disabled></td>
  </tr>
  <?php
} else {
} ?>
  <tr>
    <td colspan="2">
      <p style="padding:0px 5px;">
        <?= $_format_time_limit ?>
      </p>
    </td>
  </tr>
</table>
</form>
</div>
</div>
</div>
</div>