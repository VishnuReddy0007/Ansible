<?php
use App\Jobs\ReportLib;
use App\Models\CRMCall;
use App\Models\User;
//->where("user_id","")
$logdate       = isset($_GET['logdate']) ? $_GET['logdate'] : date('Y-m-d');
$userlogs      = DB::table("userlogs")->where('created_at', '>', $logdate . " 00:00:00")->where('created_at', '<', $logdate . " 23:59:59")->orderByRaw("user_id,created_at ASC")->get();
$serverip      = env("app_ip");
$dashboarduser = Auth::user();
$timeoffset    = $dashboarduser->timezone * 60;
$reportdata    = array();
function calls($reportdata, $id, $k)
{
    //CRMCall:: DB::table("crmcalls2")->
  // echo $k."---".$reportdata[$id][$k]["Start Time"]."---".$reportdata[$id][$k]["End Time"]."<br>";

    $calls = CRMCall::where("user_id", $id)->where('created_at', ">=", $reportdata[$id][$k]["Start Time"])->where('updated_at', "<=", $reportdata[$id][$k]["End Time"])->where('sipid_id', '>', '0')->select('created_at', 'updated_at', 'number', 'crm_id', 'ts_Wait', 'ts_Close', 'ts_Force', 'id', 'type', 'ts_Call', 'crm_id', "userstatus", "state", "sipid_id")->orderBy('created_at', 'asc')->get();
    if (!empty($calls[0])) {
        // echo "--data<br>";
        $lastCall_id = 0;
        $lastCall    = array_filter(array_column($reportdata[$id], "Number"), 'strlen');
        if (!empty($lastCall)) {
            end($lastCall);
            $lastCall_key = key($lastCall);
            if (array_key_exists("callid", $reportdata[$id][$lastCall_key])) {
                $lastCall_id = $reportdata[$id][$lastCall_key]["callid"];
                if ($lastCall_id > 0) {
                    $calls2  = CRMCall::where("user_id", $id)->where('id', ">", $lastCall_id)->where('id', "<", $calls[0]->id)->whereNotIn('id', [$lastCall_id, $calls[0]->id])->where('sipid_id', '>', '0')->select('created_at', 'updated_at', 'number', 'crm_id', 'ts_Wait', 'ts_Close', 'ts_Force', 'id', 'type', 'ts_Call', 'crm_id', "userstatus", "state", "sipid_id")->orderBy('created_at', 'asc')->get();
                    $result1 = array();
                    if (!empty($calls2[0])) {
                        $calls   = json_decode(json_encode($calls), true);
                        $calls2  = json_decode(json_encode($calls2), true);
                        $result1 = array_merge($calls, $calls2);
                        $starttimesort = array_column($result1, 'created_at');
                        array_multisort($starttimesort, SORT_ASC, $result1);
                        $calls   = json_decode(json_encode($result1), false);

                    }
                }
            }
        }
    }
    if (!empty($calls[0])) {
        $endtime     = $reportdata[$id][$k]["End Time"];
        $call_status = $reportdata[$id][$k]["Status"];
        $clientcode  = "";
        $c = 0;
        foreach ($calls as $cval) {
            $record_id=$cval->crm_id;
            if ($record_id > 0) {
                $res = DB::table("records")->where('id', $record_id)->select("clientcode")->first();
                if (!empty($res->clientcode)) {
                    $clientcode = $res->clientcode;
                }
            }
            $call_mode = $cval->type;
            if ($c > 0) {
                $k = array_keys($reportdata[$id])[count($reportdata[$id]) - 1] + 1;
            }
            $status = "";
            if ($cval->state != "Hangup" || $cval->userstatus == "") {
                $status = "Incall";
            }
            if ($call_mode == "Inbound") {
                $searchkey = (string) array_search(date("Y-m-d H:i:s", round($cval->ts_Wait / 1000)), array_column($reportdata[$id], 'Start Time'));
                if ($searchkey != "") {
                    if ($reportdata[$id][$searchkey]["Status"] == "Refresh Page") {
                        $reportdata[$id][$searchkey]['unset']="Y1";
                    }
                }
                $reportdata[$id][$k]["Start Time"]   = date("Y-m-d H:i:s", round($cval->ts_Wait / 1000));
                $reportdata[$id][$k]["Mode"]         = $call_mode;
                $reportdata[$id][$k]["Status"]       = "Wait";
                $reportdata[$id][$k]["End Time"]     = date("Y-m-d H:i:s", round($cval->ts_Call / 1000));
                $reportdata[$id][$k]["Number"]       = "";
                $reportdata[$id][$k]["Client Id"]    = "";
                $reportdata[$id][$k]["User Name"]    = $reportdata[$id][0]["User Name"];
                $reportdata[$id][$k]["User Id"]      = $reportdata[$id][0]["User Id"];
                $event_duration                      = date("H:i:s", (strtotime($reportdata[$id][$k]["End Time"]) - strtotime($reportdata[$id][$k]["Start Time"])));
                $reportdata[$id][$k]["Event Length"] = $event_duration;
                $k                                   = $k + 1;
                $reportdata[$id][$k]["Start Time"]   = date("Y-m-d H:i:s", round($cval->ts_Call / 1000));
                $reportdata[$id][$k]["Mode"]         = $call_mode;
                $reportdata[$id][$k]["Status"]       = (empty($status) ? "Called" : $status);
                $reportdata[$id][$k]["Number"]       = $cval->number;
                $reportdata[$id][$k]["callid"]       = $cval->id;
                $reportdata[$id][$k]["Client Id"]    = $clientcode;
                $reportdata[$id][$k]["User Name"]    = $reportdata[$id][0]["User Name"];
                $reportdata[$id][$k]["User Id"]      = $reportdata[$id][0]["User Id"];
            } else {
                $reportdata[$id][$k]["Start Time"]   = date("Y-m-d H:i:s", round($cval->ts_Wait / 1000));
                $reportdata[$id][$k]["Mode"]         = $call_mode;
                $reportdata[$id][$k]["Status"]       = "Preview";
                $reportdata[$id][$k]["End Time"]     = date("Y-m-d H:i:s", round($cval->ts_Call / 1000));
                $reportdata[$id][$k]["Number"]       = $cval->number;
                $reportdata[$id][$k]["callid"]       = $cval->id;
                $reportdata[$id][$k]["Client Id"]    = $clientcode;
                $reportdata[$id][$k]["User Name"]    = $reportdata[$id][0]["User Name"];
                $reportdata[$id][$k]["User Id"]      = $reportdata[$id][0]["User Id"];
                $event_duration                      = date("H:i:s", (strtotime($reportdata[$id][$k]["End Time"]) - strtotime($reportdata[$id][$k]["Start Time"])));
                $reportdata[$id][$k]["Event Length"] = $event_duration;
                $k                                   = $k + 1;
                $reportdata[$id][$k]["Start Time"]   = date("Y-m-d H:i:s", round($cval->ts_Call / 1000));
                $reportdata[$id][$k]["Mode"]         = $call_mode;
                $reportdata[$id][$k]["Status"]       = (empty($status) ? "Called" : $status);
                $reportdata[$id][$k]["Number"]       = $cval->number;
                $reportdata[$id][$k]["callid"]       = $cval->id;

                $reportdata[$id][$k]["Client Id"] = $clientcode;
                $reportdata[$id][$k]["User Name"] = $reportdata[$id][0]["User Name"];
                $reportdata[$id][$k]["User Id"]   = $reportdata[$id][0]["User Id"];
            }

            if (!empty($cval->ts_Force)) {
                $reportdata[$id][$k]["End Time"]     = date("Y-m-d H:i:s", round($cval->ts_Force / 1000));
                $event_duration                      = date("H:i:s", (strtotime($reportdata[$id][$k]["End Time"]) - strtotime($reportdata[$id][$k]["Start Time"])));
                $reportdata[$id][$k]["Event Length"] = $event_duration;
            } else {
                $reportdata[$id][$k]["End Time"]     = date("Y-m-d H:i:s", round($cval->ts_Close / 1000));
                $event_duration                      = date("H:i:s", (strtotime($reportdata[$id][$k]["End Time"]) - strtotime($reportdata[$id][$k]["Start Time"])));
                $reportdata[$id][$k]["Event Length"] = $event_duration;
            }
            $c++;
        }

        if (strtotime($reportdata[$id][$k]["End Time"]) < strtotime($endtime)) {
            $last                        = $k;
            $k                           = $k + 1;
            $reportdata[$id][$k]["Mode"] = $call_mode;
            if ($call_mode == "Inbound") {
                $reportdata[$id][$k]["Status"] = "Wait";
            } else {
                $reportdata[$id][$k]["Status"] = "Paused";
            }
            $reportdata[$id][$k]["Client Id"]    = "";
            $reportdata[$id][$k]["Number"]       = "";
            $reportdata[$id][$k]["Start Time"]   = $reportdata[$id][$last]["End Time"];
            $reportdata[$id][$k]["End Time"]     = $endtime;
            $reportdata[$id][$k]["User Name"]    = $reportdata[$id][0]["User Name"];
            $reportdata[$id][$k]["User Id"]      = $reportdata[$id][0]["User Id"];
            $event_duration                      = date("H:i:s", (strtotime($reportdata[$id][$k]["End Time"]) - strtotime($reportdata[$id][$k]["Start Time"])));
            $reportdata[$id][$k]["Event Length"] = $event_duration;
        }
    } 
    // else {
    //      $reportdata[$id][$k]['unset']="Y2";
    //       // echo "--NOdata<br>";
    //     //unset not working
    // }

    return $reportdata;

}
if (!empty($userlogs)) {
    $callMode  = array("Manual", "Progressive", "Inbound");
    $reportLib = new ReportLib();
    $userlogs  = json_decode(json_encode($userlogs), true);
    $userlist  = array_column($userlogs, "user_id");
    $userArr   = array_unique($userlist);
    $lastArr   = array_flip($userlist);
    if (!$dashboarduser->moduleACL("Admin", false, false, true)) {
        // User::=DB::table("users1")->
        $users = User::where(function ($query) use ($dashboarduser) {
            $query->where('supervisor', '=', $dashboarduser->username)
                ->orWhere('lteam2', '=', $dashboarduser->username)
                ->orWhere('lteam', '=', $dashboarduser->username);
        })->whereIN('id', $userArr)->where("usertype", "User")->select('id', 'username', 'fullname', 'presence')->where('status', 'Active')->orderBy("id", "asc")->get();
    } else {
        $users = User::where("usertype", "User")->whereIN('id', $userArr)->select('id', 'username', 'fullname', 'presence')->where('status', 'Active')->orderBy("id", "asc")->get();
    }
    $users   = json_decode(json_encode($users), true);
    $userArr = array_column($users, "id");
    foreach ($userlogs as $ulog) {
        $ulogid = $ulog['id'];
        $id     = $ulog['user_id'];
        $ukey   = (string) array_search($id, $userArr);
        if ($ukey == "") {
            continue;
        }
        $starttime = $ulog['startdate'] . " " . $ulog['starttime'];
        $duration  = $ulog["durationsec"];
        $endtime   = $ulog['enddate'] . " " . $ulog['endtime'];
        $data      = json_decode($ulog['data'], true);
        $sipcout   = 1;
        $j         = 0;
        if (!isset($reportdata[$id][0]["User Name"])) {
            $reportdata[$id][0]["User Name"] = $users[$ukey]["fullname"];
            $reportdata[$id][0]["User Id"]   = $users[$ukey]["username"];
            $reportdata[$id][0]["presence"]  = $users[$ukey]["presence"];
        }
        foreach ($data as $sip => $sdata) {
            $sip = (empty($sip) ? 0 : $sip);
            
            if (empty($sip)) {
                continue;
            }
            $pts = $sdata['ts'];
            if (isset($sdata['states']) && !empty($sdata['states'])) {
                end($sdata['states']);
                $lastts                        = key($sdata['states']);
                $reportdata[$id][0]['lastts']  = $lastts;
                $reportdata[$id][0]['startts'] = $pts;
                foreach ($sdata['states'] as $ts => $states) {
                    // echo date("Y-m-d H:i:s", round($ts / 1000))."--".$states[0]."---".$states[1]."--".date("Y-m-d H:i:s", round($pts / 1000));
                    // echo "<br>";
                    if ($states[1] == "WRAPUP") {
                        continue;
                    }
                    $newarr[$ts] = $states;
                } //end $sdata['states']
                if (!empty($newarr)) {
                    if (empty($reportdata[$id][0]["Status"])) {
                        $k = 0;
                    } else {
                        end($reportdata[$id]);
                        $k = key($reportdata[$id]) + 1;
                    }
                    if ($j == 0) {
                        $reportdata[$id][$k]["Status"]       = "Login";
                        $reportdata[$id][$k]["Mode"]         = "Not Ready";
                        $reportdata[$id][$k]["Start Time"]   = $starttime;
                        $firstts                             = array_keys($newarr)[0];
                        $reportdata[$id][$k]["End Time"]     = date("Y-m-d H:i:s", round($firstts / 1000));
                        $reportdata[$id][$k]["Client Id"]    = "";
                        $reportdata[$id][$k]["Number"]       = "";
                        $reportdata[$id][$k]["User Name"]    = $reportdata[$id][0]["User Name"];
                        $reportdata[$id][$k]["User Id"]      = $reportdata[$id][0]["User Id"];
                        $event_duration                      = date("H:i:s", (strtotime($reportdata[$id][$k]["End Time"]) - strtotime($reportdata[$id][$k]["Start Time"])));
                        $reportdata[$id][$k]['sip']          = $sip;
                        $reportdata[$id][$k]["Event Length"] = $event_duration;
                    }

                    foreach ($newarr as $nts => $nstate) {
                        end($reportdata[$id]);
                        $k    = key($reportdata[$id]) + 1;
                        $last = $k - 1;
                        if (!isset($reportdata[$id][$last]["End Time"])) {
                            // echo $nts."+".$reportdata[$id][$last]["Mode"];
                            $reportdata[$id][$last]["End Time"]     = date("Y-m-d H:i:s", round($nts / 1000));
                            $event_duration                         = date("H:i:s", (strtotime($reportdata[$id][$last]["End Time"]) - strtotime($reportdata[$id][$last]["Start Time"])));
                            $reportdata[$id][$last]["Event Length"] = $event_duration;

                            if (in_array($reportdata[$id][$last]["Mode"], $callMode)) {
                                // echo "here";
                                $reportdata = calls($reportdata, $id, $last);
                                end($reportdata[$id]);
                                $k = key($reportdata[$id]) + 1;
                            }
                            // echo "===<br>";
                            if ($reportdata[$id][0]['lastts'] == $nts) {

                                $reportdata[$id][$k]["End Time"] = date("Y-m-d H:i:s", round($reportdata[$id][0]['startts'] / 1000));
                                if (!empty($reportdata[$id][$k]["Start Time"])) {
                                    $event_duration                      = date("H:i:s", (strtotime($reportdata[$id][$k]["End Time"]) - strtotime($reportdata[$id][$k]["Start Time"])));
                                    $reportdata[$id][$k]["Event Length"] = $event_duration;
                                }
                                if (in_array($reportdata[$id][$last]["Mode"], $callMode)) {
                                    $reportdata = calls($reportdata, $id, $last);
                                    end($reportdata[$id]);
                                    $n = key($reportdata[$id]) + 1;
                                } else {
                                    $n = $k + 1;
                                }
                                $reportdata[$id][$n]["Status"]     = "Idle";
                                $reportdata[$id][$n]["Mode"]       = "Not Ready";
                                $reportdata[$id][$n]["Start Time"] = date("Y-m-d H:i:s", round($reportdata[$id][0]['startts'] / 1000));
                                $reportdata[$id][$n]["sip"]        = $sip;
                                $reportdata[$id][$n]["Client Id"]  = "";
                                $reportdata[$id][$n]["Number"]     = "";
                                $reportdata[$id][$n]["User Name"]  = $reportdata[$id][0]["User Name"];
                                $reportdata[$id][$n]["User Id"]    = $reportdata[$id][0]["User Id"];
                            }
                        }
                        $reportdata[$id][$k]["Number"] = "";
                        if (empty($nstate[1])) {
                            $reportdata[$id][$k]["Mode"]   = $nstate[0];
                            $reportdata[$id][$k]["Status"] = "Wait";
                        } else {
                            if ($nstate[0] == "Paused") {
                                $reportdata[$id][$k]["Mode"]   = $nstate[1];
                                $reportdata[$id][$k]["Status"] = "Break";
                            } else {
                                $reportdata[$id][$k]["Mode"]   = "Inbound";
                                $reportdata[$id][$k]["Status"] = "Wait";
                            }
                        }
                        $reportdata[$id][$k]["User Name"] = $reportdata[$id][0]["User Name"];
                        $reportdata[$id][$k]["User Id"]   = $reportdata[$id][0]["User Id"];
                        // echo $nts."--2==<br>";
                        $reportdata[$id][$k]["Start Time"] = date("Y-m-d H:i:s", round($nts / 1000));
                        $reportdata[$id][$k]['sip']        = $sip;
                        $reportdata[$id][$k]["Client Id"]  = "";
                        $reportdata[$id][$k]["Number"]     = "";
                        if (!empty($reportdata[$id][$k]["End Time"])) {
                            $event_duration                      = date("H:i:s", (strtotime($reportdata[$id][$k]["End Time"]) - strtotime($reportdata[$id][$k]["Start Time"])));
                            $reportdata[$id][$k]["Event Length"] = $event_duration;
                             if (in_array($reportdata[$id][$k]["Mode"], $callMode)) {
                                // echo "here";
                                $reportdata = calls($reportdata, $id, $k);
                                end($reportdata[$id]);
                                $k = key($reportdata[$id]) + 1;
                            }

                        }
                        $j++;
                    }
                     if (!isset($reportdata[$id][$k]["End Time"]) && isset($reportdata[$id][$k]["Start Time"])) {
                       
                    $reportdata[$id][$k]["End Time"]     = date("Y-m-d H:i:s", round($pts / 1000));
                    $event_duration                         = date("H:i:s", (strtotime($reportdata[$id][$k]["End Time"]) - strtotime($reportdata[$id][$k]["Start Time"])));
                    $reportdata[$id][$k]["Event Length"] = $event_duration;
                    if (in_array($reportdata[$id][$last]["Mode"], $callMode)) {
                                $reportdata = calls($reportdata, $id, $last);
                                end($reportdata[$id]);
                                $k = key($reportdata[$id]) + 1;
                    }else{
                        $k=$k+1;
                    }
                    $reportdata[$id][$k]["Start Time"]   = date("Y-m-d H:i:s", round($pts / 1000));
                    $reportdata[$id][$k]['sip']        = $sip;
                    $reportdata[$id][$k]["Client Id"]  = "";
                    $reportdata[$id][$k]["Number"]     = "";
                    $reportdata[$id][$k]["User Name"] = $reportdata[$id][0]["User Name"];
                    $reportdata[$id][$k]["User Id"]   = $reportdata[$id][0]["User Id"];
                    $reportdata[$id][$k]["Status"]     = "Idle";
                    $reportdata[$id][$k]["Mode"]       = "Not Ready";
                  }
                } //$newarr
                 
                $reportdata[$id][0]['startts'] = '';
                $reportdata[$id][0]['lastts']  = '';
                $newarr                        = array();
            } else {

                if (empty($sip)) {
                    continue;
                }
                if (empty($reportdata[$id][0]["Status"])) {
                    $k = 0;
                    if ($j == 0) {
                        $reportdata[$id][$k]["User Name"]    = $reportdata[$id][0]["User Name"];
                        $reportdata[$id][$k]["User Id"]      = $reportdata[$id][0]["User Id"];
                        $reportdata[$id][$k]["Status"]       = "Login";
                        $reportdata[$id][$k]["Mode"]         = "Not Ready";
                        $reportdata[$id][$k]["Start Time"]   = $starttime;
                        $reportdata[$id][$k]["Client Id"]    = "";
                        $reportdata[$id][$k]["Number"]       = "";
                        $reportdata[$id][$k]["End Time"]     = date("Y-m-d H:i:s", round($pts / 1000));
                        $event_duration                      = date("H:i:s", (strtotime($reportdata[$id][$k]["End Time"]) - strtotime($reportdata[$id][$k]["Start Time"])));
                        $reportdata[$id][$k]['sip']          = $sip;
                        $reportdata[$id][$k]["Event Length"] = $event_duration;
                        $k                                   = $k + 1;
                    }
                    $j++;
                } else {
                    end($reportdata[$id]);
                    $k    = key($reportdata[$id]) + 1;
                    $last = $k - 1;
                    if (!isset($reportdata[$id][$last]["End Time"])) {
                        $reportdata[$id][$last]["End Time"]     = date("Y-m-d H:i:s", round($pts / 1000));
                        $event_duration                         = date("H:i:s", (strtotime($reportdata[$id][$last]["End Time"]) - strtotime($reportdata[$id][$last]["Start Time"])));
                        $reportdata[$id][$last]["Event Length"] = $event_duration;
                        $reportdata                             = calls($reportdata, $id, $last);

                        end($reportdata[$id]);
                        $k = key($reportdata[$id]) + 1;
                    }
                    if ($j == 0) {
                        $reportdata[$id][$k]["User Name"]    = $reportdata[$id][0]["User Name"];
                        $reportdata[$id][$k]["User Id"]      = $reportdata[$id][0]["User Id"];
                        $reportdata[$id][$k]["Status"]       = "Login";
                        $reportdata[$id][$k]["Mode"]         = "Not Ready";
                        $reportdata[$id][$k]["Start Time"]   = $starttime;
                        $reportdata[$id][$k]["sip"]          = $sip;
                        $reportdata[$id][$k]["Client Id"]    = "";
                        $reportdata[$id][$k]["Number"]       = "";
                        $reportdata[$id][$k]["End Time"]     = date("Y-m-d H:i:s", round($pts / 1000));
                        $event_duration                      = date("H:i:s", (strtotime($reportdata[$id][$k]["End Time"]) - strtotime($reportdata[$id][$k]["Start Time"])));
                        $reportdata[$id][$k]["Event Length"] = $event_duration;
                        $k                                   = $k + 1;
                    }
                    $reportdata[$id][$k]["User Name"]  = $reportdata[$id][0]["User Name"];
                    $reportdata[$id][$k]["User Id"]    = $reportdata[$id][0]["User Id"];
                    $reportdata[$id][$k]["Number"]     = "";
                    $reportdata[$id][$k]["Status"]     = "Idle";
                    $reportdata[$id][$k]["Mode"]       = "Not Ready";
                    $reportdata[$id][$k]["Start Time"] = date("Y-m-d H:i:s", round($pts / 1000));
                    $reportdata[$id][$k]['sip']        = $sip;
                    $reportdata[$id][$k]["Client Id"]  = "";
                    $j++;
                }
            }

            $reportdata[$id][0]['startts'] = '';
            $reportdata[$id][0]['lastts']  = '';
        } //foreach data
        end($reportdata[$id]);
        $k = key($reportdata[$id]);
        if (!isset($reportdata[$id][$k]["Status"])) {
            continue;
        }
        if ($reportdata[$id][$k]["Status"] == "Login") {
            $last                              = $k;
            $k                                 = $last + 1;
            $reportdata[$id][$k]["Status"]     = "Logout";
            $reportdata[$id][$k]["Mode"]       = "-";
            $reportdata[$id][$k]["Client Id"]  = "";
            $reportdata[$id][$k]["Number"]     = "";
            $reportdata[$id][$k]["End Time"]   = $reportdata[$id][$last]["End Time"]   = (empty($duration) ? $reportdata[$id][$last]["End Time"] : $endtime);
            $reportdata[$id][$k]["Start Time"] = $reportdata[$id][$last]["End Time"];

            if (strtotime($reportdata[$id][$k]["Start Time"]) > strtotime($reportdata[$id][$k]["End Time"])) {
                $reportdata[$id][$last]["Start Time"] = $reportdata[$id][$k]["End Time"];
                $reportdata[$id][$k]["Start Time"]    = $reportdata[$id][$k]["End Time"];
            }
            $event_duration                         = date("H:i:s", (strtotime($reportdata[$id][$k]["End Time"]) - strtotime($reportdata[$id][$k]["Start Time"])));
            $reportdata[$id][$k]["Event Length"]    = $event_duration;
            $event_duration                         = date("H:i:s", (strtotime($reportdata[$id][$last]["End Time"]) - strtotime($reportdata[$id][$last]["Start Time"])));
            $reportdata[$id][$last]["Event Length"] = $event_duration;
            $reportdata[$id][$k]["User Name"]       = $reportdata[$id][0]["User Name"];
            $reportdata[$id][$k]["User Id"]         = $reportdata[$id][0]["User Id"];
        }
        if ($reportdata[$id][0]['presence'] == 1 && (strtotime($logdate) == strtotime(date("Y-m-d"))) && $userlogs[$lastArr[$id]]['id'] == $ulogid) {
            $reportdata[$id][$k]["End Time"]     = date("Y-m-d H:i:s");
            $event_duration                      = date("H:i:s", (strtotime($reportdata[$id][$k]["End Time"]) - strtotime($reportdata[$id][$k]["Start Time"])));
            $reportdata[$id][$k]["Event Length"] = $event_duration;
        } else {
            if (!isset($reportdata[$id][$k]["Start Time"])) {
                $last                              = $k - 1;
                $reportdata[$id][$k]["Status"]     = "Logout";
                $reportdata[$id][$k]["Mode"]       = "-";
                $reportdata[$id][$k]["Client Id"]  = "";
                $reportdata[$id][$k]["Number"]     = "";
                $reportdata[$id][$k]["Start Time"] = $reportdata[$id][$last]["Start Time"];
                $reportdata[$id][$k]["End Time"]   = (empty($duration) ? $reportdata[$id][$k]["Start Time"] : $endtime);
                if (strtotime($reportdata[$id][$k]["Start Time"]) > strtotime($reportdata[$id][$k]["End Time"])) {
                    $reportdata[$id][$k]["Start Time"] = $reportdata[$id][$k]["End Time"];
                }
                $event_duration                      = date("H:i:s", (strtotime($reportdata[$id][$k]["End Time"]) - strtotime($reportdata[$id][$k]["Start Time"])));
                $reportdata[$id][$k]["Event Length"] = $event_duration;
                $reportdata[$id][$k]["User Name"]    = $reportdata[$id][0]["User Name"];
                $reportdata[$id][$k]["User Id"]      = $reportdata[$id][0]["User Id"];

            } else if (!isset($reportdata[$id][$k]["End Time"])) {

                $reportdata[$id][$k]["Client Id"] = "";
                $reportdata[$id][$k]["Number"]    = "";
                $reportdata[$id][$k]["Status"]    = "Logout";
                $reportdata[$id][$k]["Mode"]      = "-";
                $reportdata[$id][$k]["End Time"]  = (empty($duration) ? $reportdata[$id][$k]["Start Time"] : $endtime);
                if (strtotime($reportdata[$id][$k]["Start Time"]) > strtotime($reportdata[$id][$k]["End Time"])) {
                    $reportdata[$id][$k]["Start Time"] = $reportdata[$id][$k]["End Time"];
                }
                $last=$k-1;
                $reportdata[$id][$last]["End Time"] = $reportdata[$id][$k]["Start Time"];
                $reportdata[$id][$last]["Event Length"] =date("H:i:s", (strtotime($reportdata[$id][$last]["End Time"]) - strtotime($reportdata[$id][$last]["Start Time"])));
                $event_duration                      = date("H:i:s", (strtotime($reportdata[$id][$k]["End Time"]) - strtotime($reportdata[$id][$k]["Start Time"])));
                $reportdata[$id][$k]["Event Length"] = $event_duration;
                $reportdata[$id][$k]["User Name"]    = $reportdata[$id][0]["User Name"];
                $reportdata[$id][$k]["User Id"]      = $reportdata[$id][0]["User Id"];
            }

        }
    } // foreach userlogs

} // if
$i             = 0;
$reportName    = 'Agent Activity Report';
$ajaxPath      = 'dialer/agentactivity';
$reporthead    = array("Server IP", "User Name", "User Id", "Client Id", "Number", "Status", "Mode", "Event Start Time", "Event End Time", "Event Length");
$highestColumn = sizeof($reporthead);
for ($i = 0; $i < count($reporthead); $i++) {$headerwidth[$i] = '150px';}
$outhead = "<tr>";
$outstr  = "";
for ($head = 0; $head < $highestColumn; $head++) {
    $outhead .= "<td style='width:" . $headerwidth[$head] . ";'>" . $reporthead[$head] . "</td>";
}
$outhead .= "</tr>";
$exceldata = array();
if (!empty($reportdata)) {
    $p = 0;
    foreach ($reportdata as $user) {
        $starttimeArr = array_reduce($user, function ($result, $item) {
            $result[] = strtotime($item['Start Time']) . $item['Number'] . $item["Status"];
            return $result;
        });
        $starttimeArr = array_unique($starttimeArr);
        $i            = 0;
        foreach ($user as $row) {
            if (array_key_exists('unset', $row)) {
                continue;
            }
            if (!array_key_exists($i, $starttimeArr)) {
                $i++;
                continue;
            }
            $p++;
            $outstr .= "<tr>";
            for ($head = 0; $head < $highestColumn; $head++) {
                if ($reporthead[$head] == "Server IP") {
                    $row["Server IP"] = $serverip;
                }
                if ($reporthead[$head] == "Event Start Time") {
                    $row["Event Start Time"] = date("Y-m-d H:i:s", strtotime($row["Start Time"]) - ($timeoffset));

                    $row["Event End Time"] = date("Y-m-d H:i:s", strtotime($row["End Time"]) - ($timeoffset));

                }
                $exceldata[$p][$reporthead[$head]] = $row[$reporthead[$head]];
                $outstr .= "<td style='width:" . $headerwidth[$head] . ";'>" . $row[$reporthead[$head]] . "</td>";
            }
            $outstr .= "</tr>";
            $i++;
        }
    }
}

$reportLib = new ReportLib();
if (Input::has("dllogxls")) {
    $reportLib->downloadExcelFromArray($reporthead, $exceldata, 'AgentActivityReport.csv');
}
?>
@include('layout.module.dialer.reportLayout')
