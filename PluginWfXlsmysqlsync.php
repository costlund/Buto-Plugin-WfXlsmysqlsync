<?php
/**
<p>Sync data between xls and mysql.</p>
*/
class PluginWfXlsmysqlsync{
  private $settings = null;
  function __construct($buto = false){
    if($buto){
      if(!wfUser::hasRole("webmaster")){
        exit('Role webmaster is required!');
      }
      wfArray::set($GLOBALS, 'sys/layout_path', '/plugin/wf/xlsmysqlsync/layout');
      wfPlugin::includeonce('wf/array');
      $this->settings = new PluginWfArray(wfArray::get($GLOBALS, 'sys/settings/plugin_modules/'.wfArray::get($GLOBALS, 'sys/class').'/settings'));
      /**
       * Handle mysql param if string to yml file.
       */
      $this->settings->set('mysql', wfSettings::getSettingsFromYmlString($this->settings->get('mysql')));
    }
  }
  /**
   <p>Start page.</p>
   */
  public function page_start(){
    $desktop = $this->getYml('page/desktop.yml');
    $desktop->setById('btn', 'attribute/data-class', wfArray::get($GLOBALS, 'sys/class'));
    wfDocument::mergeLayout($desktop->get());
  }
  public function page_run(){
    /**
     * Exceed time.
     */
    set_time_limit(60*5);
    ini_set("memory_limit",-1);
    /**
     * Convert to array.
     */
    $xls = $this->xlsToArray(wfRequest::get('xls'));
    if(sizeof($xls)<3){
      exit('Less then 3 rows...');
    }
    /**
     * Table name.
     */
    $table_name = $this->getTableName($xls);
    if(!$table_name){
      exit('Could not find table name...');
    }
    $show_columns = $this->runSQL("show columns from $table_name;", 'Field');
    /**
     * Columns exist?
     */
    if($this->fieldExist($xls, $show_columns)!==true){
      exit($this->fieldExist($xls, $show_columns));
    }
    $sql = $this->createSql($xls, $show_columns, $table_name);
    $s = null;
    foreach ($sql as $key => $value) {
      $s .= "$value<br>";
    }
    echo $s;
    exit;
  }
  private function getKeyPri($xls, $show_columns){
    $key_pri = array();
    foreach ($xls[1] as $key => $value){
      if($show_columns->get("$value/Key")=='PRI'){
        $key_pri[$key] = $value;
      }
    }
    return $key_pri;
  }
  private function getKeyNotPri($xls, $show_columns){
    $key_not_pri = array();
    foreach ($xls[1] as $key => $value){
      if($show_columns->get("$value/Key")!='PRI'){
        $key_not_pri[$key] = $value;
      }
    }
    return $key_not_pri;
  }
  private function createSql($xls, $show_columns, $table_name){
    /**
     * Key PRI.
     */
    $key_pri = $this->getKeyPri($xls, $show_columns);
    /**
     * Key not PRI.
     */
    $key_not_pri = $this->getKeyNotPri($xls, $show_columns);
    /**
     * Check SQL.
     */
    $s = "select ";
    foreach ($key_pri as $key => $value){
      $s .= "$value, ";
    }
    $s = substr($s, 0, strlen($s)-2);
    $s .= " from $table_name where ";
    foreach ($key_pri as $key => $value){
      $s .= "$value='?$value?' and ";
    }
    $s = substr($s, 0, strlen($s)-5);
    $s .= " limit 1;";
    $sql_check = $s;
    /**
     * Update SQL.
     */
    $s = "update $table_name set ";
    foreach ($key_not_pri as $key => $value){
      $s .= "$value='?$value?', ";
    }
    $s = substr($s, 0, strlen($s)-2);
    $s .= " where ";
    foreach ($key_pri as $key => $value){
      $s .= "$value='?$value?' and ";
    }
    $s = substr($s, 0, strlen($s)-5);
    $s .= ";";
    $sql_update = $s;
    /**
     * Insert SQL.
     */
    $s = "insert into $table_name (";
    foreach ($key_pri as $key => $value){
      $s .= "$value, ";
    }
    foreach ($key_not_pri as $key => $value){
      $s .= "$value, ";
    }
    $s = substr($s, 0, strlen($s)-2);
    $s .= ") values (";
    foreach ($key_pri as $key => $value){
      $s .= "'?$value?', ";
    }
    foreach ($key_not_pri as $key => $value){
      $s .= "'?$value?', ";
    }
    $s = substr($s, 0, strlen($s)-2);
    $s .= ");";
    $sql_insert = $s;
    /**
     * Loop rows.
     */
    $sql = array();
    //wfHelp::yml_dump($xls, true);
    foreach ($xls as $key => $value) {
      if($key < 2){continue;}
      /**
       * Check.
       */
      $s = $sql_check;
      foreach ($key_pri as $key2 => $value2){
        $s = str_replace("?$value2?", $value[$key2], $s);
      }
      $rs = $this->runSQL($s);
      if(sizeof($rs->get())==0){
        /**
         * Insert.
         */
        $s = $sql_insert;
        foreach ($key_pri as $key2 => $value2){
          $s = str_replace("?$value2?", $value[$key2], $s);
        }
        foreach ($key_not_pri as $key2 => $value2){
          $s = str_replace("?$value2?", $value[$key2], $s);
        }
        $sql[] = $s;
        $this->runSQL($s);
      }else{
        /**
         * Update.
         */
        $s = $sql_update;
        foreach ($key_pri as $key2 => $value2){
          $s = str_replace("?$value2?", $value[$key2], $s);
        }
        foreach ($key_not_pri as $key2 => $value2){
          if(isset($value[$key2])){
            $s = str_replace("?$value2?", $value[$key2], $s);
          }else{
            // Last rows last column does not exist if it is empty...
            $s = str_replace("?$value2?", '', $s);
          }
        }
        $sql[] = $s;
        $this->runSQL($s);
      }
    }
    return $sql;
  }
  /**
   * Check if field exist.
   * @param type $xls
   * @param type $show_columns
   * @return boolean
   */
  private function fieldExist($xls, $show_columns){
    foreach ($xls[1] as $key => $value) {
      if(!$show_columns->get($value)){
        return "Column $value does not exist!";
      }
    }
    return true;
  }
  private function getTableName($xls){
    $name = null;
    foreach ($xls[0] as $key => $value){
      if($key==0){
        $name = $value;
      }else{
        if(strlen($value)){
          /**
           * Name of table should only be in first column. If else there is an error.
           */
          $name = null;
          break;
        }
      }
    }
    return $name;
  }
  private function xlsToArray($xls){
    $row = preg_split('/\n|\r\n?/', $xls);
    $data = array();
    foreach ($row as $key => $value) {
      $data[] = preg_split("/[\t]/", $value);
    }
    return $data;
  }
  /**
   * Get yml.
   * Example $this->getYml('/page/desktop.yml');
   */
  private function getYml($file){
    wfPlugin::includeonce('wf/yml');
    return new PluginWfYml('/plugin/wf/xlsmysqlsync/'.$file);
  }
  /**
   <p>Method to run sql.</p>
   */
  private function runSQL($sql, $key_field = 'id'){
    wfPlugin::includeonce('wf/mysql');
    $mysql = new PluginWfMysql();
    $mysql->open($this->settings->get('mysql'));
    $test = $mysql->runSql($sql, $key_field);
    return new PluginWfArray($test['data']);
  }
}
