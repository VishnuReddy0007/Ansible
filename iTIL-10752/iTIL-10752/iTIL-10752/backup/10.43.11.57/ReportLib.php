<?php

namespace App\Jobs;

use App\Jobs\KHRMSLib;
use App\Models\User;
use Auth;
use Config;
use DB;
use App\Models\CRMCall;
use App\Models\UserLog;
use \PDO;
use \PHPExcel_Cell;
use \PHPExcel_IOFactory;

class ReportLib
{

    public function pripareReportData()
    {
        $wakka          = new KHRMSLib();
        $dashboarduser  = Auth::user();
        $conn_exception = '';

        $i = 1;

        $loggedInUser     = DB::table('users')->where('username', '=', $dashboarduser->username)->get();
        $loggedInUserData = json_decode($loggedInUser[0]->data, true);
        $ipids            = ($loggedInUserData['childserverusers'] != '') ? explode(',', $loggedInUserData['childserverusers']) : array();
        $ipArray          = array();
        if (count($ipids) > 0) {
            $iparray = DB::table('location_servers')->select('server_ip')->whereIn('id', $ipids)->get();
            foreach ($iparray as $ip) {
                $ipArray[] = $ip->server_ip;
            }

            if (($akey = array_search(env('DB_HOST'), $ipArray)) !== false) {
                unset($ipArray[$akey]);
            }
        }
        array_push($ipArray, env('DB_HOST'));

        $budgetedUser = array();
        $reportarray  = array();
        //    $accessData = array();
        //    $allusers = array();
        //    echo "<pre>";
        //    print_r($ipArray);
        foreach ($ipArray as $ip) {
            try {
                //echo $ip."<br />";
                Config::set("database.connections.mysql.read.host", $ip);
                //DB::purge('mysql');
                DB::reconnect('mysql');
                $accessData = array();
                $allusers   = array();

                if ($dashboarduser->usertype != 'Admin') {
                    $accessData['is_admin'] = false;
                    $allusers               = DB::table('users')->where(function ($query) use ($dashboarduser) {
                        $query->where('username', '=', $dashboarduser->username)
                            ->orwhere('supervisor', '=', $dashboarduser->username)
                            ->orWhere('lteam2', '=', $dashboarduser->username)
                            ->orWhere('lteam', '=', $dashboarduser->username);
                    })->where('status', '=', 'Active')->where('usertype', '=', 'User')->get();

                    $clients  = array();
                    $didlines = array();
                    if ($dashboarduser->exten != "") {
                        $didlines[] = $dashboarduser->exten;
                    }

                    $oclientlst = $wakka->clientsReadAccess();
                    if (!empty($oclientlst)) {
                        foreach ($oclientlst as $tclnt) {
                            if ($tclnt != "") {
                                $roclientstr[] = "$tclnt";
                            }
                        }
                    }

                    $accessData['clients']  = $clients;
                    $accessData['didlines'] = $didlines;
                } else {
                    $accessData['is_admin'] = true;
                    $allusers               = DB::table('users')->where('status', '=', 'Active')->where('usertype', '=', 'User')->get();
                }

                foreach ($allusers as $tuser) {

                    $campaigns = json_decode($tuser->data, true);
                    $campdata  = unserialize($campaigns['hrmsdata']);
                    $clientlst = explode(",", $campdata['clientsownerlist']);

                    $accessData['users'][]               = $tuser->id;
                    $accessData['usersData'][$tuser->id] = array(
                        'username' => $tuser->username,
                        'fullname'                                              => $tuser->fullname, 'client' => $clientlst[0], 'supervisor' => $tuser->supervisor, 'ip' => $ip
                    );
                    $accessData['usersData']['userip'] = $ip;
                    $budgetedUser[]                    = $tuser->username;
                }
                if ($ip == 'localhost' || $ip == '127.0.0.1') {
                    $ip = env('app_ip');
                }
                $reportarray = generateReportData($ip, $accessData, $reportarray, $i);

                DB::disconnect('mysql');
            } catch (\Exception $e) {
                echo $e;
                //$conn_exception .= "Could not connect to the database on $ip.<br />";
            }
        }
        $return_array['conn_exception'] = $conn_exception;
        $return_array['reportarray']    = $reportarray;
        $return_array['budgetedUser']   = array_unique($budgetedUser);
        $return_array['usersData']      = $accessData['usersData'];
        $return_array['IP']             = $ip;
        //print_r($return_array['users']);
        return $return_array;
    }

