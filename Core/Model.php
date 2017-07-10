<?php


class Model
{
  private $mysqli;
  private $result;
  private static $model = NULL;
  private static $isLoanedOut = FALSE;

  protected function __construct()
  {
    $this->mysqli = new mysqli(HOST, USER, PASSWORD, DATABASE, PORT);
    if ($this->mysqli->connect_error) {
        die('Error de Conexión (' . $mysqli->connect_errno . ') '
                . $mysqli->connect_error);
    }
  }

  private function __clone(){}
  private function __wakeup(){}

  public static function getModel()
  {
    if (self::$model == null)
    {
      self::$model = new Model();
    }

    return self::$model;
  }

  public function setQuery($query)
  {
    $this->result = $this->mysqli->query($query);
  }

  public function getResult()
  {
    $result = $this->result;
    while ($row = $result->fetch_object()){
        $resultArray[] = $row;
    }
    $result->close();
    $this->result = NULL;
    return $resultArray;
  }
}
