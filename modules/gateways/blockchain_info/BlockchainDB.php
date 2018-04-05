<?php

/**
 * Created by IntelliJ IDEA.
 * User: roman
 * Date: 9/1/17
 * Time: 3:59 PM
 */
class BlockchainDB
{
    public static $FETCH_ASSOC = 'assoc';
    public static $FETCH_ARRAY = 'array';
    private $db_host;
    private $db_username;
    private $db_password;
    private $db_name;

    function __construct()
    {
        include __DIR__."/../../../configuration.php";
        global $db_host,$db_username,$db_password,$db_name;
        $this->db_host = $db_host;
        $this->db_username = $db_username;
        $this->db_password = $db_password;
        $this->db_name = $db_name;
        $this->db_link = mysqli_connect($this->db_host,$this->db_username,$this->db_password,$this->db_name);
    }

    public function mysqlQuery($query)
    {
        $argcount = func_num_args();
        if($argcount > 1){
            $args = func_get_args();
            unset($args[0]);
            for ($i = 1; $i <= $argcount - 1; $i++) {
                $args[$i] = $args[$i]=='NULL'?'NULL':$this->quote_smart($args[$i]);
            }
            $query = vsprintf($query,$args);
        }
        $result=mysqli_query($this->db_link,$query);
        $err=mysqli_errno($this->db_link);

        if($err === 2006 || $err === 2013){
            //RECONNECT TO THE MYSQL DB
            $this->db_link=mysqli_connect($this->db_host,$this->db_username,$this->db_password,$this->db_name);
            return $this->mysqlQuery($query);
        }

        return $result;
    }

    public function fetch_assoc($result)
    {
        return mysqli_fetch_assoc($result);
    }






    private function quote_smart($value)
    {
        // Stripslashes
        if (get_magic_quotes_gpc()){
            $value = stripslashes($value);
        }
        // Quote if not a number or a numeric string
        if (!is_numeric($value)){
            $value = "'" . mysqli_real_escape_string($this->db_link,$value) . "'";
        }
        return $value;
    }



}