    public function downloadExcelFromArray($reporthead, $reportdata, $filename = 'logexcel.csv')
    {
        include_once app_path() . '/lib/phpexcel/PHPExcel.php';
        $inputFileType = "CSV";
        $objReader     = PHPExcel_IOFactory::createReader($inputFileType);
        $objPHPExcel   = $objReader->load("assets/extras/blank.csv");
        $baseRow       = 2;
        $highestColumn = sizeof($reporthead);
        for ($head = 0; $head < $highestColumn; $head++) {
            $colstr = PHPExcel_Cell::stringFromColumnIndex($head);
            $objPHPExcel->getActiveSheet()->setCellValue($colstr . "1", $reporthead[$head]);
        }
        if (is_array($reportdata)) {
            foreach ($reportdata as $row) {
                $baseRowValue = $baseRow++;
                $col          = 0;

                for ($head = 0; $head < $highestColumn; $head++) {
                    $colstr = PHPExcel_Cell::stringFromColumnIndex($head);
					$objPHPExcel->getActiveSheet()->setCellValue($colstr . $baseRowValue, strip_tags($row[$reporthead[$head]]));
                }
                if (ob_get_contents()) ob_end_clean();
            }
        }

        header('Content-Type: text/csv');
        header('Content-Disposition: attachment;filename="' . $filename . '"');
        header('Cache-Control: max-age=0');
        $objWriter = PHPExcel_IOFactory::createWriter($objPHPExcel, $inputFileType);

        $objWriter->save('php://output');
        exit();
        return;
    }

