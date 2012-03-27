<?php
	//
	/*
	 Reads /scripts/replication.cfg
	 Note: replication.cfg will define each entry in this file.  ONLY include these entries (tab between name and value, no empty lines)
	 
	  HOST_IP  ip_of_replication_host_here
	  HOST_USER   replication_host_root_user_here
	  HOST_PWD    replication_host_root_pwd_here
	  REPLICATION_USER  replication_client_user_here
	  REPLICATION_PWD	replication_client_pwd_here
	  CLIENT_IP   this_machine(localhost)
	  CLIENT_USER this_sql_user(root)
	  CLIENT_PWD  this_sql_pwd(root password)
	  
	*/
	
	define('REPL_CFG','/scripts/replication.cfg');
	define('LF',"\n");
	define('HT',"\t");
	define('BR','<br>');
	
	
	// stop our slave
	// connect to the master
	// flush tables with read lock
	// get the master position and file
	// reset our slave 
	// start our slave
	// unlock master tables
	// check to see if our slave is replicating OK
	
	if (!file_exists(REPL_CFG))
	{
		echo 'No file:'.REPL_CFG.LF;
		exit(1);
	}
	$aLines=file(REPL_CFG);
	foreach($aLines as $cLine)
	{
		$aLine=explode("\t", $cLine);
		$aLine[1]=str_replace(LF,'',$aLine[1]);
		define($aLine[0],$aLine[1]);
	}
	echo 'Read configuration file '.REPL_CFG.LF;
	
	$oMasterHost=mysql_connect(HOST_IP,HOST_USER,HOST_PWD,true);
	if (!$oMasterHost)
	{
		echo 'Cannot connect to '.HOST_IP.' as '.HOST_USER.' with password '.HOST_PWD.': '.mysql_error().LF;
		exit(1);
	}
	$oMaster=new oMaster($oMasterHost,HOST_IP);
	if (!$oMaster->masterstatus() || !$oMaster->status)
	{
		if (!$oMaster->status)
			echo 'Master error bit set'.LF;
		echo 'Error initializing '.HOST_IP.LF;
		echo $oMaster->cStatusMsg.LF;
		exit(1);
	}
	
	echo 'Master status on:'.HOST_IP.LF;
	echo '            File:'.$oMaster->cFile.LF;
	echo '        Position:'.$oMaster->nPosition.LF;
	echo '     	      DoDB:'.$oMaster->cDoDB.LF;
	echo '          DontDB:'.$oMaster->cDontDB.LF;
	echo '          '.LF;


	$oSlaveHost=mysql_connect(CLIENT_IP,CLIENT_USER,CLIENT_PWD,true);
	if (!$oSlaveHost)
	{
		echo 'Cannot connect to '.CLIENT_IP.' as '.CLIENT_USER.' with password '.CLIENT_PWD.': '.mysql_error().LF;
		exit(1);
	}
	$oSlave=new oSlave($oSlaveHost,CLIENT_IP);
	if (!$oSlave->slavestatus() || !$oSlave->status)
	{
		if (!$oSlave->status)
			echo 'Slave error bit set'.LF;
		echo 'Error initializing '.CLIENT_IP.LF;
		echo $oSlave->cStatusMsg.LF;
		exit(1);
	}
	echo 'Client status on:'.CLIENT_IP.LF;
	echo '            Time:'.gmdate("H:i:s",time()).LF;
	echo '            Host:'.CLIENT_IP.LF;
	echo '            File:'.$oSlave->file.LF;
	echo '       Position :'.$oSlave->position.LF;
	echo '          io_run:'.$oSlave->io_run.LF;
	echo '         sql_run:'.$oSlave->sql_run.LF;
	echo '           error:'.$oSlave->errorNum.LF;
	echo '             msg:'.$oSlave->errorMsg.LF;
	
	if ($oSlave->errorNum==0)
	{
		echo '** No need to re-sync.  No error reported'.LF;
		exit(0);
	}
	
	if (!$oMaster->ReadLock())
	{
		if (!$oMaster->status)
			echo 'Master error bit set'.LF;
		echo 'Error in command['.$oMaster->cSql.']'.LF;
		echo $oMaster->cStatusMsg.LF;
		exit(1);
	}
	echo HOST_IP.' * flushed tables with read lock:OK'.LF;
	if (!checkProcess($oMaster->masterstatus(),$oMaster))
		exit(1);
	
	// we have the master pos and log.  Set this in slave
	if (!checkProcess($oSlave->StopSlave(),$oSlave))
		exit(1);
	// set slave replication
	$oSlave->file=$oMaster->cFile;
	$oSlave->position=$oMaster->nPosition;
	$oSlave->cReplicationHost=HOST_IP;
	$oSlave->cReplicationUser=REPLICATION_USER;
	$oSlave->cReplicationPwd=REPLICATION_PWD;
	$oSlave->SetReplicationHost();
	echo HOST_IP.' * Set Replication Host:OK'.LF;
	if (!checkProcess($oSlave->StartSlave(),$oSlave))
		exit(1);
	echo HOST_IP.' * Start Slave:OK'.LF;
    
	
