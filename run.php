<?php


//Includes the bot's core
include("turtler.php");

//Creates the bot object, with bot's name and logfile's name as parameters
$turtler = new Turtler("turtteli2", "turtteli.log");

/*
 * Example on how to add own functions and services:
 * $turtler->addFunction("function_name");
 * $turtler->addService("function_name", 1);
 * Look for readme.txt for more information.
*/

//connects the bot to a server on specific port specified by parameters, and 
//attempts to join the bot to a channel
$turtler->connect("irc.quakenet.org", 6667, "#turtler");



?>