    public function prepareUserlogData($logdate, $users, $usersData, $fetchFresh = false)
    {
        if (!$fetchFresh) {
            $ulogs = DB::table('userlogs_central')
                ->where('created_at', '>=', date("Y-m-d H:i:s", $logdate))
                ->where('created_at', '<=', date("Y-m-d H:i:s", $logdate + 24 * 60 * 60)) //->where('id','=',45510) // Debug code
                ->whereIn('user_id', $users)->get();

            if (is_array($ulogs) && count($ulogs)) {
                return json_decode(json_encode($ulogs), true);
            }
        }


        $skipState = array('Progressive-', 'Manual-', 'Ready-Incoming', 'Ready-Predictive', 'DialNext-', 'WRAPUP', 'AUTOWRAPUP');

        $ulogs = DB::table('userlogs')
            ->where('created_at', '>=', date("Y-m-d H:i:s", $logdate))
            ->where('created_at', '<=', date("Y-m-d H:i:s", $logdate + 24 * 60 * 60)) //->where('id','=',45510) // Debug code
            ->whereIn('user_id', $users)->get();

        $ulogs = json_decode(json_encode($ulogs), true);

        $userlogs = array();
        foreach ($ulogs as $ulog) {
            $tuser = $usersData[$ulog['user_id']];

            $ulog['usercode']   = $tuser['username'];
            $ulog['user']       = $tuser['fullname'];
            $ulog['supervisor'] = $tuser['supervisor'];

            $data = json_decode($ulog['data'], true);
            //uasort($data, "self::cmp");

            $lastSip   = end($data);
            $lastSipId = key($data);
            $startts   = strtotime($ulog['startdate'] . ' ' . $ulog['starttime']);
            if($ulog['endtime'] != '00:00:00'){
                $endts_n=strtotime($ulog['enddate'] . ' ' . $ulog['endtime']);
                $endts=$endts_n;
            }else{
                $next_udata=DB::table("userlogs")->where('user_id',$ulog['user_id'])->where("startdate",$ulog['startdate'])->where("id",">",$ulog['id'])->orderBy("created_at","asc")->select("starttime")->first();
                if(!empty($next_udata)){
                    $endts_n=strtotime($ulog['startdate'].' '.$next_udata->starttime);
                    // $endts_n=strtotime(date("Y-m-d H:i:s",strtotime("-1 minutes",$endts_n)));
                }else{
                    $endts_n=strtotime($ulog['startdate'].' 18:29:29');
                }
                $endts=round($lastSip['ts'] / 1000);
            }
            

            $ulog += $this->prepareUserlogStates($data, $startts, $skipState);

            $calllog = $this->prepareCalllogData($logdate, $startts, $endts_n, $ulog['user_id']);
            $endts   = ($endts > $calllog['last_update']) ? $endts : $calllog['last_update'];
            $oncall  = $calllog['TS_OnCall'];

            $ulog['enddate']  = date('Y-m-d', $endts);
            $ulog['endtime']  = date('H:i:s', $endts);
            $ulog['Duration'] = ($endts - $startts);

            $ulog['Productive'] = $oncall + $ulog['BusinessTime'];
            $ulog['Idle']       = $ulog['Duration'] - ($ulog['AllBreaks'] + $ulog['Productive'] + $ulog['NotReady']);
            $ulog += $calllog;

            $ignoreState = array('durationsec', 'group', 'data', 'last_update');
            $skipState   = array_merge($skipState, $ignoreState);
            foreach ($skipState as $field) {
                unset($ulog[$field]);
            }
            $userlogs[$ulog['id']] = $ulog;
        }

        return $userlogs;
    }

function prepareUserlogStates($data, $startts, $skipState)
    {
        $breaks     = array("Paused", "BusinessTime", "TeamMeeting", "Lunch", "TeaBreak", "DownTime", "Utility");
        // $timehead    = array_merge($skipState, array('NotReady'), $breaks);

        foreach ($breaks as $break) {
            if ($break == 'Paused') continue;
            $ulog[$break] = 0;
        }
        $ulog['AllBreaks'] = $ulog['NotReady'] = $ulog['Refresh']= 0;

        $prets      = $startts * 1000;
        $i=0;
    
        foreach ($data as $sip => $sdata) {
            ;
            if($i==0){
                $previous   = "NotReady";
            }
            $pts = $sdata['ts'];
            if (isset($sdata['states'])) {
                if(!empty($sdata['states'])){
                        foreach ($sdata['states'] as $ts => $states) {
                        if(!isset($ulog[$previous])){
                             $ulog[$previous]=0;
                         }
                        $ulog[$previous] += round($ts - $prets, 2) / 1000;
                        $previous   = ($states[0] == 'Paused') ? $states[1] : $states[0] . '-' . $states[1];
                        $prets      = $ts;
                    }
                }else{
                    $ulog[$previous] += round($pts - $prets, 2) / 1000;
                }
                $prets = $pts;
                // echo $previous."-- ts ".$ts."-  ++ prets".$prets."<br>";
                // $ulog[$previous] += round($pts - $prets, 2) / 1000;
                
            }
            $i++;
        }

        foreach ($breaks as $break) {
            if ($break == 'Paused' || $break == 'BusinessTime') continue;
            $ulog['AllBreaks'] += $ulog[$break];
        }
        return $ulog;
    }


