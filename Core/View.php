<?php

  class View
  {

    public static function getTemplate($name, $parameters)
    {
      extract($parameters);

      ob_start();
      include __DIR__.'/../Templates/'.$name.'.php';
      $contenido = ob_get_contents();
      
      ob_start();
      include __DIR__.'/../TemplateBase.php';
      $page = ob_get_contents();

      return $page;
    }
  }