function checkProcess($xProcess,$oProc)
{
	if (!$xProcess)
	{
		if (!$oProc->status)
			echo 'Error bit set'.LF;
		echo 'Error in ['.$oProc->cSql.']'.LF;
		echo $oProc->cStatusMsg.LF;
		return(false);
	}
	return(true);
}

function CheckResult($sql,$result)
{
	if (!$result)
	{
		echo 'Error in sql['.$sql.']:'.mysql_error().LF;	
		exit(1);
	}
	if (mysql_num_rows($result)<=0)
	{
		echo 'No rows in sql['.$sql.']'.LF;	
		exit(1);
	}
	
}


// simple class for the replication master
class oMaster
{
	var $cFile;
	var $nPosition;
	var $nStatus;
	var $cDoDB;
	var $cDontDB;
	var $oHost;
	var $cName;
	var $oResult;
	var $oMasterResult;
	var $status;
	var $cStatusMsg;
	var $cSql;
	
	function ReadLock()
	{
		if(!$this->DoQuery('flush tables with read lock'))
			return(false);
		return(true);
	}
	
	
	function oMaster($oHost,$Name)
	{
		$this->oHost=$oHost;
		$this->cName-$Name;
		$this->status=true;
		$this->cStatusMsg='';
	}
	function SetError($cMsg)
	{
		$this->status=false;
		$this->cStatusMsg=$cMsg;
	}
	
	function CheckResult()
	{
		if (!$this->oResult)
		{
			$this->SetError('Error in sql['.$this->cSql.']:'.mysql_error());
			return(false);
		}
		return(true);
	}
	

	function DoQuery($cSql)	
	{
		$this->cSql=$cSql;
		$this->oResult=mysql_query($this->cSql,$this->oHost);
		return($this->CheckResult());
	}
	
		
	function masterstatus()
	{
		if (!$this->DoQuery('show master status'))
			return(false);
		$this->oMasterResult=$this->oResult;
		if (mysql_num_rows($this->oMasterResult)==0)
		{
			$this->SetError('No master on '.$this->cName);
			return(false);
		}
		$aRow=mysql_fetch_array($this->oMasterResult);
		$this->cFile=$aRow['File'];
		$this->nPosition=$aRow['Position'];
		$this->cDoDB=$aRow['Binlog_Do_DB'];
		$this->cDontDB=$aRow['Binlog_Ignore_DB'];
		return(true);
	}
	
}

// simple class for the replication slave (this machine)
class oSlave
{
	var $file;
	var $position;
	var $sql_run;
	var $io_run;
	var $errorNum;
	var $errorMsg;
	var $oHost;
	var $cName;
	var $oResult;
	var $oSlaveResult;
	var $oTimeResult;
	var $time;
	var $status;
	var $cStatusMsg;
	var $cSql;
	
	var $cReplicationHost;
	var $cReplicationUser;
	var $cReplicationPwd;
	
	
	function StopSlave()
	{
		if(!$this->DoQuery('stop slave'))
			return(false);
		return(true);
	}
	function StartSlave()
	{
		if(!$this->DoQuery('start slave'))
			return(false);
		return(true);
	}
	
	function SetReplicationHost()
	{
		$this->cSql='change master to master_host = "'.$this->cReplicationHost.'", master_user = "'.$this->cReplicationUser.'", master_password = "'.$this->cReplicationPwd.'", master_log_file = "'.$this->file.'", master_log_pos = "'.$this->position;
		if(!$this->DoQuery($this->cSql))
			return(false);
		return(true);
	}
	
	function oSlave($oHost,$Name)
	{
		$this->oHost=$oHost;
		$this->cName-$Name;
		$this->status=true;
		$this->cStatusMsg='';
	}
	function SetError($cMsg)
	{
		$this->status=false;
		$this->cStatusMsg=$cMsg;
	}
	
	function CheckResult()
	{
		if (!$this->oResult)
		{
			$this->SetError('Error in sql['.$this->cSql.']:'.mysql_error());
			return(false);
		}
		return(true);
	}
	

	function DoQuery($cSql)	
	{
		$this->cSql=$cSql;
		$this->oResult=mysql_query($this->cSql,$this->oHost);
		return($this->CheckResult());
	}
	
	function slavestatus()
	{
		if (!$this->DoQuery('show slave status'))
			return(false);
		$this->oSlaveResult=$this->oResult;
		if (mysql_num_rows($this->oSlaveResult)==0)
		{
			$this->SetError('No slave on '.$this->cName);
			return(false);
		}
		
		if (!$this->DoQuery('select now()'))
			return(false);
		$this->oTimeResult=$this->oResult;	
		
		$this->cSql='mysql_result()';
		$this->time=mysql_result($this->oTimeResult,0,0);
		
		$status=null;
		while($status = mysql_fetch_array($this->oSlaveResult))
		{
			$this->file=$status[5];
			$this->position=$status[6];
			$this->sql_run=$status[10];
			$this->io_run=$status[11];
			$this->errorNum=$status[18];
			$this->errorMsg=$status[19];
		}
		return(true);
	}
	
}