    public function prepareCalllogData($logdate, $starttime, $endtime, $user_id)
    {
        $prevlogdate = strtotime(date('Y-m-d', strtotime('-7 days')));

        if ($logdate > $prevlogdate) {
            $alist = DB::table('crmcalls');
        } else {
            $alist = DB::table('crmcalls_archive');
        }

        $alist = $alist->whereNotIn('type', ['Conference', 'MAgent'])->where('userstatus', '!=', 'InboundDROP')
            ->where('created_at', '>=', date("Y-m-d H:i:s", $starttime))
            ->where('created_at', '<=', date("Y-m-d H:i:s", $endtime))
            ->where('user_id', '=', $user_id)->get();

        $calllog["last_update"] = 0;
        foreach (array('', 'Man', 'Pro', 'Inb') as $prefix) {
            $calllog[$prefix . "TS_PreCall"]  = 0;
            $calllog[$prefix . "TS_Ring"]     = 0;
            $calllog[$prefix . "TS_Talk"]     = 0;
            $calllog[$prefix . "TS_PostCall"] = 0;
            $calllog[$prefix . "TS_OnCall"]   = 0;
	        $calllog[$prefix . "TS_Hold"]     = 0;
        }
        $calllog["ProductiveConnects"] = $calllog["NonRegiConnects"] = $calllog["RegiConnects"] = $calllog["TotalConnects"] = $calllog["Attempt"] = $calllog["TotalNoOfInbCalls"] = 0;

        foreach ($alist as $aline) {
            $aline->callSec = ($aline->type != 'Inbound') ? $aline->callSec : 0;

            $talktime  = $aline->talkSec + $aline->recstartSec + $aline->recendSec;
            $totaltime = $aline->waitSec + $aline->callSec + $talktime + $aline->dispoSec;

            $prefix = substr($aline->type, 0, 3);

            $calllog[$prefix . "TS_PreCall"] += $aline->waitSec / 1000;
            $calllog[$prefix . "TS_Ring"] += $aline->callSec / 1000;
            $calllog[$prefix . "TS_Talk"] += $talktime / 1000;
            $calllog[$prefix . "TS_PostCall"] += $aline->dispoSec / 1000;
            $calllog[$prefix . "TS_OnCall"] += $totaltime / 1000;
	        $calllog[$prefix . "TS_Hold"] += $aline->callhold / 1000;

            $calllog["TS_PreCall"] += $aline->waitSec / 1000;
            $calllog["TS_Ring"] += $aline->callSec / 1000;
            $calllog["TS_Talk"] += $talktime / 1000;
            $calllog["TS_PostCall"] += $aline->dispoSec / 1000;
            $calllog["TS_OnCall"] += $totaltime / 1000;
	        $calllog["TS_Hold"] += $aline->callhold / 1000;

            $calllog["Attempt"]++;

    	    if($aline->type == 'Inbound'){
    		  $calllog["TotalNoOfInbCalls"]++;
    	    }

            if (($aline->status == 'ANSWER' || $aline->recstartSec > 0)) {
                $calllog["TotalConnects"]++;
                if ($aline->crm_id == 0) {
                    $calllog["RegiConnects"]++;
                } else {
                    $calllog["NonRegiConnects"]++;
                }
            }

            if (($aline->userstatus == 'Contact Productive' || $aline->usersubstatus == 'Contacted - Productive') && $aline->crm_id != 0) {
                $calllog["ProductiveConnects"]++;
            }

            if ($calllog["last_update"] < strtotime($aline->updated_at) && $aline->userstatus != 'FORCEDCLOSE') {
                $calllog["last_update"] = strtotime($aline->updated_at);
            }
        }
        return $calllog;
    }

    public function getUserData()
    {
        $allusers = DB::table('users')->where('status', '=', 'Active')
            //->where('usertype','=','User')
            ->get();
        foreach ($allusers as $tuser) {
            $userData['users'][]               = $tuser->id;
            $userData['usersData'][$tuser->id] = array(
                'username' => $tuser->username,
                'fullname'                                            => $tuser->fullname, 'supervisor' => $tuser->supervisor
            );
        }
        return $userData;
    }

    public function getCentralDetails()
    {
        //return array('server_id'=>'08',    'server_ip'=>'192.168.200.200',    'location'=>'Mumbai');

        $central_ip = env('central_ip');
        $server_ip  = env('app_ip');

        $conn = array(
            'driver'    => 'mysql',
            'host'      => $central_ip,
            'database'  => env('CENTRAL_DB'),
            'username'  => env('CENTRAL_USERNAME'),
            'password'  => env('CENTRAL_PASSWORD'),
            'charset'   => 'utf8',
            'collation' => 'utf8_unicode_ci',
            'prefix'    => '',
            'options'   => array(PDO::ATTR_TIMEOUT => 5),
        );
        Config::set("database.connections.conn", $conn);
        DB::connection("conn")->getDatabaseName();

        $serverclist = DB::connection("conn")->select(DB::raw("SELECT id, location FROM server_details WHERE server_ip='$server_ip'"));
        $server_id   = str_pad($serverclist[0]->id, 2, '0', STR_PAD_LEFT);

        return array('server_id' => $server_id, 'server_ip' => $server_ip, 'location' => $serverclist[0]->location);
    }

    function userSpyStr($sipid)
    {
        if (!empty($sipid)) return "&nbsp;&nbsp;&nbsp;<a href=# onclick='kDialerSpy(\"$sipid\",\"L\");return false;'>L</a> <a href=# onclick='kDialerSpy(\"$sipid\",\"B\");return false;'>B</a> <a href=# onclick='kDialerSpy(\"$sipid\",\"W\");return false;'>W</a>";
    }

