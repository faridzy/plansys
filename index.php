<?php

## WARNING: THIS FILE IS GENERATED BY PLANSYS
## DO NOT CHANGE.
$mode = "init";

## Define Root Dir
$root = $mode != "init" ? dirname($_SERVER["SCRIPT_FILENAME"]) . '/plansys' : dirname($_SERVER["SCRIPT_FILENAME"]);

## Define core lib path
$yii      = $root . '/framework/yii.php';
$config   = $root . '/config/main.php';
$setting  = $root . '/components/utility/Setting.php';
if (!file_exists($root . '/vendor/autoload.php')) {
    echo "
    <center>
        <b>Composer failed to load!</b><br/>
        Please run <code>'composer update'</code> on plansys directory
    </center>";
    die();
}
$composer = require ($root . '/vendor/autoload.php');
if (is_file($root . '/../app/vendor/autoload.php')) {
    $composerApp = require ($root . '/../app/vendor/autoload.php');
}

## Initialize settings
require_once ($setting);
Setting::init($config, $mode, $_SERVER["SCRIPT_FILENAME"]);


## Initialize Yii
require_once ($yii);
Yii::createWebApplication($config)->run();