    public function secToDuration($sec)
    {
        //return $sec;
        $sec  = round($sec);
        $sign = (!is_null($sec) && $sec <= -1) ? '-' : '';
        $sec  = ($sec < 0) ? abs($sec) : $sec;
        return $sign . sprintf("%02d%s%02d%s%02d", floor($sec / 3600), ':', ($sec / 60) % 60, ':', $sec % 60);
    }

    public function toPercent($fraction)
    {
        return number_format($fraction * 100, 2) . '%';
    }

    public function cmp($a, $b)
    {
        return $a["ts"] - $b["ts"];
    }
    function getCustData($call_id)
    {
        $campaign = $cust_id = $dialmode  = $sipid = '';
        $crmcall = CRMCall::find($call_id);
        if ($crmcall) {
            $campaign = $crmcall->client;
            if ($crmcall->crm_id > 0) {
                $record = DB::table('records')->select('clientcode')->where('id', $crmcall->crm_id)->first();
                if ($record) {
                    $cust_id = $record->clientcode;
                }
            }
            $dialmode = $crmcall->type;
            $sipid = $crmcall->sipid_id;
        } else {
            $campaign = "Call_id " . $call_id;
        }
        return array('campaign' => $campaign, 'cust_id' => $cust_id, 'dialmode' => $dialmode, 'sipid' => $sipid);
    }

    function getCustCallData($call_id)
    {
        $campaign = $cust_id = $dialmode  = $sipid = '';
        $crmcall = CRMCall::find($call_id);
        if ($crmcall) {
            $calldetails=array("ts_wait"=>$crmcall->ts_Wait,"ts_call"=>$crmcall->ts_Call,"ts_talk"=>$crmcall->ts_Talk);
            $campaign = $crmcall->client;
            if ($crmcall->crm_id > 0) {
                $record = DB::table('records')->select('clientcode')->where('id', $crmcall->crm_id)->first();
                if ($record) {
                    $cust_id = $record->clientcode;
                }
            }
            $dialmode = $crmcall->type;
            $sipid = $crmcall->sipid_id;
        } else {
            $campaign = "Call_id " . $call_id;
            $calldetails=array();   
        }
        return array('campaign' => $campaign, 'cust_id' => $cust_id, 'dialmode' => $dialmode, 'sipid' => $sipid,"calldetails"=>$calldetails);
    }

    function getUserLastStatus($user_id)
    {
        $stend = [];
        $userlog = UserLog::where('user_id', '=', $user_id)->where('updated_at', '>', date("Y-m-d"))->orderBy("id", "DESC")->first();
        if ($userlog) {
            $stend = $userlog->getLastStatus();
            if ($stend) {
                if ($stend[0] == "Progressive") $stend[0] = 'Preview';
                if ($stend[0] == '') {
                    $stend[0] = "NotReady";
                    $stend[1] = "NotReady";
                }
            } else {
                $stend[0] = "NotReady";
                $stend[1] = "NotReady";
               
            }
        } else {
            $stend[0] = "ERROR";
            $stend[1] = "NotReady";
        }
        return $stend;
    }

    function getUserLastTimeStatus($user_id)
    {
        $stend = [];
        $userlog = UserLog::where('user_id', '=', $user_id)->where('updated_at', '>', date("Y-m-d"))->orderBy("id", "DESC")->first();
        
        if (!empty($userlog)) {
            $stend = $userlog->getLasttimeStatus();
            if ($stend) {
                if ($stend['status'][0] == "Progressive") $stend['status'][0] = 'Preview';

                if(!empty($stend['ts'])){
                    $ts=round($stend['ts']/ 1000);
                    $stend['ts']=date("H:i:s",$ts);
                }else{
                    $stend["ts"] = date("H:i:s",strtotime($userlog->updated_at));
                }
                
                if ($stend['status'][0] == '') {
                    $stend['status'][0] = "NotReady";
                    $stend['status'][1] = "NotReady";
                }

            } else {
                $stend['status'][0] = "NotReady";
                $stend['status'][1] = "NotReady";
                $stend["ts"] = date("H:i:s",strtotime($userlog->updated_at));
               
            }
           
        } else {
            $stend['status'][0] = "ERROR";
            $stend['status'][1] = "NotReady";
            $stend["ts"] =date("H:i:s",strtotime($userlog->updated_at));
        }
        return $stend;
    }
}
